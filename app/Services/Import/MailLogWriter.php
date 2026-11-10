<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Services\Import;

use App\Configuration\AppConfig;

use App\Core\App;

use Illuminate\Database\Capsule\Manager as Capsule;

use App\Models\MailLogData;

use App\Services\FuzzyService;
use App\Services\MapHitService;
use App\Services\VirusService;

use RuntimeException;
use Throwable;
use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Database\LostConnectionDetector;
use PDOException;

final class MailLogWriter
{
	private Capsule $capsule;

	public function __construct() {
		$this->capsule = App::capsule();
	}

	/*
	 Retries are Connection::transaction()'s, deliberately.

	 The framework rolls back correctly on both failure paths, but only
	 when $attempts > 1. A statement deadlock goes through
	 handleTransactionException(), which calls rollBack() unconditionally.
	 A deadlock at COMMIT goes through handleCommitTransactionException(),
	 which decrements Connection::$transactions but guards its
	 $pdo->rollBack() with $currentAttempt < $maxAttempts -- so at the
	 default $attempts = 1 the rollback is skipped, PDO keeps in_txn set
	 (it is cleared only when commit() succeeds) and the next
	 beginTransaction() throws "There is already an active transaction".
	 That is why the hand-rolled retry loop this replaces could never
	 retry the case it was written for.

	 Do not reintroduce a loop around transaction(): it would have to
	 clean up PDO by hand, and rolling back on inTransaction() alone is
	 wrong as soon as anything wraps insert() in a transaction of its own
	 -- a nested statement deadlock throws DeadlockException without
	 rolling back, and the guard would kill the caller's transaction.

	 See ManagesTransactions.php in illuminate/database.
	*/
	/**
	 * $e for the logs: SQLSTATE and error code first, then the full
	 * message. A QueryException's message holds the SQL with its values,
	 * kept on purpose: it is what shows why the database refused a mail
	 * (something not checked before the insert, or a schema that needs
	 * changing).
	 */
	public static function describe(Throwable $e): string {
		if (!$e instanceof PDOException) {
			return get_debug_type($e) . ': ' . $e->getMessage();
		}
		/** @var mixed $state */
		$state = $e->errorInfo[0] ?? null;
		/** @var mixed $code */
		$code = $e->errorInfo[1] ?? $e->getCode();
		return 'SQLSTATE ' . (is_string($state) ? $state : '?') . ', error ' . (is_int($code) || is_string($code) ? $code : '?')
			. ': ' . $e->getMessage();
	}

	/*
	 MariaDB errors that refuse every mail alike, whatever its data: too
	 many connections (1040), access denied (1045), node not ready (Galera,
	 1047), shutdown in progress (1053), lock wait timeout (1205), deadlock
	 (1213), connection killed (1927), and the client's cannot connect,
	 server gone away and lost connection (2002, 2003, 2006, 2013)
	*/
	private const array UNAVAILABLE_CODES = [1040, 1045, 1047, 1053, 1205, 1213, 1927, 2002, 2003, 2006, 2013];

	/**
	 * Whether $e says the database cannot take any mail now (connection,
	 * server, locks), as opposed to rejecting this mail's data. Only the
	 * first is worth spooling: every mail fails alike and replays once the
	 * database is back, where a rejected mail would fail again on replay.
	 */
	public static function databaseUnavailable(Throwable $e): bool {
		if ((new LostConnectionDetector())->causedByLostConnection($e)
			|| (new ConcurrencyErrorDetector())->causedByConcurrencyError($e)) {
			return true;
		}
		if (!$e instanceof PDOException) {
			return false;
		}
		/** @var mixed $state SQLSTATE */
		$state = $e->errorInfo[0] ?? null;
		/** @var mixed $code the driver's error code; a failed connect has it only as the exception code */
		$code = $e->errorInfo[1] ?? $e->getCode();
		return (is_string($state) && str_starts_with($state, '08'))
			|| in_array($code, self::UNAVAILABLE_CODES, true);
	}

	/**
	 * @param array<string, mixed> $mailData mail_logs + mail_log_data columns => values
	 * @param array<array-key, string> $recipients
	 */
	public function insert(array $mailData, array $recipients): int {
		$id = $this->capsule
			->connection()
			->transaction(
				function () use ($mailData, $recipients) {

					$mailLogId = $this->insertMailLog($mailData);
					$this->insertMailRecipients($mailLogId, $recipients);

					return $mailLogId;
				},
				attempts: AppConfig::MAX_DEADLOCK_ATTEMPTS
			);

		// after the commit: counting hits must never cost the mail
		$this->recordFuzzyHits($id, $mailData);
		$this->recordMapHits($id, $mailData);
		$this->recordViruses($id, $mailData);

		return $id;
	}

	// credit learned fuzzy mails whose hashes this mail matched
	/** @param array<string, mixed> $mailData */
	private function recordFuzzyHits(int $mailLogId, array $mailData): void {
		$qid = self::qid($mailData);
		try {
			$hashes = json_decode(self::text($mailData, 'fuzzy_hashes') ?? '', true);
			if (!is_array($hashes) || $hashes === []) {
				return;
			}
			$at = self::text($mailData, 'created_at');
			(new FuzzyService())->recordHits($mailLogId, $hashes, $at, $qid);
		} catch (Throwable $e) {
			App::fileLogger()->error("[MailLogWriter] {$qid} could not record fuzzy hits: " . $e->getMessage());
		}
	}

	// record the map and local rule symbols this mail carries
	/** @param array<string, mixed> $mailData */
	private function recordMapHits(int $mailLogId, array $mailData): void {
		$qid = self::qid($mailData);
		try {
			$symbols = self::text($mailData, 'symbols') ?? '';
			(new MapHitService())->recordHits($mailLogId, $symbols, $qid);
		} catch (Throwable $e) {
			App::fileLogger()->error("[MailLogWriter] {$qid} could not record map hits: " . $e->getMessage());
		}
	}

	// record the antivirus symbols and names of a virus mail
	/** @param array<string, mixed> $mailData */
	private function recordViruses(int $mailLogId, array $mailData): void {
		if (empty($mailData['has_virus'])) {
			return;
		}

		$qid = self::qid($mailData);
		try {
			$symbols = self::text($mailData, 'symbols') ?? '';
			(new VirusService())->recordViruses($mailLogId, $symbols, $qid);
		} catch (Throwable $e) {
			App::fileLogger()->error("[MailLogWriter] {$qid} could not record viruses: " . $e->getMessage());
		}
	}

	/*
	 Insert into mail_logs and mail_log_data. Split write is the only mode:
	 MAIL_LOG_DATA is a required migration, enforced by
	 Kernel::verifyRequiredMigrations()
	*/
	/** @param array<string, mixed> $mailData */
	private function insertMailLog(array $mailData): int {
		[$mailLog, $mailLogData] = $this->splitMailData($mailData);

		$mailLogId = $this->capsule
			->table(AppConfig::MAIL_LOGS_TABLE)
			->insertGetId($mailLog);

		$this->insertMailLogData(
			$mailLogId,
			$mailLogData
		);

		return $mailLogId;
	}

	// Insert into mail_logs_data.
	/** @param array<string, mixed> $mailData mail_log_data columns */
	private function insertMailLogData(int $mailLogId, array $mailData): void {
		$mailData['mail_log_id'] = $mailLogId;

		$this->capsule
			->table(AppConfig::MAIL_LOG_DATA_TABLE)
			->insert($mailData);
	}

	// Insert recipients.
	/** @param array<array-key, string> $recipients */
	private function insertMailRecipients(int $mailLogId, array $recipients): void {
		if (empty($recipients)) {
			return;
		}

		$rows = [];

		foreach (array_unique($recipients) as $email) {
			$email = strtolower(trim($email));

			if ($email === '') {
				continue;
			}

			$rows[] = [
				'mail_log_id'     => $mailLogId,
				'recipient_email' => $email,
			];
		}

		if (empty($rows)) {
			return;
		}

		$this->capsule
			->table(AppConfig::MAIL_LOG_RECIPIENTS_TABLE)
			->insert($rows);
	}

	/**
	 * @param array<string, mixed> $mailData
	 * @return array{array<string, mixed>, array<string, mixed>} mail_logs columns, mail_log_data columns
	 */
	private function splitMailData(array $mailData): array {
		$extra = array_intersect_key($mailData, array_flip(MailLogData::DATA_COLUMNS));

		return [array_diff_key($mailData, $extra), $extra];
	}

	/**
	 * A mail data field the after-commit records read: text, or null when
	 * absent. Another type throws, so the record's catch logs it instead
	 * of a cast quietly recording nothing.
	 * @param array<string, mixed> $mailData
	 */
	private static function text(array $mailData, string $field): ?string {
		/** @var mixed $value */
		$value = $mailData[$field] ?? null;
		if ($value === null || is_string($value)) {
			return $value;
		}
		throw new RuntimeException("{$field} is " . get_debug_type($value) . ', not text');
	}

	// the qid, for log lines only
	/** @param array<string, mixed> $mailData */
	private static function qid(array $mailData): string {
		/** @var mixed $qid */
		$qid = $mailData['qid'] ?? '';
		return is_string($qid) ? $qid : '';
	}

}
