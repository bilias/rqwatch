<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Services\Import;

use App\Configuration\Config;

use App\Core\App;
use App\Core\Cache\RedisCache;

use App\Utils\Helper;

use Symfony\Component\Console\Output\OutputInterface;

use Psr\Log\LoggerInterface;
use Throwable;

/*
 Holds metadata for mails whose mail_logs insert was refused, so a cron
 can replay them later.

 The connection is up in this case and the write is rejected -- a
 deadlock, a certification failure, a read-only node. A database that
 cannot be reached at all never gets here: Kernel::bootDatabase() fails
 first and answers 503, and that mail's metadata is lost.

 Only the metadata is spooled. Helper::store_raw_mail() has already
 written the message to local disk by the time MailLogWriter::insert()
 runs, so the payload is a few KB rather than the whole mail -- see the
 write-order note in the project docs. Helper::discard_raw_mail() must
 therefore NOT run on a path that spools: the spooled mail_location has
 to keep pointing at a file that exists.
*/
final class MailLogSpool
{
	private LoggerInterface $fileLogger;
	private LoggerInterface $syslogLogger;

	/*
	 Entries and the counter need separate namespaces. A counter key
	 sharing the entry prefix would be returned by the all-servers SCAN
	 in drain() and parsed as a payload. Colon-delimited like
	 LoginThrottle's rqwatch_throttle:count: / :block: keys.
	*/
	private const string MAIL_NS = ':mail:';
	private const string COUNT_NS = ':count:';

	/*
	 listByPrefix() is on RedisCache and not on CacheInterface, the same
	 reason LoginThrottle::listBlocked() types against the concrete
	 class. RedisCache connects lazily, so holding it costs nothing.
	*/
	private ?RedisCache $cache = null;

	public function __construct() {
		$this->fileLogger = App::fileLogger();
		$this->syslogLogger = App::syslogLogger();

		if (Helper::env_bool('REDIS_ENABLE')) {
			$cache = App::cache();
			$this->cache = $cache instanceof RedisCache ? $cache : null;
		}
	}

	/*
	 MY_API_SERVER_ALIAS names the node holding the raw file, which is
	 not $data['server'] -- that comes from rspamd's ?server= and names
	 the mail server, and one node can serve several of them.
	*/
	private function serverAlias(): string {
		$alias = trim((string) ($_ENV['MY_API_SERVER_ALIAS'] ?? ''));

		if ($alias === '') {
			// spool anyway: losing a mail over a missing env var would
			// defeat the point. An all-servers drain still finds it.
			$this->fileLogger->error('[MailLogSpool] MY_API_SERVER_ALIAS is empty');

			return 'unknown';
		}

		return $alias;
	}

	/*
	 The alias is the variable part of the key and is followed by ':',
	 so it must not be able to contain one -- otherwise the prefix for
	 'mx1' would also match an alias of 'mx1:a'. rawurlencode() escapes
	 ':' to '%3A' and leaves A-Za-z0-9-_.~ alone, so 'mx1', 'mx-1',
	 'mx_1' and 'mx.example.com' all stay readable in the key while the
	 delimiter stays unambiguous. Do not swap this for a character
	 filter: that would rewrite the operator's alias.
	*/
	private function aliasKeyPart(string $alias): string {
		return rawurlencode($alias);
	}

	private function baseKey(): string {
		return (string) Config::get('import_spool_redis_key');
	}

	// every server's entries, for an all-servers drain
	private function allMailPrefix(): string {
		return $this->baseKey() . self::MAIL_NS;
	}

	// one server's entries
	private function mailPrefix(string $alias): string {
		return $this->allMailPrefix() . $this->aliasKeyPart($alias) . ':';
	}

	private function countKey(string $alias): string {
		return $this->baseKey() . self::COUNT_NS . $this->aliasKeyPart($alias);
	}

	// '<base>:mail:<encoded alias>:<uniqid>' -> the decoded alias
	private function aliasFromKey(string $key): string {
		$rest = substr($key, strlen($this->allMailPrefix()));

		if ($rest === '') {
			return 'unknown';
		}

		return rawurldecode(explode(':', $rest, 2)[0]);
	}

	/*
	 How many entries are waiting. Counts keys with a SCAN rather than
	 reading the counter, so it reports the truth even if the counter has
	 drifted -- affordable here because only the CLI calls it. push()
	 must keep using the counter: a SCAN per request during a mass
	 failure is exactly the wrong cost.
	*/
	public function pending(bool $localOnly): int {
		if ($this->cache === null) {
			return 0;
		}

		$prefix = $localOnly
			? $this->mailPrefix($this->serverAlias())
			: $this->allMailPrefix();

		try {
			return count($this->cache->listByPrefix($prefix));
		} catch (Throwable $e) {
			$this->fileLogger->error('[MailLogSpool] pending: ' . $e->getMessage());

			return 0;
		}
	}

	/*
	 Store one failed insert. Returns false when the caller must fall
	 back to its own failure response, so a false here means "still
	 lost" and the caller should keep answering 500.
	*/
	public function push(array $mailData, array $recipients): bool {
		/*
		 Deliberately here and not in the constructor: pending() and
		 drain() must keep working with the switch off, so cli cron:import_spool
		 can still import mail from Redis
		*/
		if (!Config::get('import_spool')) {
			return false;
		}

		if ($this->cache === null) {
			return false;
		}

		$cache = $this->cache;

		$max = (int) Config::get('import_spool_max');
		$ttl = (int) Config::get('import_spool_ttl');

		if ($max < 1 || $ttl < 1) {
			$this->fileLogger->error('[MailLogSpool] import_spool_max/ttl not usable');

			return false;
		}

		$alias = $this->serverAlias();
		$countKey = $this->countKey($alias);
		$qid = (string) ($mailData['qid'] ?? 'unknown');

		try {
			$pending = (int) ($cache->get($countKey) ?: 0);

			if ($pending >= $max) {
				$this->fileLogger->critical(
					"[MailLogSpool] spool full ({$pending}/{$max}) on {$alias}, "
					. "not spooling {$qid}"
				);

				return false;
			}

			/*
			 created_day is GENERATED ALWAYS AS (cast(created_at as date))
			 STORED, so without an explicit created_at a replayed row takes
			 the replay date: wrong quarantine day, wrong stats bucket, and
			 it disagrees with the date directory the file already sits in.
			 insertGetId() is the query builder, so $fillable does not
			 block it.
			*/
			$mailData['created_at'] = date('Y-m-d H:i:s');

			$payload = json_encode(
				['data' => $mailData, 'recipients' => $recipients],
				JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
			);

			if ($payload === false) {
				$this->fileLogger->error(
					"[MailLogSpool] cannot encode {$qid}: " . json_last_error_msg()
				);

				return false;
			}

			$key = $this->mailPrefix($alias) . uniqid('', true);

			if (!$cache->set($key, $payload, $ttl)) {
				$this->fileLogger->error("[MailLogSpool] cannot store {$key} for {$qid}");

				return false;
			}

			$cache->incr($countKey);
			$cache->expire($countKey, $ttl);

			$spooled = "{$qid} spooled as {$key} for later import";
			$this->fileLogger->warning("[MailLogSpool] {$spooled}");
			$this->syslogLogger->warning($spooled);

			return true;
		} catch (Throwable $e) {
			$this->fileLogger->error('[MailLogSpool] push: ' . $e->getMessage());

			return false;
		}
	}

	/*
	 Replay spooled entries into the database.

	 $localOnly restricts to this node's own entries, which is the only
	 mode that can check the raw file: mail_location names a path on the
	 node that received the mail. Insert first and delete the key after,
	 deliberately -- there is no natural key to dedupe on (qid_index is
	 not unique), so the choice is between a duplicate row and a lost
	 mail if the process dies between COMMIT and DEL, and this feature
	 exists to stop losing mails.

	 Any insert failure stops the whole pass and leaves the rest spooled:
	 a cron firing mid-stall would otherwise grind through the batch
	 failing every one. An unusable payload is skipped instead, so one
	 bad entry cannot block the queue; it is left in place for the
	 operator and cleared by the TTL.

	 @return array{found:int,inserted:int,skipped:int,stopped:bool}
	*/
	public function drain(
		bool $localOnly,
		int $batch,
		bool $showOnly,
		?OutputInterface $output = null
	): array {

		$result = ['found' => 0, 'inserted' => 0, 'skipped' => 0, 'stopped' => false];

		if ($this->cache === null) {
			$output?->writeln('<comment>Redis is not enabled; nothing to do</comment>');

			return $result;
		}

		$cache = $this->cache;
		$alias = $localOnly ? $this->serverAlias() : null;
		$prefix = $alias === null ? $this->allMailPrefix() : $this->mailPrefix($alias);

		try {
			$keys = array_keys($cache->listByPrefix($prefix));
		} catch (Throwable $e) {
			$this->fileLogger->error('[MailLogSpool] drain list: ' . $e->getMessage());
			$output?->writeln('<error>Cannot list spooled entries: ' . $e->getMessage() . '</error>');

			return $result;
		}

		sort($keys);
		$result['found'] = count($keys);
		$keys = array_slice($keys, 0, $batch);
		$writer = new MailLogWriter();
		$seen = [];

		foreach ($keys as $key) {
			$seen[$this->aliasFromKey($key)] = true;

			try {
				$raw = $cache->get($key);
			} catch (Throwable $e) {
				$this->fileLogger->error("[MailLogSpool] cannot read {$key}: " . $e->getMessage());
				$result['skipped']++;
				continue;
			}

			$entry = is_string($raw) ? json_decode($raw, true) : null;

			if (!is_array($entry)
				|| !isset($entry['data'])
				|| !is_array($entry['data'])
				|| !is_array($entry['recipients'] ?? null)
			) {
				$this->fileLogger->error("[MailLogSpool] unusable payload in {$key}");
				$output?->writeln("<error>Unusable payload in {$key}</error>");
				$result['skipped']++;
				continue;
			}

			$data = $entry['data'];
			$qid = (string) ($data['qid'] ?? 'unknown');

			if ($showOnly) {
				$output?->writeln(
					"QID: {$qid}, stored: " . (string) ($data['mail_stored'] ?? '0')
					. ", key: {$key}"
				);
				continue;
			}

			/*
			 Only the owning node can see the file. A row claiming
			 mail_stored = 1 against a missing file breaks the detail and
			 release pages, so record the metadata honestly instead.
			*/
			if ($localOnly
				&& !empty($data['mail_location'])
				&& !file_exists((string) $data['mail_location'])
			) {
				$gone = "{$qid} raw file is gone: {$data['mail_location']},"
					. " importing with mail_stored = 0";
				$this->fileLogger->warning("[MailLogSpool] {$gone}");
				$this->syslogLogger->warning($gone);
				$data['mail_stored'] = 0;
				$data['mail_location'] = null;
			}

			try {
				$id = $writer->insert($data, $entry['recipients']);
			} catch (Throwable $e) {
				$reason = $e->getPrevious()?->getMessage() ?? $e->getMessage();
				$failed = "import of {$qid} failed, stopping ({$key}): {$reason}";

				$this->fileLogger->critical("[MailLogSpool] {$failed}");
				$this->syslogLogger->critical($failed);
				$output?->writeln("<error>{$failed}</error>");
				$result['stopped'] = true;
				break;
			}

			try {
				$cache->delete($key);
			} catch (Throwable $e) {
				// the row is committed; a surviving key means one duplicate
				// on the next pass, which is the direction we chose
				$stuck = "{$qid} imported as {$id} but {$key} remains, may import"
					. " twice: " . $e->getMessage();
				$this->fileLogger->error("[MailLogSpool] {$stuck}");
				$this->syslogLogger->error($stuck);
			}

			$result['inserted']++;

			$saved = "{$qid} saved in DB [id: {$id}] by cron:import_spool";
			$this->fileLogger->info("[MailLogSpool] {$saved}");
			$this->syslogLogger->info($saved);

			$output?->writeln(
				"<info>Imported {$qid} as id {$id}</info>",
				OutputInterface::VERBOSITY_VERBOSE
			);
		}

		if (!$showOnly) {
			$this->resyncCounters($cache, $localOnly ? [(string) $alias => true] : $seen);
		}

		return $result;
	}

	/*
	 The cap in push() reads a counter rather than counting keys, because
	 during a mass failure a SCAN per request is exactly the wrong cost.
	 That counter drifts, so every drain pass rewrites it from the real
	 remaining count.
	*/
	private function resyncCounters(RedisCache $cache, array $aliases): void {
		$ttl = (int) Config::get('import_spool_ttl');

		foreach (array_keys($aliases) as $alias) {
			$alias = (string) $alias;

			try {
				$remaining = count($cache->listByPrefix($this->mailPrefix($alias)));
				$cache->set($this->countKey($alias), (string) $remaining, $ttl);
			} catch (Throwable $e) {
				$this->fileLogger->error(
					"[MailLogSpool] cannot resync counter for {$alias}: " . $e->getMessage()
				);
			}
		}
	}

}
