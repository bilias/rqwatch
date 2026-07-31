<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Core\Database\Migrations;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

use Psr\Log\LoggerInterface;

use App\Configuration\AppConfig;
use App\Configuration\MigrationList;

use App\Core\Database\AbstractMigration;

use Symfony\Component\Console\Output\OutputInterface;

use RuntimeException;

class MailRecipientsMigration extends AbstractMigration {

	public function getName(): string {
		return MigrationList::MAIL_RECIPIENTS;
	}

	public function run(int $batch, int $sleep, bool $force, ?OutputInterface $output = null): bool {
		$this->ensureMigrationsTable();

		if (!$force && $this->hasMigration()) {
			$output?->writeln("<info>Migration {$this->getName()} is already recorded</info>");
			return true;
		}

		if (!$force && $this->verify()) {
			$output?->writeln("<info>Migration {$this->getName()} exists, recording status</info>");
			$this->recordMigration();
			return true;
		}

		$this->fileLogger?->info("Starting migration {$this->getName()}");
		$output?->writeln("<comment>Starting migration {$this->getName()} in batches of {$batch}</comment>");
		$output?->writeln("<comment>This will take some time, please be patient</comment>");

		try {
			// create table if does not exist, then check and throw if not exist
			$this->ensureRecipientsTable($output);
			$this->runMigration($batch, $sleep, $output);
			$this->recordMigration();
		} catch (\Throwable $e) {
			$this->fileLogger?->error(
				"Migration {$this->getName()} failed: " . $e->getMessage()
			);

			$output?->writeln(
				"<error>Migration {$this->getName()} failed: {$e->getMessage()}</error>"
			);

			return false;
		}

		$this->fileLogger?->info("Finished migration {$this->getName()}");
		return true;
	}

	private function runMigration(int $batch, int $sleep, ?OutputInterface $output = null): void {
		$output?->write("<info>Total recipients: </info>");

		$baseQuery = $this->capsule::table(AppConfig::MAIL_LOGS_TABLE . ' as ml')
			->select('ml.id', 'ml.rcpt_to', 'r.mail_log_id')
			->leftJoin(AppConfig::MAIL_LOG_RECIPIENTS_TABLE . ' as r', 'r.mail_log_id', '=', 'ml.id');
			//->whereNull('r.mail_log_id');
			//->where('ml.rcpt_to', '!=', 'unknown');

		$total = (clone $baseQuery)->count('ml.id');

		$unknown = (clone $baseQuery)
							->where('ml.rcpt_to', '=', 'unknown')
							->count('ml.id');

		$total = $total - $unknown;
		$output?->writeln("<info>{$total}</info>");
		if ($total == 0) {
			return;
		}

		$lastId = 0;
		$scanned = 0;
		$migrated = 0;

		while (true) {
			$query = (clone $baseQuery)
				->where('ml.id', '>', $lastId)
				->orderBy('ml.id')
				->limit($batch);

			/*
			$sql = $query->toSql();
			$bindings = implode(', ', $query->getBindings());
			$output?->writeln("SQL iteration, lastId={$lastId}: {$sql} | Bindings: {$bindings}");
			*/
			$logs = $query->get();

			if ($logs->isEmpty()) {
				break;
			}

			$batchRows = [];
			$inserted = 0;
			foreach ($logs as $log) {

				// already migrated mail_log id
				if ($log->mail_log_id !== null) {
					continue;
				}

				/*
				$rcptTo = trim(strtolower((string)$log->rcpt_to));
				if ($rcptTo === '' || $rcptTo === 'unknown') {
					continue;
				}

				$recipients = array_unique(array_filter(
					array_map(
						static fn(string $email) => trim(strtolower($email)),
						explode(',', $log->rcpt_to)
					)
				));
				*/

				if ($log->rcpt_to === null || $log->rcpt_to === '') {
					continue;
				}

				$recipients = [];
				foreach (explode(',', $log->rcpt_to) as $email) {
					$email = strtolower(trim($email));
					if ($email === '' || $email === 'unknown') {
						continue;
					}

					$recipients[$email] = true;
				}

				$recipients = array_keys($recipients);

				if (empty($recipients)) {
					continue;
				}

				//$rows = [];
				foreach ($recipients as $email) {
					// $rows[] = [
					$batchRows[] = [
						'mail_log_id'     => $log->id,
						'recipient_email' => $email,
					];
				}

				//$this->capsule->getConnection()->beginTransaction();

				/*
				try {
					$inserted += $this->capsule::table(AppConfig::MAIL_LOG_RECIPIENTS_TABLE)->insertOrIgnore($rows);
					//$this->capsule->getConnection()->commit();
				} catch (\Exception $e) {
					//$this->capsule->getConnection()->rollBack();
					throw $e;
				}
				*/
			}

			// insert in batches
			if (!empty($batchRows)) {
				try {
					$inserted += $this->capsule::table(AppConfig::MAIL_LOG_RECIPIENTS_TABLE)->insertOrIgnore($batchRows);
				} catch (\Exception $e) {
					throw $e;
				}
			}

			$lastId = $logs->last()->id;
			$scanned += $logs->count();
			$migrated += $inserted;
			$remaining = max(0, $total - $scanned);
			$output?->writeln("<info>Scanned: {$scanned}, Remaining: {$remaining}, Migrated: {$migrated} (recipients)</info>"
);
			usleep($sleep);
		}
	}

	private function ensureRecipientsTable(OutputInterface $output): void {
		if ($this->hasTable(AppConfig::MAIL_LOG_RECIPIENTS_TABLE)) {
			return;
		}

		try {
			$this->createRecipientsTable($output);
		} catch (\Throwable $e) {
			$this->fileLogger?->error(
				"createRecipientsTable failed: " . $e->getMessage()
			);

			throw $e;
		}

		if (!$this->hasTable(AppConfig::MAIL_LOG_RECIPIENTS_TABLE)) {
			throw new RuntimeException("Failed to create ". AppConfig::MAIL_LOG_RECIPIENTS_TABLE . " table");
		}
	}

	private function createRecipientsTable(OutputInterface $output): void
    {
		$this->fileLogger?->info("Creating table " . AppConfig::MAIL_LOG_RECIPIENTS_TABLE);
		$output?->writeln("<comment>Creating table " . AppConfig::MAIL_LOG_RECIPIENTS_TABLE . "</comment>");

		$this->createTable(
			AppConfig::MAIL_LOG_RECIPIENTS_TABLE,
			function (Blueprint $table) {
				$table->unsignedInteger('mail_log_id');
				$table->string('recipient_email', 255);

				$table->primary([
					'mail_log_id',
					'recipient_email'
				]);

				$table->index(
					'recipient_email',
					'recipient_email_idx'
				);

				$table->index(
					[
						'recipient_email',
						'mail_log_id'
					],
					'recipient_email_mail_log_id_idx'
				);

				$table->foreign('mail_log_id', 'fk_mail')
					->references('id')
					->on(AppConfig::MAIL_LOGS_TABLE)
					->onDelete('cascade');
				}
		);
	}

	protected function verify(): bool {
		return $this->hasTable(
			AppConfig::MAIL_LOG_RECIPIENTS_TABLE
		);
	}

}
