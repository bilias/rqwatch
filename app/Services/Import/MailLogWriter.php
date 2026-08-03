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

use App\Inventory\Migrations;
use App\Core\Database\MigrationStatus;

use App\Core\Database\Migrations\MailRecipientsMigration;
use App\Core\Database\Migrations\MailLogDataMigration;

use App\Models\MailLogData;

final class MailLogWriter
{
	private Capsule $capsule;
	private LoggerInterface $fileLogger;
	private MigrationStatus $migrationStatus;

	private ?bool $supportsRecipients = null;

	public function __construct() {
		$this->capsule = App::capsule();
		$this->fileLogger = App::fileLogger();
		$this->migrationStatus = App::migrationStatus();
	}

	public function insert(array $mailData, array $recipients): int {
		return $this->capsule
			->connection()
			->transaction(function () use ($mailData, $recipients) {

				$mailLogId = $this->insertMailLog($mailData);

				$this->insertRecipients(
					$mailLogId,
					$recipients
				);

				return $mailLogId;
			});
	}

	// Insert into mail_logs.
	private function insertMailLog(array $mailData): int {
		// we are in the middle of MAIL_LOG_DATA migration
		// dual write to both tables
		if ($this->migrationStatus->isMigrationRunning(Migrations::MAIL_LOG_DATA)) {
			$this->fileLogger->info("dual write, migration running");
			return $this->insertMailLogDualWrite($mailData);
		}

		// MAIL_LOG_DATA completed
		if ($this->supportsMailLogData()) {
			$this->fileLogger->info("split write, migration done");
			return $this->insertMailLogSplitWrite($mailData);
		}

		// legacy write
		$this->fileLogger->info("legacy write");
		return $this->insertMailLogLegacyWrite($mailData);
	}

	private function insertMailLogSplitWrite(array $mailData): int {
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

	private function insertMailLogDualWrite(array $mailData): int {
		$mailLogId = $this->capsule
			->table(AppConfig::MAIL_LOGS_TABLE)
			->insertGetId($mailData);

		$mailLogData = [
			'mail_log_id' => $mailLogId,
			'headers' => $mailData['headers'] ?? null,
			'symbols' => $mailData['symbols'] ?? null,
			'fuzzy_hashes' => $mailData['fuzzy_hashes'] ?? null,
		];

		$this->insertMailLogData(
			$mailLogId,
			$mailLogData
		);

		return $mailLogId;
	}

	private function insertMailLogLegacyWrite(array $mailData): int {
		return $this->capsule
			->table(AppConfig::MAIL_LOGS_TABLE)
			->insertGetId($mailData);
	}

	// Insert into mail_logs_data.
	private function insertMailLogData(int $mailLogId, array $mailData): void {
		$mailData['mail_log_id'] = $mailLogId;

		$this->capsule
			->table(AppConfig::MAIL_LOG_DATA_TABLE)
			->insert($mailData);
	}

	// Insert recipients.
	private function insertRecipients(int $mailLogId, array $recipients): void {
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
		// auto fallback to legacy mode
		//return $this->migrationStatus->isMigrationApplied(
		return $this->migrationStatus->isMigrationCompleted(
			Migrations::MAIL_RECIPIENTS
		);
	}

	private function supportsMailLogData(): bool {
		// auto fallback to legacy mode
		//return $this->migrationStatus->isMigrationApplied(
		return $this->migrationStatus->isMigrationCompleted(
			Migrations::MAIL_LOG_DATA
		);
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
