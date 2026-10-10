<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Core\Database\Migrations;

use App\Configuration\AppConfig;
use App\Inventory\Migrations;

use Symfony\Component\Console\Output\OutputInterface;

use RuntimeException;
use Throwable;

class EverStored extends AbstractMigration {

	protected const string MIGRATION_NAME = Migrations::EVER_STORED;

	private const string COLUMN = 'ever_stored';
	private const string INDEX = 'ever_stored_created_day_index';

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

		// a fresh install from db-init.sql already has the column and index
		if ($this->verifySchema()) {
			$output->writeln("<comment>Migration $details exists, recording status\n</comment>");
			$this->recordMigrationStatus(Migrations::STATUS_COMPLETED);
			return true;
		}

		$this->fileLogger->info("Starting migration $name");
		$output->writeln("<comment>Starting migration $details</comment>");
		$output->writeln("<question>This will take some time, please be patient</question>");

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
		$table = AppConfig::MAIL_LOGS_TABLE;
		$column = self::COLUMN;
		$index = self::INDEX;
		$db = $this->capsule->getConnection();

		// INSTANT and INPLACE fail instead of falling back to a table copy under TOI
		if (!$this->hasColumn($table, $column)) {
			$db->statement(
				"ALTER TABLE `{$table}` ADD COLUMN `{$column}` TINYINT(1) GENERATED ALWAYS AS "
				. "(`mail_stored` = 1 OR (`mail_location` IS NOT NULL AND `mail_location` <> '0')) VIRTUAL "
				. "AFTER `mail_stored`, ALGORITHM=INSTANT"
			);
			$output->writeln("<info>Added column {$column} to {$table}</info>");
		}

		if (!$this->hasIndex($table, $index)) {
			$db->statement(
				"ALTER TABLE `{$table}` ADD INDEX `{$index}` (`{$column}`, `created_day`), "
				. "ALGORITHM=INPLACE, LOCK=NONE"
			);
			$output->writeln("<info>Added index {$index} to {$table}</info>");
		}

		if (!$this->verifySchema()) {
			throw new RuntimeException("Failed to add {$column} column and index to {$table}");
		}
	}

	protected function verifySchema(): bool {
		return $this->hasColumn(AppConfig::MAIL_LOGS_TABLE, self::COLUMN)
			&& $this->hasIndex(AppConfig::MAIL_LOGS_TABLE, self::INDEX);
	}

}
