<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Core\Database\Migrations;

use Illuminate\Database\Schema\Blueprint;

use App\Configuration\AppConfig;
use App\Inventory\Migrations;
use App\Models\MailLogData;

use Symfony\Component\Console\Output\OutputInterface;

use RuntimeException;

class MailLogDataMigration extends AbstractMigration {

	protected const string MIGRATION_NAME = Migrations::MAIL_LOG_DATA;

	public function run(int $batch, int $sleep, bool $force, OutputInterface $output): bool {
		$this->ensureMigrationsTable();

		$name = $this->getName();
		$descr = $this->getDescr();
		$details = "'{$descr}' ($name)";

		// completed and verified
		if (!$force && $this->isApplied()) {
			$output->writeln("<comment>Migration $details is already recorded\n</comment>");
			return true;
		}

		if (!$force && $this->verifySchema()) {
			$output->writeln("<comment>Migration $details exists, recording status\n</comment>");
			$this->recordMigrationStatus(Migrations::STATUS_COMPLETED);
			return true;
		}

		$this->fileLogger->info("Starting migration $name");
		$output->writeln("<comment>Starting migration $details in batches of {$batch}</comment>");
		$output->writeln("<question>This will take some time, please be patient</question>");

		try {
			// create table if does not exist, then check and throw if not exist
			$this->ensureMailLogDataTable($output);
			$this->recordMigrationStatus(Migrations::STATUS_RUNNING);

			$this->runMigration($batch, $sleep, $output);
			$this->recordMigrationStatus(Migrations::STATUS_COMPLETED);
		} catch (\Throwable $e) {
			$this->fileLogger->error(
				"Migration $name failed: " . $e->getMessage()
			);

			$output->writeln(
				"<error>Migration $details failed: {$e->getMessage()}</error>"
			);

			return false;
		}

		$this->fileLogger->info("Migration $name completed");
		$output->writeln("<comment>Migration $details completed\n</comment>");
		return true;
	}

	private function runMigration(int $batch, int $sleep, ?OutputInterface $output = null): void {
		$output->write("<info>Total entries: </info>");

		$baseQuery = $this->capsule::table(AppConfig::MAIL_LOGS_TABLE . ' as ml')
			->select(
				'ml.id',
				'ml.headers',
				'ml.symbols',
				'ml.fuzzy_hashes',
				'md.mail_log_id'
			)
			->leftJoin(
				AppConfig::MAIL_LOG_DATA_TABLE . ' as md',
				'md.mail_log_id',
				'=',
				'ml.id'
			)->whereNull('md.mail_log_id');

		$total = (clone $baseQuery)->count('ml.id');

		$output->writeln("<info>{$total}</info>");
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

			$logs = $query->get();

			if ($logs->isEmpty()) {
				break;
			}

			$batchRows = [];
			$inserted = 0;
			foreach ($logs as $log) {

				// already migrated mail_log id
				/* removed by whereNull('md.mail_log_id') above
				if ($log->mail_log_id !== null) {
					continue;
				}
				*/

				$row = [
					'mail_log_id' => $log->id,
				];

				foreach (MailLogData::DATA_COLUMNS as $column) {
					$row[$column] = $log->$column;
				}

				$batchRows[] = $row;
			}

			// insert in batches
			if (!empty($batchRows)) {
				$inserted += $this->capsule::table(AppConfig::MAIL_LOG_DATA_TABLE)->insertOrIgnore($batchRows);
			}

			$lastId = $logs->last()->id;
			$scanned += $logs->count();
			$migrated += $inserted;
			$remaining = max(0, $total - $scanned);
			$output->writeln("<info>Found: {$scanned}, Remaining: {$remaining}, Migrated: {$migrated} (mails)</info>"
);
			usleep($sleep);
		}
	}

	private function ensureMailLogDataTable(OutputInterface $output): void {
		if ($this->hasTable(AppConfig::MAIL_LOG_DATA_TABLE)) {
			return;
		}

		try {
			$this->createMailLogDataTable($output);
		} catch (\Throwable $e) {
			$this->fileLogger->error(
				"createMailLogDataTable failed: " . $e->getMessage()
			);

			throw $e;
		}

		if (!$this->hasTable(AppConfig::MAIL_LOG_DATA_TABLE)) {
			throw new RuntimeException("Failed to create ". AppConfig::MAIL_LOG_DATA_TABLE . " table");
		}
	}

	private function createMailLogDataTable(OutputInterface $output): void
    {
		$this->fileLogger->info("Creating table " . AppConfig::MAIL_LOG_DATA_TABLE);
		$output->writeln("<comment>Creating table " . AppConfig::MAIL_LOG_DATA_TABLE . "</comment>");

		$this->createTable(
			AppConfig::MAIL_LOG_DATA_TABLE,
			function (Blueprint $table) {
				$table->unsignedInteger('mail_log_id');

				$table->longText('headers')->nullable();
				$table->json('symbols')->nullable();
				$table->json('fuzzy_hashes')->nullable();

				$table->primary('mail_log_id');

				$table->foreign('mail_log_id', 'fk_mail_logs_data_mail_logs')
					->references('id')
					->on(AppConfig::MAIL_LOGS_TABLE)
					->onDelete('cascade');
				}
		);
	}

	protected function verifySchema(): bool {
		return $this->hasTable(
			AppConfig::MAIL_LOG_DATA_TABLE
		);
	}

}
