<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Core\Database\Migrations;

use App\Core\App;
use App\Configuration\AppConfig;
use App\Inventory\Migrations;

use Symfony\Component\Console\Output\OutputInterface;

use RuntimeException;
use Throwable;

class MailLogFuzzyHits extends AbstractMigration {

	protected const string MIGRATION_NAME = Migrations::MAIL_LOG_FUZZY_HITS;

	// added in this order
	private const array COLUMNS = [
		'hits'        => 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER `learned_by`',
		'last_hit_at' => 'DATETIME DEFAULT NULL AFTER `hits`',
		'updated_at'  => 'TIMESTAMP NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() AFTER `created_at`',
	];

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

		// the columns go on mail_log_fuzzy
		if (!App::migrationStatus()->mailLogFuzzyCompleted()) {
			$required = Migrations::MIGRATION_DESCR[Migrations::MAIL_LOG_FUZZY];

			$this->fileLogger->error(
				"Migration $name requires '{$required}' (" . Migrations::MAIL_LOG_FUZZY . ") to be completed first"
			);

			$output->writeln(
				"<error>Migration $details requires '{$required}' to be completed first</error>"
			);

			return false;
		}

		// a fresh install from db-init.sql already has the columns
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
		$table = AppConfig::MAIL_LOG_FUZZY_TABLE;

		$parts = [];
		foreach (self::COLUMNS as $column => $definition) {
			if (!$this->hasColumn($table, $column)) {
				$parts[] = "ADD COLUMN `{$column}` {$definition}";
			}
		}

		if (!empty($parts)) {
			// INSTANT fails instead of falling back to a table rebuild under TOI
			$this->capsule->getConnection()->statement(
				"ALTER TABLE `{$table}` " . implode(', ', $parts) . ", ALGORITHM=INSTANT"
			);
			$output->writeln("<info>Added " . count($parts) . " column(s) to {$table}</info>");
		}

		if (!$this->verifySchema()) {
			throw new RuntimeException("Failed to add fuzzy hit and updated_at columns to {$table}");
		}
	}

	protected function verifySchema(): bool {
		foreach (array_keys(self::COLUMNS) as $column) {
			if (!$this->hasColumn(AppConfig::MAIL_LOG_FUZZY_TABLE, $column)) {
				return false;
			}
		}
		return true;
	}

}
