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
use App\Inventory\Migrations;

use Symfony\Component\Console\Output\OutputInterface;

use RuntimeException;

class CreatedDayMigration extends AbstractMigration {

	protected const string MIGRATION_NAME = Migrations::CREATED_DAY;

	private const string COLUMN_CREATED_DAY = 'created_day';
	private const string INDEX_CREATED_DAY = 'created_day_index';
	private const string INDEX_CREATED_DAY_ACTION = 'created_day_action_index';
	private const string INDEX_MAIL_STORED_CREATED_DAY = 'mail_stored_created_day_index';
	private const string INDEX_HAS_VIRUS_CREATED_DAY = 'has_virus_created_day_index';

	public function run(int $batch, int $sleep, bool $force, OutputInterface $output) {
		$this->ensureMigrationsTable();

		$name = $this->getName();
		$descr = $this->getDescr();
		$details = "'{$descr}' ($name)";

		// completed and verified
		if ($this->isApplied()) {
			$output->writeln("<comment>Migration $details is already recorded\n</comment>");
			return true;
		}

		if ($this->verifySchema()) {
			$output->writeln("<comment>Migration $details exists, recording status\n</comment>");
			$this->recordMigrationStatus(Migrations::STATUS_COMPLETED);
			return true;
		}

		$this->fileLogger->info("Starting migration $name");
		$output->writeln("<comment>Starting migration $details</comment>");
		$output->writeln("<question>This will take some time, please be patient</question>");

		try {
			// create table if does not exist, then check and throw if not exist
			$this->recordMigrationStatus(Migrations::STATUS_RUNNING);

			$this->runMigration();
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

	private function runMigration(): void {
		$this->createCreatedDayColumn();

		if (!$this->hasColumn(AppConfig::MAIL_LOGS_TABLE, self::COLUMN_CREATED_DAY)) {
			throw new RuntimeException(
				"Failed to create " . self::COLUMN_CREATED_DAY . " column"
			);
		}
	}

	protected function verifySchema(): bool {
		return $this->hasColumn(
			AppConfig::MAIL_LOGS_TABLE,
			self::COLUMN_CREATED_DAY
		)
		&&
		$this->hasIndex(
			AppConfig::MAIL_LOGS_TABLE,
			self::INDEX_CREATED_DAY
		);
		$this->hasIndex(
			AppConfig::MAIL_LOGS_TABLE,
			self::INDEX_CREATED_DAY_ACTION
		);
		$this->hasIndex(
			AppConfig::MAIL_LOGS_TABLE,
			self::INDEX_MAIL_STORED_CREATED_DAY
		);
		$this->hasIndex(
			AppConfig::MAIL_LOGS_TABLE,
			self::INDEX_HAS_VIRUS_CREATED_DAY
		);
	}

	private function createCreatedDayColumn(): void {
		$this->alterTable(
			AppConfig::MAIL_LOGS_TABLE,
			function (Blueprint $table) {
				$table->date(self::COLUMN_CREATED_DAY)
					->storedAs('DATE(created_at)')
					->after('updated_at');

				$table->index(
					self::COLUMN_CREATED_DAY,
					self::INDEX_CREATED_DAY
				);
				$table->index(
					[self::COLUMN_CREATED_DAY, 'action'],
					self::INDEX_CREATED_DAY_ACTION
				);
				$table->index(
					['mail_stored', self::COLUMN_CREATED_DAY],
					self::INDEX_MAIL_STORED_CREATED_DAY
				);
				$table->index(
					['has_virus', self::COLUMN_CREATED_DAY],
					self::INDEX_HAS_VIRUS_CREATED_DAY
				);
			}
		);
	}

}
