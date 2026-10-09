<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Services;

use App\Core\App;

use App\Configuration\AppConfig;
use App\Configuration\Config;

use App\Models\MailLog;
use App\Models\MailLogFuzzy;
use App\Models\MailLogData;

use Psr\Log\LoggerInterface;

use Illuminate\Pagination\LengthAwarePaginator;

use Illuminate\Database\Eloquent\Builder;

use Symfony\Component\HttpFoundation\Response;

use App\Core\Exception\FuzzyError;
use App\Core\Exception\FuzzyException;

use Throwable;

/*
 * Fuzzy learning of quarantined mail into the rspamd fuzzy storage.
 *
 * learnLocal()/unlearnLocal() run on the node that stores the mail and talk
 * to the rspamd controller in the mail's API_SERVERS entry ('fuzzy_url').
 * Callers on other nodes go through the owning node's API.
 */
class FuzzyService
{
	// rspamd returns digests as 64 bytes, hex encoded
	private const string HASH_FORMAT = '/^[a-f0-9]{128}$/';

	private const float TIMEOUT = 10.0;

	private const int DEFAULT_WEIGHT = 10;

	private LoggerInterface $logger;
	private LoggerInterface $syslogLogger;

	public function __construct() {
		$this->logger = App::fileLogger();
		$this->syslogLogger = App::syslogLogger();
	}

	public static function isEnabled(): bool {
		return (bool) Config::get('fuzzy_learn_enable')
			&& App::migrationStatus()->mailLogFuzzyCompleted();
	}

	// whether hits and last_hit_at exist to be shown
	public static function hitsEnabled(): bool {
		return App::migrationStatus()->mailLogFuzzyHitsCompleted();
	}

	// whether mail stored on $server can be learned
	public static function serverEnabled(?string $server): bool {
		$api_servers = Config::get('API_SERVERS') ?? [];

		return $server !== null
			&& !empty($api_servers[$server]['fuzzy_url']);
	}

	/*
	 * The fuzzy lists of $fuzzy_learn_flags, keyed by flag:
	 * [flag => ['label' => ..., 'symbol' => ..., 'weight' => ...]].
	 * Invalid entries are logged and left out.
	 */
	public static function flags(): array {
		$flags = [];

		foreach ((array) (Config::get('fuzzy_learn_flags') ?? []) as $flag => $entry) {
			$valid = is_int($flag) && $flag >= 1 && $flag <= 255 && is_array($entry)
				&& trim((string) ($entry['label'] ?? '')) !== ''
				&& is_int($entry['weight'] ?? self::DEFAULT_WEIGHT)
				&& ($entry['weight'] ?? self::DEFAULT_WEIGHT) >= 1
				&& ($entry['weight'] ?? self::DEFAULT_WEIGHT) <= 65535;

			if (!$valid) {
				App::fileLogger()->error("[FuzzyService] invalid fuzzy_learn_flags entry " .
					json_encode([$flag => $entry]) . " in config: flag 1-255, label required, weight 1-65535");
				continue;
			}

			$flags[$flag] = [
				'label' => trim((string) $entry['label']),
				'symbol' => trim((string) ($entry['symbol'] ?? '')),
				'weight' => $entry['weight'] ?? self::DEFAULT_WEIGHT,
			];
		}

		return $flags;
	}

	// list labels by rspamd symbol name (upper case), for showing hits in listings
	public static function symbolLabels(): array {
		$labels = [];
		foreach (self::flags() as $entry) {
			if ($entry['symbol'] !== '') {
				$labels[strtoupper($entry['symbol'])] = $entry['label'];
			}
		}
		return $labels;
	}

	/*
	 * Learned records the stored mail $mailLogId hit on arrival: those holding
	 * a digest from its fuzzy_hashes. As [['id', 'mail_log_id', 'qid', 'flag']].
	 */
	public function matchedBy(int $mailLogId): array {
		$hashes = MailLogData::where('mail_log_id', $mailLogId)->value('fuzzy_hashes');
		if (is_string($hashes)) {
			$hashes = json_decode($hashes, true);
		}
		$hashes = array_values(array_unique(preg_grep(self::HASH_FORMAT,
			array_filter((array) $hashes, 'is_string'))));
		if ($hashes === []) {
			return [];
		}

		$query = MailLogFuzzy::query()
			->where(function ($q) use ($hashes) {
				foreach ($hashes as $hash) {
					$q->orWhereRaw('JSON_CONTAINS(hashes, JSON_QUOTE(?))', [$hash]);
				}
			})
			// not its own learn
			->where(fn ($q) => $q->whereNull('mail_log_id')->orWhere('mail_log_id', '<>', $mailLogId));

		return $query->orderBy('id')->get(['id', 'mail_log_id', 'qid', 'flag'])
			->map(fn ($r) => [
				'id' => (int) $r->id,
				'mail_log_id' => $r->mail_log_id,
				'qid' => (string) $r->qid,
				'flag' => (int) $r->flag,
			])->all();
	}

	public function getByMailLogId(int $mailLogId): ?MailLogFuzzy {
		return MailLogFuzzy::where('mail_log_id', $mailLogId)->first();
	}

	// records unchanged for $days: no learn, hit or added weight, so rspamd has let their hashes expire
	public function getExpired(int $days, ?string $server = null): Builder {
		$query = MailLogFuzzy::whereRaw('updated_at < NOW() - INTERVAL ? DAY', [$days]);
		if ($server !== null) {
			$query->where('api_server', $server);
		}
		return $query->orderBy('id');
	}

	// deletes the records only: their hashes are already gone from rspamd
	public function purgeExpired(Builder $query): int {
		$deleted = 0;

		foreach ((clone $query)->get() as $row) {
			$since = "unchanged since {$row->updated_at}, " .
				($row->last_hit_at ? "last hit {$row->last_hit_at}" : "no hits");
			$row->delete();
			$deleted++;
			$this->logger->info("{$row->qid} fuzzy record purged ({$since}, server {$row->api_server})");
		}

		return $deleted;
	}

	/*
	 * Newest first: $search in the QID, learned by or server, or a hash prefix.
	 * Also the records sharing a digest with a QID match (its "Shared with").
	 */
	public function searchLearnedPaginated(string $search, string $url, int $page, int $perPage): LengthAwarePaginator {
		$like = '%' . addcslashes($search, '%_\\') . '%';
		$table = AppConfig::MAIL_LOG_FUZZY_TABLE;

		return MailLogFuzzy::where(function ($q) use ($search, $like, $table) {
				$q->where('qid', 'like', $like)
					->orWhere('learned_by', 'like', $like)
					->orWhere('api_server', 'like', $like)
					->orWhereIn('id', function ($sub) use ($like, $table) {
						$sub->selectRaw('o.id')
							->fromRaw("{$table} o, JSON_TABLE(o.hashes, '$[*]' COLUMNS (h VARCHAR(128) PATH '$')) oj, " .
								"{$table} m, JSON_TABLE(m.hashes, '$[*]' COLUMNS (h VARCHAR(128) PATH '$')) mj")
							->whereRaw('m.qid LIKE ? AND mj.h = oj.h', [$like]);
					});
				// hashes are 128 hex characters; symbol options show a 10 character prefix
				if (preg_match('/^[a-f0-9]{8,128}$/i', $search)) {
					$q->orWhere('hashes', 'like', '%"' . strtolower($search) . '%');
				}
			})
			->orderByDesc('id')
			->paginate($perPage, ['*'], 'page', $page)
			->withPath($url)
			->appends(['q' => $search]);
	}

	// newest first; rows outlive their mail_logs row
	public function getLearnedPaginated(string $url, int $page, int $perPage): LengthAwarePaginator {
		return MailLogFuzzy::orderByDesc('id')
			->paginate($perPage, ['*'], 'page', $page)
			->withPath($url);
	}

	// learn on this node, or through the API of the node that stores the mail
	public function learn(MailLog $maillog, int $flag, string $learnedBy, ?int $weight = null): void {
		$server = (string) $maillog->server;

		$this->flagEntry($flag, (string) $maillog->qid);
		$this->checkWeight($weight);

		if (!self::serverEnabled($server)) {
			$this->logger->error("[FuzzyService_learn] {$maillog->qid} no fuzzy_url for API server '{$server}'. Check config.local.php");
			throw new FuzzyException("Fuzzy learning is not available for server '{$server}'", FuzzyError::Unavailable);
		}

		if ($server === ($_ENV['MY_API_SERVER_ALIAS'] ?? '')) {
			$this->learnLocal($maillog, $flag, $learnedBy, $weight);
			return;
		}

		$this->callApi($server, 'learn', (int) $maillog->id, $learnedBy, (string) $maillog->qid, $flag, $weight);
	}

	// unlearn by the same route learn() took: the mail's server
	public function unlearn(MailLogFuzzy $row, string $unlearnedBy): void {
		$server = (string) $row->api_server;

		if ($server === ($_ENV['MY_API_SERVER_ALIAS'] ?? '')) {
			$this->unlearnLocal($row, $unlearnedBy);
			return;
		}

		$this->callApi($server, 'unlearn', (int) $row->id, $unlearnedBy, (string) $row->qid);
	}


	// add weight by the same route learn() took: the mail's server
	public function addWeight(MailLogFuzzy $row, int $weight, string $addedBy): void {
		$server = (string) $row->api_server;

		$this->checkWeight($weight);

		if (!self::serverEnabled($server)) {
			$this->logger->error("[FuzzyService_addWeight] {$row->qid} no fuzzy_url for API server '{$server}'. Check config.local.php");
			throw new FuzzyException("Fuzzy learning is not available for server '{$server}'", FuzzyError::Unavailable);
		}

		if ($server === ($_ENV['MY_API_SERVER_ALIAS'] ?? '')) {
			$this->addWeightLocal($row, $weight, $addedBy);
			return;
		}

		$this->callApi($server, 'boost', (int) $row->id, $addedBy, (string) $row->qid, null, $weight);
	}

	// $weight overrides the list's weight from config
	public function learnLocal(MailLog $maillog, int $flag, string $learnedBy, ?int $weight = null): MailLogFuzzy {
		$lf = "[FuzzyService_learnLocal]";
		$qid = (string) $maillog->qid;

		if (!$maillog->mail_stored) {
			throw new FuzzyException("Mail {$qid} is not stored", FuzzyError::Conflict);
		}

		if ($this->getByMailLogId((int) $maillog->id) !== null) {
			throw new FuzzyException("Mail {$qid} is already learned", FuzzyError::Conflict);
		}

		$entry = $this->flagEntry($flag, $qid);
		$this->checkWeight($weight);
		$weight ??= $entry['weight'];

		$hashes = $this->fuzzyAdd($maillog, $flag, $weight, $lf);

		try {
			// retries deadlocks, lock waits and Galera conflicts, not duplicate keys
			$row = App::capsule()
				->connection()
				->transaction(
					function () use ($maillog, $flag, $weight, $hashes, $learnedBy) {
						return MailLogFuzzy::create([
							'mail_log_id' => (int) $maillog->id,
							'qid' => $maillog->qid,
							// the mail's server, so unlearn() routes the same way learn() did
							'api_server' => (string) $maillog->server,
							'flag' => $flag,
							'weight' => $weight,
							'hashes' => array_values($hashes),
							'learned_by' => $learnedBy,
						]);
					},
					attempts: AppConfig::MAX_DEADLOCK_ATTEMPTS
				);
		} catch (Throwable $e) {
			$this->logger->error("{$lf} {$qid} learned but not recorded: " . $e->getMessage());
			$concurrent = null;
			try {
				// a concurrent learn of the same mail recorded these hashes: keep them
				$concurrent = $this->getByMailLogId((int) $maillog->id) !== null;
				if (!$concurrent) {
					// rspamd deletes the whole key: keep digests other learned mails have
					$delete = array_values(array_diff($hashes, $this->hashesHeldByOthers($hashes)));
					$this->logger->error("{$lf} {$qid} rolling back unrecorded hashes: " .
						($delete === [] ? 'none' : implode(',', $delete)) . " (" . (count($hashes) - count($delete)) . " kept, shared)");
					if ($delete !== []) {
						$this->deleteHashes((string) $maillog->server, $delete, $flag, $qid);
					}
				}
			} catch (Throwable $e2) {
				$this->logger->critical("{$lf} {$qid} learned but neither recorded nor rolled back, hashes " .
					implode(',', $hashes) . ": " . $e2->getMessage());
				throw new FuzzyException("Mail {$qid} was learned but not recorded; see the log", FuzzyError::Internal);
			}
			if ($concurrent) {
				throw new FuzzyException("Mail {$qid} is already learned", FuzzyError::Conflict);
			}
			throw new FuzzyException("Mail {$qid} could not be learned (database error)", FuzzyError::Internal);
		}

		$msg = "{$qid} learned as fuzzy '{$entry['label']}' (flag {$flag}, weight {$weight}, " .
			count($hashes) . " hashes) by '{$learnedBy}'";
		$this->logger->info($msg);
		$this->syslogLogger->info($msg);

		return $row;
	}

	// digests of $row that other learned mails also have
	public function sharedHashes(MailLogFuzzy $row): array {
		return $this->hashesHeldByOthers((array) $row->hashes, (int) $row->getKey());
	}

	// per row id: the other learned mails sharing any of its hashes, oldest first
	public function sharedWith(iterable $rows): array {
		$hashes = [];
		foreach ($rows as $row) {
			foreach ((array) $row->hashes as $hash) {
				$hashes[(string) $hash] = true;
			}
		}
		if ($hashes === []) {
			return [];
		}

		$table = AppConfig::MAIL_LOG_FUZZY_TABLE;
		$found = App::capsule()->getConnection()->select(
			"SELECT f.id, f.qid, f.mail_log_id, f.flag, j.h FROM {$table} f, " .
			"JSON_TABLE(f.hashes, '$[*]' COLUMNS (h VARCHAR(128) PATH '$')) j " .
			"WHERE j.h IN (" . implode(',', array_fill(0, count($hashes), '?')) . ")",
			array_keys($hashes)
		);

		$holders = [];
		foreach ($found as $r) {
			$holders[$r->h][(int) $r->id] = [
				'id' => (int) $r->id,
				'qid' => $r->qid,
				'mail_log_id' => $r->mail_log_id === null ? null : (int) $r->mail_log_id,
				'flag' => (int) $r->flag,
			];
		}

		$shared = [];
		foreach ($rows as $row) {
			$others = [];
			foreach ((array) $row->hashes as $hash) {
				$others += $holders[(string) $hash] ?? [];
			}
			unset($others[(int) $row->getKey()]);
			if ($others !== []) {
				ksort($others);
				$shared[(int) $row->getKey()] = array_values($others);
			}
		}
		return $shared;
	}

	/*
	 * Count a hit on every learned mail holding one of $hashes, once per
	 * incoming mail. $at is the incoming mail's created_at (null: now).
	 * Never throws: a failure here must not cost the import.
	 */
	public function recordHits(array $hashes, ?string $at, string $qid): void {
		$hashes = array_values(array_unique(preg_grep(self::HASH_FORMAT,
			array_filter($hashes, 'is_string'))));
		if ($hashes === []) {
			return;
		}

		try {
			if (!App::migrationStatus()->mailLogFuzzyHitsCompleted()) {
				return;
			}

			$query = MailLogFuzzy::query();
			foreach ($hashes as $hash) {
				$query->orWhereRaw('JSON_CONTAINS(hashes, JSON_QUOTE(?))', [$hash]);
			}
			// read first: no write when only other storages matched
			$rows = $query->get(['id', 'qid']);
			if ($rows->isEmpty()) {
				return;
			}

			// never moves last_hit_at backwards, e.g. on a spool replay
			$ids = $rows->pluck('id')->all();
			App::capsule()->getConnection()->update(
				"UPDATE " . AppConfig::MAIL_LOG_FUZZY_TABLE . " SET hits = hits + 1, " .
				"last_hit_at = GREATEST(COALESCE(last_hit_at, COALESCE(?, NOW())), COALESCE(?, NOW())) " .
				"WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")",
				array_merge([$at, $at], $ids)
			);

			$msg = "{$qid} fuzzy hit on learned mail(s) " . $rows->pluck('qid')->implode(', ');
			$this->logger->info($msg);
			$this->syslogLogger->info($msg);
		} catch (Throwable $e) {
			$this->logger->error("[FuzzyService_recordHits] {$qid} could not record fuzzy hits: " . $e->getMessage());
		}
	}

	// the subset of $hashes held by learned mails other than $exceptId
	private function hashesHeldByOthers(array $hashes, ?int $exceptId = null): array {
		return array_values(array_filter($hashes, function ($hash) use ($exceptId): bool {
			$query = MailLogFuzzy::whereRaw('JSON_CONTAINS(hashes, JSON_QUOTE(?))', [(string) $hash]);
			if ($exceptId !== null) {
				$query->whereKeyNot($exceptId);
			}
			return $query->exists();
		}));
	}

	/*
	 * Holders of $row's digests when none of them is in $row's list: rspamd
	 * keeps such a digest on unlearn, and with it $row's list slot, which only
	 * deleting the whole digest removes. As [['qid', 'flag']], empty if none.
	 */
	public function orphanedSlotHolders(MailLogFuzzy $row): array {
		$hashes = array_values(array_unique(array_filter((array) $row->hashes, 'is_string')));
		if ($hashes === []) {
			return [];
		}

		$table = AppConfig::MAIL_LOG_FUZZY_TABLE;
		$found = App::capsule()->getConnection()->select(
			"SELECT f.qid, f.flag, j.h FROM {$table} f, " .
			"JSON_TABLE(f.hashes, '$[*]' COLUMNS (h VARCHAR(128) PATH '$')) j " .
			"WHERE f.id <> ? AND j.h IN (" . implode(',', array_fill(0, count($hashes), '?')) . ")",
			array_merge([(int) $row->getKey()], $hashes)
		);

		$byHash = [];
		foreach ($found as $r) {
			$byHash[$r->h][] = ['qid' => (string) $r->qid, 'flag' => (int) $r->flag];
		}

		$holders = [];
		foreach ($byHash as $list) {
			// another holder in the same list keeps the slot legitimately
			if (!in_array((int) $row->flag, array_column($list, 'flag'), true)) {
				foreach ($list as $h) {
					$holders[$h['qid']] = $h;
				}
			}
		}
		return array_values($holders);
	}

	public function unlearnLocal(MailLogFuzzy $row, string $unlearnedBy): void {
		$qid = (string) $row->qid;

		// a shared digest stays in rspamd until its last learned mail is unlearned
		$shared = $this->sharedHashes($row);
		$orphaned = $this->orphanedSlotHolders($row);
		$delete = array_values(array_diff((array) $row->hashes, $shared));

		if ($delete !== []) {
			$this->deleteHashes((string) $row->api_server, $delete, (int) $row->flag, $qid);
		}
		$row->delete();

		$label = self::flags()[(int) $row->flag]['label'] ?? "flag {$row->flag}";
		$kept = $shared !== [] ? ", kept " . count($shared) . " hash(es) shared with other learned mails" : '';
		$msg = "{$qid} unlearned from fuzzy '{$label}' (flag {$row->flag}, weight {$row->weight}){$kept} by '{$unlearnedBy}'";
		$this->logger->info($msg);
		$this->syslogLogger->info($msg);

		if ($orphaned !== []) {
			$this->logger->warning("{$qid} fuzzy '{$label}' slot stays active in rspamd: its hash is also learned in another list by " .
				implode(', ', array_column($orphaned, 'qid')));
		}
	}


	// re-add a learned mail with extra weight: rspamd adds to it, nothing is deleted
	public function addWeightLocal(MailLogFuzzy $row, int $weight, string $addedBy): void {
		$lf = "[FuzzyService_addWeightLocal]";
		$qid = (string) $row->qid;

		$this->checkWeight($weight);
		if ((int) $row->weight + $weight > 65535) {
			throw new FuzzyException("Mail {$qid}: total weight would exceed 65535", FuzzyError::Conflict);
		}

		$maillog = $row->mailLog;
		if ($maillog === null || !$maillog->mail_stored) {
			throw new FuzzyException("Mail {$qid} is not stored", FuzzyError::Conflict);
		}

		$entry = $this->flagEntry((int) $row->flag, $qid);
		$hashes = $this->fuzzyAdd($maillog, (int) $row->flag, $weight, $lf);
		$merged = array_values(array_unique(array_merge((array) $row->hashes, $hashes)));

		// rspamd cannot subtract: once added, this weight is kept even if recording fails
		try {
			$updated = App::capsule()
				->connection()
				->transaction(
					function () use ($row, $weight, $merged) {
						return MailLogFuzzy::whereKey($row->getKey())
							->increment('weight', $weight, ['hashes' => json_encode($merged)]);
					},
					attempts: AppConfig::MAX_DEADLOCK_ATTEMPTS
				);
		} catch (Throwable $e) {
			$this->logger->critical("{$lf} {$qid} weight {$weight} added in rspamd but not recorded: " . $e->getMessage());
			throw new FuzzyException("Mail {$qid}: weight added in rspamd but not recorded; see the log", FuzzyError::Internal);
		}

		if ($updated === 0) {
			$this->logger->critical("{$lf} {$qid} weight {$weight} added in rspamd but the fuzzy record is gone, hashes " .
				implode(',', $hashes));
			throw new FuzzyException("Mail {$qid} was unlearned meanwhile; see the log", FuzzyError::Conflict);
		}

		$msg = "{$qid} fuzzy '{$entry['label']}' (flag {$row->flag}) weight +{$weight}, now " .
			((int) $row->weight + $weight) . ", by '{$addedBy}'";
		$this->logger->info($msg);
		$this->syslogLogger->info($msg);
	}

	/*
	 * POST to the fuzzy_mail API of another node. Throws with the remote
	 * error message, or one that names what went wrong.
	 */
	private function callApi(string $api_server, string $action, int $id, string $user, string $qid, ?int $flag = null, ?int $weight = null): void {
		$lf = "[FuzzyService_callApi]";
		$api_servers = Config::get('API_SERVERS') ?? [];
		$base = (string) ($api_servers[$api_server]['url'] ?? '');

		if ($base === '') {
			$this->logger->error("{$lf} API server '{$api_server}' does not exist in API_SERVERS or has an empty url. Check config.local.php");
			throw new FuzzyException("API server '{$api_server}' has no url in API_SERVERS", FuzzyError::Internal);
		}

		if (empty($_ENV['MAIL_API_USER']) || empty($_ENV['MAIL_API_PASS'])) {
			$this->logger->warning("{$lf} MAIL_API_USER or MAIL_API_PASS not set");
			throw new FuzzyException("MAIL_API_USER or MAIL_API_PASS not set", FuzzyError::Internal);
		}

		// no redirects: a redirect can end on a page that answers 200
		$apiClient = new ApiClient(array_merge(
			$api_servers[$api_server]['options'] ?? [],
			['max_redirects' => 0]
		));

		try {
			$response = $apiClient->postWithAuth(
				$base . Config::get('FUZZY_MAIL_API_PATH'),
				[
					'action' => $action,
					'id' => $id,
					'remote_user' => $user,
				] + ($flag !== null ? ['flag' => $flag] : [])
				  + ($weight !== null ? ['weight' => $weight] : []),
				$_ENV['MAIL_API_USER'],
				$_ENV['MAIL_API_PASS']
			);
			$code = $response->getStatusCode();
			$body = trim($response->getContent(false));
		} catch (Throwable $e) {
			$this->logger->error("{$lf} {$qid} {$action} via '{$api_server}' failed: " . $e->getMessage());
			throw new FuzzyException("API server '{$api_server}' is not reachable", FuzzyError::Upstream);
		}

		// only our API's exact reply counts as success
		if ($code === Response::HTTP_OK && $body === "Message {$action}ed") {
			return;
		}

		$this->logger->error("{$lf} {$qid} {$action} via '{$api_server}' returned {$code}: '" .
			mb_strimwidth($body, 0, 200, '...') . "'");

		if ($code === Response::HTTP_OK) {
			throw new FuzzyException("API server '{$api_server}' gave an unexpected reply ({$code})", FuzzyError::Upstream);
		}

		// our API answers in plain text; anything else is the web server
		if (in_array($code, [Response::HTTP_UNAUTHORIZED, Response::HTTP_FORBIDDEN], true)) {
			$this->logger->warning("{$lf} Check remote web server access control as well as local and remote MAIL_API_USER, MAIL_API_PASS, MAIL_API_ACL, API_ENABLE");
			throw new FuzzyException("API server '{$api_server}' refused access ({$code})" .
				(($body !== '' && !str_contains($body, '<')) ? ": {$body}" : ''), FuzzyError::Internal);
		}

		if ($body === '' || str_contains($body, '<')) {
			throw new FuzzyException("API server '{$api_server}' gave an unexpected reply ({$code})", FuzzyError::Upstream);
		}

		throw new FuzzyException($body, FuzzyError::Upstream);
	}

	private function deleteHashes(string $server, array $hashes, int $flag, string $qid): void {
		$this->call($server, '/fuzzydelhash', '', [
			'Flag' => (string) $flag,
			'Hash' => array_values($hashes),
		], $qid);
	}

	/*
	 * POST to the local rspamd controller. Returns the decoded JSON reply,
	 * throws on transport errors and non-200 replies.
	 */
	private function call(string $server, string $path, string $body, array $headers, string $qid): array {
		$lf = "[FuzzyService_call]";
		// the mail's server entry, as the web side checked; not our own alias
		$url = Config::get('API_SERVERS')[$server]['fuzzy_url'] ?? '';

		if ($url === '') {
			$this->logger->error("{$lf} no fuzzy_url for API server '{$server}'. Check config.local.php");
			throw new FuzzyException("Fuzzy learning is not configured for '{$server}': no fuzzy_url", FuzzyError::Unavailable);
		}

		$password = (string) ($_ENV['RSPAMD_CONTROLLER_ENABLE_PASS'] ?? '');
		if ($password !== '') {
			$headers['Password'] = $password;
		}

		try {
			$response = (new ApiClient())->postToRspamd(
				rtrim($url, '/') . $path, $body, $headers, self::TIMEOUT
			);
			$code = $response->getStatusCode();
			$content = $response->getContent(false);
		} catch (Throwable $e) {
			$this->logger->error("{$lf} {$qid} {$path} for '{$server}' failed: " . $e->getMessage());
			throw new FuzzyException("rspamd controller {$url} for '{$server}' is not reachable", FuzzyError::Upstream);
		}

		$reply = json_decode($content, true);

		if ($code !== Response::HTTP_OK || !is_array($reply)) {
			$err = is_array($reply) ? (string) ($reply['error'] ?? $content) : $content;
			$this->logger->error("{$lf} {$qid} {$path} for '{$server}' returned {$code}: {$err}");
			throw new FuzzyException("rspamd for '{$server}' refused the request: {$err}", FuzzyError::Upstream);
		}

		return $reply;
	}

	// /fuzzyadd the stored mail; returns the hashes rspamd reports
	private function fuzzyAdd(MailLog $maillog, int $flag, int $weight, string $lf): array {
		$qid = (string) $maillog->qid;
		$location = (string) $maillog->mail_location;
		$raw = is_file($location) ? file_get_contents($location) : false;

		if ($raw === false || $raw === '') {
			$this->logger->error("{$lf} {$qid} file '{$location}' not readable");
			throw new FuzzyException("Mail {$qid} file not readable", FuzzyError::Internal);
		}

		$reply = $this->call((string) $maillog->server, '/fuzzyadd', $raw, [
			'Flag' => (string) $flag,
			'Weight' => (string) $weight,
		], $qid);

		$hashes = $reply['hashes'] ?? null;

		if (!is_array($hashes) || $hashes === [] ||
			count(preg_grep(self::HASH_FORMAT, $hashes)) !== count($hashes)) {
			$this->logger->error("{$lf} {$qid} unexpected fuzzyadd reply: " . json_encode($reply));
			throw new FuzzyException("Unexpected reply from rspamd", FuzzyError::Upstream);
		}

		return $hashes;
	}

	private function checkWeight(?int $weight): void {
		if ($weight !== null && ($weight < 1 || $weight > 65535)) {
			throw new FuzzyException("Invalid weight {$weight}, must be 1-65535", FuzzyError::Conflict);
		}
	}

	// the configured list for $flag; refuses flags not in $fuzzy_learn_flags
	private function flagEntry(int $flag, string $qid): array {
		$flags = self::flags();

		if (!isset($flags[$flag])) {
			$alias = (string) ($_ENV['MY_API_SERVER_ALIAS'] ?? '');
			$this->logger->error("[FuzzyService] {$qid} fuzzy flag {$flag} is not in fuzzy_learn_flags on '{$alias}'. Check config.local.php");
			throw new FuzzyException("Fuzzy flag {$flag} is not configured on '{$alias}'", FuzzyError::Unavailable);
		}

		return $flags[$flag];
	}

}
