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

use Symfony\Component\HttpFoundation\Response;

use RuntimeException;
use Throwable;

/*
 * Fuzzy learning of quarantined mail into the rspamd fuzzy storage.
 *
 * learnLocal()/unlearnLocal() run on the node that stores the mail and talk
 * to that node's own rspamd controller (API_SERVERS[alias]['fuzzy_url']).
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


	// learn on this node, or through the API of the node that stores the mail
	public function learn(MailLog $maillog, string $learnedBy): void {
		$server = (string) $maillog->server;

		if (!self::serverEnabled($server)) {
			throw new RuntimeException("Fuzzy learning is not available for server '{$server}'");
		}

		if ($server === ($_ENV['MY_API_SERVER_ALIAS'] ?? '')) {
			$this->learnLocal($maillog, $learnedBy);
			return;
		}

		$this->callApi($server, 'learn', (int) $maillog->id, $learnedBy, (string) $maillog->qid);
	}

	// unlearn on the node that did the learn
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
		$alias = (string) ($_ENV['MY_API_SERVER_ALIAS'] ?? '');
		$qid = (string) $maillog->qid;

		if ($maillog->server !== $alias) {
			throw new RuntimeException("Mail {$qid} is not stored on this server", Response::HTTP_CONFLICT);
		}

		if (!$maillog->mail_stored) {
			throw new RuntimeException("Mail {$qid} is not stored", Response::HTTP_CONFLICT);
		}

		if ($this->getByMailLogId((int) $maillog->id) !== null) {
			throw new RuntimeException("Mail {$qid} is already learned", Response::HTTP_CONFLICT);
		}

		$location = (string) $maillog->mail_location;
		$raw = is_file($location) ? file_get_contents($location) : false;

		if ($raw === false || $raw === '') {
			$this->logger->error("{$lf} {$qid} file '{$location}' not readable");
			throw new RuntimeException("Mail {$qid} file not readable", Response::HTTP_INTERNAL_SERVER_ERROR);
		}

		$flag = $this->flag();
		$weight = $this->weight();

		$reply = $this->call('/fuzzyadd', $raw, [
			'Flag' => (string) $flag,
			'Weight' => (string) $weight,
		], $qid);

		$hashes = $reply['hashes'] ?? null;

		if (!is_array($hashes) || $hashes === [] ||
			count(preg_grep(self::HASH_FORMAT, $hashes)) !== count($hashes)) {
			$this->logger->error("{$lf} {$qid} unexpected fuzzyadd reply: " . json_encode($reply));
			throw new RuntimeException("Unexpected reply from rspamd", Response::HTTP_BAD_GATEWAY);
		}

		try {
			$row = MailLogFuzzy::create([
				'mail_log_id' => (int) $maillog->id,
				'qid' => $maillog->qid,
				'api_server' => $alias,
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
					$this->deleteHashes($hashes, $flag, $qid);
				}
			} catch (Throwable $e2) {
				$this->logger->critical("{$lf} {$qid} could not remove unrecorded hashes " .
					implode(',', $hashes) . ": " . $e2->getMessage());
			}
			throw new RuntimeException("Mail {$qid} could not be recorded");
		}

		$msg = "{$qid} learned as fuzzy (flag {$flag}, weight {$weight}, " .
			count($hashes) . " hashes) by '{$learnedBy}'";
		$this->logger->info($msg);
		$this->syslogLogger->info($msg);

		return $row;
	}

	public function unlearnLocal(MailLogFuzzy $row, string $unlearnedBy): void {
		$alias = (string) ($_ENV['MY_API_SERVER_ALIAS'] ?? '');
		$qid = (string) $row->qid;

		if ($row->api_server !== $alias) {
			throw new RuntimeException("Mail {$qid} was not learned on this server", Response::HTTP_CONFLICT);
		}

		$this->deleteHashes((array) $row->hashes, (int) $row->flag, $qid);
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
			throw new RuntimeException("Error. Contact admin");
		}

		if (empty($_ENV['MAIL_API_USER']) || empty($_ENV['MAIL_API_PASS'])) {
			$this->logger->warning("{$lf} MAIL_API_USER or MAIL_API_PASS not set");
			throw new RuntimeException("Error. Contact admin");
		}

		$apiClient = new ApiClient($api_servers[$api_server]['options'] ?? []);

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
			throw new RuntimeException("API server '{$api_server}' is not reachable");
		}

		if ($code === Response::HTTP_OK) {
			return;
		}

		$this->logger->error("{$lf} {$qid} {$action} via '{$api_server}' returned {$code}: '{$body}'");

		// our API answers in plain text; anything else is the web server
		if (in_array($code, [Response::HTTP_UNAUTHORIZED, Response::HTTP_FORBIDDEN], true)) {
			$this->logger->warning("{$lf} Check local and remote MAIL_API_USER, MAIL_API_PASS, MAIL_API_ACL");
			throw new RuntimeException("Error. Contact admin");
		}

		if ($body === '' || str_contains($body, '<')) {
			throw new RuntimeException("Error. Contact admin");
		}

		throw new RuntimeException($body);
	}

	private function deleteHashes(array $hashes, int $flag, string $qid): void {
		$this->call('/fuzzydelhash', '', [
			'Flag' => (string) $flag,
			'Hash' => array_values($hashes),
		], $qid);
	}

	/*
	 * POST to the local rspamd controller. Returns the decoded JSON reply,
	 * throws on transport errors and non-200 replies.
	 */
	private function call(string $path, string $body, array $headers, string $qid): array {
		$lf = "[FuzzyService_call]";
		$alias = (string) ($_ENV['MY_API_SERVER_ALIAS'] ?? '');
		$url = Config::get('API_SERVERS')[$alias]['fuzzy_url'] ?? '';

		if ($url === '') {
			$this->logger->error("{$lf} no fuzzy_url for API server '{$alias}'. Check config.local.php");
			throw new RuntimeException("Fuzzy learning is not configured on this server", Response::HTTP_NOT_IMPLEMENTED);
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
			$this->logger->error("{$lf} {$qid} {$path} failed: " . $e->getMessage());
			throw new RuntimeException("rspamd controller is not reachable", Response::HTTP_BAD_GATEWAY);
		}

		$reply = json_decode($content, true);

		if ($code !== Response::HTTP_OK || !is_array($reply)) {
			$err = is_array($reply) ? (string) ($reply['error'] ?? $content) : $content;
			$this->logger->error("{$lf} {$qid} {$path} returned {$code}: {$err}");
			throw new RuntimeException("rspamd refused the request: {$err}", Response::HTTP_BAD_GATEWAY);
		}

		return $reply;
	}

	private function flag(): int {
		$flag = (int) Config::get('fuzzy_learn_flag');
		if ($flag < 1 || $flag > 255) {
			throw new RuntimeException("Invalid fuzzy_learn_flag in config", Response::HTTP_INTERNAL_SERVER_ERROR);
		}
		return $flag;
	}

	private function weight(): int {
		$weight = (int) Config::get('fuzzy_learn_weight');
		if ($weight < 1 || $weight > 65535) {
			throw new RuntimeException("Invalid fuzzy_learn_weight in config", Response::HTTP_INTERNAL_SERVER_ERROR);
		}
		return $weight;
	}

}
