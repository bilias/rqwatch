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

class IdActionIndex extends AbstractMigration {

	protected const string MIGRATION_NAME = Migrations::ID_ACTION_INDEX;

	private const string INDEX_ID_ACTION = 'id_action_index';

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
		$this->createIdActionIndex();

		if (!$this->hasIndex(AppConfig::MAIL_LOGS_TABLE, self::INDEX_ID_ACTION)) {
			throw new RuntimeException(
				"Failed to create " . self::INDEX_ID_ACTION . " index"
			);
		}
	}

	protected function verifySchema(): bool {
		return $this->hasIndex(
			AppConfig::MAIL_LOGS_TABLE,
			self::INDEX_ID_ACTION
		);
	}

	private function createIdActionIndex(): void {
		$this->alterTable(
			AppConfig::MAIL_LOGS_TABLE,
			function (Blueprint $table) {
				$table->index(
					['id', 'action'],
					self::INDEX_ID_ACTION
				);
			}
		);
	}

}
