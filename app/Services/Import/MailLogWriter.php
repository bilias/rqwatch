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

use Psr\Log\LoggerInterface;

use Illuminate\Database\Capsule\Manager as Capsule;

use App\Core\Database\MigrationStatus;

use App\Models\MailLogData;

use Illuminate\Database\QueryException;
use PDOException;

final class MailLogWriter
{
	private Capsule $capsule;
	private LoggerInterface $fileLogger;
	private LoggerInterface $syslogLogger;
	private MigrationStatus $migrationStatus;

	private const int MAX_DEADLOCK_RETRIES = 3;
	private const int DEADLOCK_RETRY_DELAY_US = 500000;

	public function __construct() {
		$this->capsule = App::capsule();
		$this->fileLogger = App::fileLogger();
		$this->syslogLogger = App::syslogLogger();
		$this->migrationStatus = App::migrationStatus();
	}


	public function insert(array $mailData, array $recipients): int {
		for ($attempt = 1; $attempt <= self::MAX_DEADLOCK_RETRIES; $attempt++) {
			try {
				return $this->insertTransaction($mailData, $recipients);

			} catch (QueryException | PDOException $e) {

				if (
					!$this->isConcurrencyError($e)
					|| $attempt >= self::MAX_DEADLOCK_RETRIES
				) {
					throw $e;
				}

				$this->fileLogger->warning(
					"Deadlock inserting " . ($mailData['qid'] ?? 'unknown') .
					", retry {$attempt}/" .
					self::MAX_DEADLOCK_RETRIES
				);

				usleep($attempt * self::DEADLOCK_RETRY_DELAY_US); // 500ms, 1s before retries
			}
		}

		throw new \RuntimeException(
			"Insert failed after " . self::MAX_DEADLOCK_RETRIES . " retries"
		);
	}

	private function insertTransaction(array $mailData, array $recipients): int {
		return $this->capsule
			->connection()
			->transaction(function () use ($mailData, $recipients) {

				$mailLogId = $this->insertMailLog($mailData);
				$this->insertMailRecipients($mailLogId, $recipients);

				return $mailLogId;
			});
	}
	/*
	 MariaDB deadlock / serialization failure
	 SQLSTATE 40001. The code is a string on a QueryException, whose
	 constructor copies it from the previous exception, but PDO can report
	 it as an int, so both are tested -- the same pair Illuminate's own
	 ConcurrencyErrorDetector checks, with its message fallback.
	*/
	private function isConcurrencyError(PDOException $e): bool {
		if ($e->getCode() === '40001' || $e->getCode() === 40001) {
			return true;
		}

		return str_contains(
			$e->getMessage(),
			'Deadlock found when trying to get lock'
		);
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
		if (!$this->supportsRecipients()) {
			return;
		}

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

	private function supportsRecipients(): bool {
		return $this->migrationStatus->mailRecipientsCompleted();
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
