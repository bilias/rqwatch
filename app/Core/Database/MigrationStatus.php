<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Core\Database;

use Illuminate\Database\Capsule\Manager as Capsule;

use App\Configuration\AppConfig;

use App\Inventory\Migrations;

use App\Core\Database\Migrations\MailRecipientsMigration;
use App\Core\Database\Migrations\MailLogDataMigration;

use Illuminate\Database\QueryException;

use Psr\Log\LoggerInterface;

use RuntimeException;

final class MigrationStatus
{
	// migration status/state cache
	private array $stateCache = [];

	private bool $migrationTableExists = false;

	public function __construct(
		private Capsule $capsule,
		private LoggerInterface $fileLogger
	) {}

	public function setMigrationState(string $migration, ?string $status): void {
		if (!in_array($migration, Migrations::MIGRATIONS, true)) {
			throw new RuntimeException("Unknown migration: {$migration}");
		}

		$this->stateCache[$migration] = $status;
	}

	public function getMigrationState(string $migration): ?string {
		if (array_key_exists($migration, $this->stateCache)) {
			return $this->stateCache[$migration];
		}

		if (!in_array($migration, Migrations::MIGRATIONS, true)) {
			throw new RuntimeException("Unknown migration : {$migration}");
		}

		try {
			return $this->stateCache[$migration] = $this->capsule
				->table(AppConfig::MIGRATIONS_TABLE)
				->where('migration', $migration)
				->value('status');
		} catch (QueryException $e) {
			// migrations table does not exist yet
			if ($e->getCode() === '42S02') {
				/*
				$this->fileLogger->warning(
					"Migration table '" . AppConfig::MIGRATIONS_TABLE .
					"' does not exist"
				);
				*/
				return $this->stateCache[$migration] = null;
			}

			throw $e;
		}
	}

	public function isMigrationRunning(string $migration): bool {
		return $this->getMigrationState($migration)
			=== Migrations::STATUS_RUNNING;
	}

	public function isMigrationCompleted(string $migration): bool {
		return $this->getMigrationState($migration)
			=== Migrations::STATUS_COMPLETED;
	}

	public function getAllMigrationStates(): array {
		$states = [];

		foreach (Migrations::MIGRATIONS as $migration) {
			$states[$migration] = $this->getMigrationState($migration);
		}

		return $states;
	}

	public function setMigrationTableExists(bool $exists): void {
		$this->migrationTableExists = $exists;
	}

	public function hasMigrationsTable(): bool {
		return $this->migrationTableExists;
	}

}
