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

class UserNotificationsDefault extends AbstractMigration {

	protected const string MIGRATION_NAME = Migrations::USER_NOTIFICATIONS_DEFAULT;

	private const string COLUMN = 'disable_notifications';

	public function run(int $batch, int $sleep, bool $force, OutputInterface $output): bool {
		$this->ensureMigrationsTable();

		$name = $this->getName();
		$descr = $this->getDescr();
		$details = "'{$descr}' ($name)";

		// completed and verified. $force is ignored: re-running the
		// conversion would erase explicit opt-ins.
		if ($this->isApplied()) {
			$output->writeln("<comment>Migration $details is already applied\n</comment>");
			return true;
		}

		// no adopt branch: a nullable column alone does not mean the
		// 0 -> NULL conversion ran, and both steps are idempotent until COMPLETED
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
		$column = self::COLUMN;

		if (!$this->columnIsNullable($table, $column)) {
			$this->capsule->getConnection()->statement(
				"ALTER TABLE `{$table}` MODIFY `{$column}` TINYINT(1) DEFAULT NULL"
			);
			$output->writeln("<info>{$table}.{$column} is now nullable</info>");
		}

		// before COMPLETED every 0 means "follow the default". updated_at is
		// assigned explicitly so ON UPDATE does not touch it.
		$updated = $this->capsule->getConnection()->update(
			"UPDATE `{$table}` SET `{$column}` = NULL, `updated_at` = `updated_at` "
			. "WHERE `{$column}` = 0"
		);
		$output->writeln("<info>{$updated} users set to follow the default</info>");

		if (!$this->verifySchema()) {
			throw new RuntimeException("Failed to make {$table}.{$column} nullable");
		}
	}

	protected function verifySchema(): bool {
		return $this->columnIsNullable(AppConfig::USERS_TABLE, self::COLUMN);
	}

}
