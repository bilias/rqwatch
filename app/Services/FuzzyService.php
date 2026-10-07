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
	public function learn(MailLog $maillog, string $learnedBy): void {
		$server = (string) $maillog->server;

		if (!self::serverEnabled($server)) {
			throw new FuzzyException("Fuzzy learning is not available for server '{$server}'", FuzzyError::Unavailable);
		}

		if ($server === ($_ENV['MY_API_SERVER_ALIAS'] ?? '')) {
			$this->learnLocal($maillog, $learnedBy);
			return;
		}

		$this->callApi($server, 'learn', (int) $maillog->id, $learnedBy, (string) $maillog->qid);
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

	public function learnLocal(MailLog $maillog, string $learnedBy): MailLogFuzzy {
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

		$flag = $this->flag();
		$weight = $this->weight();

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
			try {
				// a concurrent learn of the same mail owns these hashes: keep them
				if ($this->getByMailLogId((int) $maillog->id) === null) {
					$this->deleteHashes((string) $maillog->server, $hashes, $flag, $qid);
				}
			} catch (Throwable $e2) {
				$this->logger->critical("{$lf} {$qid} could not remove unrecorded hashes " .
					implode(',', $hashes) . ": " . $e2->getMessage());
			}
			throw new FuzzyException("Mail {$qid} could not be recorded", FuzzyError::Internal);
		}

		$msg = "{$qid} learned as fuzzy (flag {$flag}, weight {$weight}, " .
			count($hashes) . " hashes) by '{$learnedBy}'";
		$this->logger->info($msg);
		$this->syslogLogger->info($msg);

		return $row;
	}

	public function unlearnLocal(MailLogFuzzy $row, string $unlearnedBy): void {
		$qid = (string) $row->qid;

		$this->deleteHashes((string) $row->api_server, (array) $row->hashes, (int) $row->flag, $qid);
		$row->delete();

		$msg = "{$qid} unlearned from fuzzy (flag {$row->flag}) by '{$unlearnedBy}'";
		$this->logger->info($msg);
		$this->syslogLogger->info($msg);
	}

	/*
	 * POST to the fuzzy_mail API of another node. Throws with the remote
	 * error message, or a generic one for auth and transport problems.
	 */
	private function callApi(string $api_server, string $action, int $id, string $user, string $qid): void {
		$lf = "[FuzzyService_callApi]";
		$api_servers = Config::get('API_SERVERS') ?? [];
		$base = (string) ($api_servers[$api_server]['url'] ?? '');

		if ($base === '') {
			$this->logger->error("{$lf} API server '{$api_server}' does not exist in API_SERVERS or has an empty url. Check config.local.php");
			throw new FuzzyException("Error. Contact admin", FuzzyError::Internal);
		}

		if (empty($_ENV['MAIL_API_USER']) || empty($_ENV['MAIL_API_PASS'])) {
			$this->logger->warning("{$lf} MAIL_API_USER or MAIL_API_PASS not set");
			throw new FuzzyException("Error. Contact admin", FuzzyError::Internal);
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
				],
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
			throw new FuzzyException("Error. Contact admin", FuzzyError::Upstream);
		}

		// our API answers in plain text; anything else is the web server
		if (in_array($code, [Response::HTTP_UNAUTHORIZED, Response::HTTP_FORBIDDEN], true)) {
			$this->logger->warning("{$lf} Check remote web server access control as well as local and remote MAIL_API_USER, MAIL_API_PASS, MAIL_API_ACL, API_ENABLE");
			throw new FuzzyException("Error. Contact admin", FuzzyError::Internal);
		}

		if ($body === '' || str_contains($body, '<')) {
			throw new FuzzyException("Error. Contact admin", FuzzyError::Upstream);
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

	private function flag(): int {
		$flag = (int) Config::get('fuzzy_learn_flag');
		if ($flag < 1 || $flag > 255) {
			$this->logger->error("[FuzzyService] invalid fuzzy_learn_flag '" .
				json_encode(Config::get('fuzzy_learn_flag')) . "' in config, must be 1-255");
			throw new FuzzyException("Invalid fuzzy_learn_flag in config", FuzzyError::Internal);
		}
		return $flag;
	}

	private function weight(): int {
		$weight = (int) Config::get('fuzzy_learn_weight');
		if ($weight < 1 || $weight > 65535) {
			$this->logger->error("[FuzzyService] invalid fuzzy_learn_weight '" .
				json_encode(Config::get('fuzzy_learn_weight')) . "' in config, must be 1-65535");
			throw new FuzzyException("Invalid fuzzy_learn_weight in config", FuzzyError::Internal);
		}
		return $weight;
	}

}
