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
use App\Configuration\Config;

use App\Models\MailLog;
use App\Models\MailLogFuzzy;

use Psr\Log\LoggerInterface;

use Illuminate\Pagination\LengthAwarePaginator;

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

	public function getByMailLogId(int $mailLogId): ?MailLogFuzzy {
		return MailLogFuzzy::where('mail_log_id', $mailLogId)->first();
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

		$location = (string) $maillog->mail_location;
		$raw = is_file($location) ? file_get_contents($location) : false;

		if ($raw === false || $raw === '') {
			$this->logger->error("{$lf} {$qid} file '{$location}' not readable");
			throw new FuzzyException("Mail {$qid} file not readable", FuzzyError::Internal);
		}

		$entry = $this->flagEntry($flag, $qid);
		$this->checkWeight($weight);
		$weight ??= $entry['weight'];

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

		try {
			$row = MailLogFuzzy::create([
				'mail_log_id' => (int) $maillog->id,
				'qid' => $maillog->qid,
				// the mail's server, so unlearn() routes the same way learn() did
				'api_server' => (string) $maillog->server,
				'flag' => $flag,
				'weight' => $weight,
				'hashes' => array_values($hashes),
				'learned_by' => $learnedBy,
			]);
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

	public function unlearnLocal(MailLogFuzzy $row, string $unlearnedBy): void {
		$qid = (string) $row->qid;

		// a shared digest stays in rspamd until its last learned mail is unlearned
		$shared = $this->sharedHashes($row);
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
