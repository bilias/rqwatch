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

use Symfony\Component\Console\Output\OutputInterface;

use RuntimeException;
use Throwable;

class MailLogFuzzyMigration extends AbstractMigration {

	protected const string MIGRATION_NAME = Migrations::MAIL_LOG_FUZZY;

	public function run(int $batch, int $sleep, bool $force, OutputInterface $output): bool {
		$this->ensureMigrationsTable();

		$name = $this->getName();
		$descr = $this->getDescr();
		$details = "'{$descr}' ($name)";

		// completed and verified
		if ($this->isApplied()) {
			$output->writeln("<comment>Migration $details is already applied\n</comment>");
			return true;
		}

		if ($this->verifySchema()) {
			$output->writeln("<comment>Migration $details exists, recording status\n</comment>");
			$this->recordMigrationStatus(Migrations::STATUS_COMPLETED);
			return true;
		}

		$this->fileLogger->info("Starting migration $name");
		$output->writeln("<comment>Starting migration $details</comment>");

		try {
			$this->recordMigrationStatus(Migrations::STATUS_RUNNING);

			$this->runMigration($output);
			$this->recordMigrationStatus(Migrations::STATUS_COMPLETED);
		} catch (Throwable $e) {
			$this->fileLogger->error(
				"Migration $name failed: " . $e->getMessage()
			);

			$output->writeln(
				"<error>Migration $details failed: {$e->getMessage()}</error>"
			);

			$this->recordMigrationFailed();

			return false;
		}

		$this->fileLogger->info("Migration $name completed");
		$output->writeln("<comment>Migration $details completed\n</comment>");
		return true;
	}

	private function runMigration(OutputInterface $output): void {
		$this->createFuzzyTable($output);

		if (!$this->hasTable(AppConfig::MAIL_LOG_FUZZY_TABLE)) {
			throw new RuntimeException(
				"Failed to create " . AppConfig::MAIL_LOG_FUZZY_TABLE . " table"
			);
		}
	}

	protected function verifySchema(): bool {
		return $this->hasTable(AppConfig::MAIL_LOG_FUZZY_TABLE);
	}

	private function createFuzzyTable(OutputInterface $output): void {
		$this->fileLogger->info("Creating table " . AppConfig::MAIL_LOG_FUZZY_TABLE);
		$output->writeln("<comment>Creating table " . AppConfig::MAIL_LOG_FUZZY_TABLE . "</comment>");

		$this->createTable(
			AppConfig::MAIL_LOG_FUZZY_TABLE,
			function (Blueprint $table) {
				$table->increments('id');
				// nullable: the learn record outlives the mail_logs row
				$table->unsignedInteger('mail_log_id')->nullable();
				$table->string('qid', 30)->nullable();
				$table->string('api_server', 10);
				$table->unsignedTinyInteger('flag');
				$table->unsignedSmallInteger('weight');
				$table->json('hashes');
				$table->string('learned_by', 100);
				$table->timestamp('created_at')->useCurrent();

				$table->unique('mail_log_id', 'mail_log_id_idx');

				$table->foreign('mail_log_id', 'fk_mail_log_fuzzy_mail_logs')
					->references('id')
					->on(AppConfig::MAIL_LOGS_TABLE)
					->nullOnDelete();
			}
		);
	}

}
