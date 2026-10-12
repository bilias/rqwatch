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

class SessionVersion extends AbstractMigration {

	protected const string MIGRATION_NAME = Migrations::SESSION_VERSION;

	// bumped to end a user's sessions after an account change
	private const string COLUMN = 'session_version';
	private const string DEFINITION = 'INT(10) UNSIGNED NOT NULL DEFAULT 0 AFTER `password`';

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

		// a fresh install from db-init.sql already has the column
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
		$table = AppConfig::USERS_TABLE;

		$this->capsule->getConnection()->statement(
			"ALTER TABLE `{$table}` ADD COLUMN `" . self::COLUMN . "` " . self::DEFINITION . ", ALGORITHM=INSTANT"
		);
		$output->writeln("<info>Added column " . self::COLUMN . " to {$table}</info>");

		if (!$this->verifySchema()) {
			throw new RuntimeException("Failed to add " . self::COLUMN . " to {$table}");
		}
	}

	protected function verifySchema(): bool {
		return $this->hasColumn(AppConfig::USERS_TABLE, self::COLUMN);
	}

}
