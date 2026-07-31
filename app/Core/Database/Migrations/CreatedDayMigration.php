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

	private const string COLUMN_CREATED_DAY = 'created_day';
	private const string INDEX_CREATED_DAY = 'created_day_index';

	public function getName(): string {
		return Migrations::ADD_CREATED_DAY;
	}

	public function run(?OutputInterface $output = null): bool {
		$this->ensureMigrationsTable();

		if ($this->hasMigration()) {
			$output?->writeln("<info>Migration {$this->getName()} is already recorded</info>");
			return true;
		}

		if ($this->verify()) {
			$output?->writeln("<info>Migration {$this->getName()} exists, recording status</info>");
			$this->recordMigration();
			return true;
		}

		$this->fileLogger?->info("Starting migration {$this->getName()}");
		$output?->writeln("<comment>Starting migration {$this->getName()}</comment>");
		$output?->writeln("<comment>This will take some time, please be patient</comment>");

		try {
			$this->runMigration();
			if (!$this->verify()) {
				throw new RuntimeException(
					"Migration {$this->getName()} verification failed"
				);
				return false;
			}
			$this->recordMigration();
		} catch (\Throwable $e) {
			$this->fileLogger?->error(
				"Migration {$this->getName()} failed: " . $e->getMessage()
			);

			$output?->writeln(
				"<error>Migration {$this->getName()} failed: {$e->getMessage()}</error>"
			);

			return false;
		}

		$this->fileLogger?->info("Finished migration {$this->getName()}");
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
