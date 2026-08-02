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

use App\Core\Database\AbstractMigration;

use Symfony\Component\Console\Output\OutputInterface;

use RuntimeException;

class CreatedDayMigration extends AbstractMigration {

	protected const string MIGRATION_NAME = Migrations::ADD_CREATED_DAY;

	private const string COLUMN_CREATED_DAY = 'created_day';
	private const string INDEX_CREATED_DAY = 'created_day_index';

	public function run(int $batch, int $sleep, bool $force, ?OutputInterface $output = null) {
		$this->ensureMigrationsTable();

		$name = $this->getName();
		$descr = $this->getDescr();
		$details = "'{$descr}' ($name)";

		if ($this->hasMigration()) {
			$output?->writeln("<info>Migration $details is already recorded</info>");
			return true;
		}

		if ($this->verify()) {
			$output?->writeln("<info>Migration $details exists, recording status</info>");
			$this->recordMigration();
			return true;
		}

		$this->fileLogger->info("Starting migration $name");
		$output?->writeln("<comment>Starting migration $details</comment>");
		$output?->writeln("<comment>This will take some time, please be patient</comment>");

		try {
			$this->runMigration();
			if (!$this->verify()) {
				throw new RuntimeException(
					"Migration $name verification failed"
				);
				return false;
			}
			$this->recordMigration();
		} catch (\Throwable $e) {
			$this->fileLogger->error(
				"Migration $name failed: " . $e->getMessage()
			);

			$output?->writeln(
				"<error>Migration $details failed: {$e->getMessage()}</error>"
			);

			return false;
		}

		$this->fileLogger->info("Finished migration $name");
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

	protected function verify(): bool {
		return $this->hasColumn(
			AppConfig::MAIL_LOGS_TABLE,
			self::COLUMN_CREATED_DAY
		)
		&&
		$this->hasIndex(
			AppConfig::MAIL_LOGS_TABLE,
			self::INDEX_CREATED_DAY
		);
	}

	private function createCreatedDayColumn(): void {
		$this->alterTable(
			AppConfig::MAIL_LOGS_TABLE,
			function (Blueprint $table) {
				$table->date(self::COLUMN_CREATED_DAY)
					->storedAs('DATE(created_at)')
					->after('updated_at');

				$table->index(self::COLUMN_CREATED_DAY, self::INDEX_CREATED_DAY);
			}
		);
	}

}
