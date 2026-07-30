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

	public function run(int $batch, int $sleep, ?OutputInterface $output = null): bool {
		$this->ensureMigrationsTable();

		if ($this->hasMigration($this->getName())) {
			$output?->writeln("<info>Migration {$this->getName()} is already performed</info>");
			return true;
		}

		$this->ensureRecipientsTable($output);

		if (!$this->hasTable(AppConfig::MAIL_LOG_RECIPIENTS_TABLE)) {
			throw new RuntimeException(
				"Failed to create " . AppConfig::MAIL_LOG_RECIPIENTS_TABLE
			);
		}

		$this->fileLogger?->info("Starting migration {$this->getName()}");
		$output?->writeln("<comment>Starting migration {$this->getName()} in batches of {$batch}</comment>");
		$output?->writeln("<comment>This will take some time, please be patient</comment>");
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
			return true;
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

		$this->recordMigration($this->getName());
		$this->fileLogger?->info("Finished migration {$this->getName()}");
		return true;
	}

	private function ensureRecipientsTable(OutputInterface $output): void {
		if ($this->hasTable(AppConfig::MAIL_LOG_RECIPIENTS_TABLE)) {
			return;
		}

		$this->createRecipientsTable($output);
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

	public function verify(): bool {
		if ($this->hasMigration($this->getName())) {
			return true; // already done
		}

		if (!$this->hasTable(AppConfig::MAIL_LOG_RECIPIENTS_TABLE)) {
			$this->fileLogger?->warning(
				"DB Migration {$this->getName()} requires manual execution"
			);
			$this->fileLogger?->warning(
				"See: https://github.com/bilias/rqwatch/blob/master/docs/MAIL_RECIPIENTS_UPDATE.md"
			);

			return false;
		}

		return true;
	}

}
