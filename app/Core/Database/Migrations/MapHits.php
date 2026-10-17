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

class MapHits extends AbstractMigration {

	protected const string MIGRATION_NAME = Migrations::MAP_HITS;

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

		// a fresh install from db-init.sql already has the schema
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

	// each part is skipped when present, so a failed run can be re-run
	private function runMigration(OutputInterface $output): void {
		$this->addCustomMapSymbols($output);
		$this->createSymbolNamesTable($output);
		$this->createSymbolHitsTable($output);

		if (!$this->verifySchema()) {
			throw new RuntimeException("Failed to create the map hits schema");
		}
	}

	protected function verifySchema(): bool {
		return $this->hasColumn(AppConfig::CUSTOM_MAP_CONFIG_TABLE, 'symbols')
			&& $this->hasTable(AppConfig::SYMBOL_NAMES_TABLE)
			&& $this->hasTable(AppConfig::MAIL_LOG_SYMBOL_HITS_TABLE);
	}

	private function addCustomMapSymbols(OutputInterface $output): void {
		$table = AppConfig::CUSTOM_MAP_CONFIG_TABLE;
		if ($this->hasColumn($table, 'symbols')) {
			return;
		}

		// INSTANT fails instead of falling back to a table rebuild under TOI
		$this->capsule->getConnection()->statement(
			"ALTER TABLE `{$table}` ADD COLUMN `symbols` VARCHAR(255) DEFAULT NULL AFTER `field_label`, ALGORITHM=INSTANT"
		);
		$output->writeln("<info>Added column symbols to {$table}</info>");
	}

	private function createSymbolNamesTable(OutputInterface $output): void {
		$table = AppConfig::SYMBOL_NAMES_TABLE;
		if ($this->hasTable($table)) {
			return;
		}

		$this->fileLogger->info("Creating table {$table}");
		$output->writeln("<comment>Creating table {$table}</comment>");

		$this->createTable($table, function (Blueprint $table) {
			$table->increments('id');
			// rspamd symbol names are case-sensitive
			$table->string('name', 128)->collation('utf8mb4_bin');

			$table->unique('name', 'name_index');
		});
	}

	private function createSymbolHitsTable(OutputInterface $output): void {
		$table = AppConfig::MAIL_LOG_SYMBOL_HITS_TABLE;
		if ($this->hasTable($table)) {
			return;
		}

		$this->fileLogger->info("Creating table {$table}");
		$output->writeln("<comment>Creating table {$table}</comment>");

		$this->createTable($table, function (Blueprint $table) {
			$table->unsignedInteger('symbol_id');
			$table->date('created_day');
			$table->unsignedInteger('mail_log_id');
			// 0: no entry, 1: maps_combined, 2: maps_custom
			$table->unsignedTinyInteger('entry_model')->default(0);
			$table->unsignedInteger('entry_id')->default(0);
			$table->double('score')->nullable();

			$table->primary(['symbol_id', 'created_day', 'mail_log_id', 'entry_model', 'entry_id']);
			$table->index('mail_log_id', 'mail_log_id_index');
			$table->index(['entry_model', 'entry_id', 'created_day'], 'entry_index');

			$table->foreign('mail_log_id', 'fk_mail_log_symbol_hits_mail_logs')
				->references('id')
				->on(AppConfig::MAIL_LOGS_TABLE)
				->cascadeOnDelete();

			$table->foreign('symbol_id', 'fk_mail_log_symbol_hits_symbol_names')
				->references('id')
				->on(AppConfig::SYMBOL_NAMES_TABLE);
		});
	}

}
