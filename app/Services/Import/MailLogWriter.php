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

use Throwable;

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

		// after the commit: counting fuzzy hits must never cost the mail
		$this->recordFuzzyHits($mailData);

		return $id;
	}

	// credit learned fuzzy mails whose hashes this mail matched
	private function recordFuzzyHits(array $mailData): void {
		$hashes = json_decode((string) ($mailData['fuzzy_hashes'] ?? ''), true);
		if (!is_array($hashes) || $hashes === []) {
			return;
		}

		$qid = (string) ($mailData['qid'] ?? '');
		try {
			(new FuzzyService())->recordHits($hashes, $mailData['created_at'] ?? null, $qid);
		} catch (Throwable $e) {
			App::fileLogger()->error("[MailLogWriter] {$qid} could not record fuzzy hits: " . $e->getMessage());
		}
	}

	/*
	 Insert into mail_logs and mail_log_data. Split write is the only mode:
	 MAIL_LOG_DATA is a required migration, enforced by
	 Kernel::verifyRequiredMigrations()
	*/
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
	private function insertMailLogData(int $mailLogId, array $mailData): void {
		$mailData['mail_log_id'] = $mailLogId;

		$this->capsule
			->table(AppConfig::MAIL_LOG_DATA_TABLE)
			->insert($mailData);
	}

	// Insert recipients.
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

	private function splitMailData(array $mailData): array {
		$main = $mailData;
		$extra = [];

		foreach (MailLogData::DATA_COLUMNS as $column) {
			if (array_key_exists($column, $main)) {
				$extra[$column] = $main[$column];
				unset($main[$column]);
			}
		}

		return [$main, $extra];
	}

}
