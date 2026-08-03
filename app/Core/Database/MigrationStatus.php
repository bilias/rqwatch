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
	private array $cache = [];

	// migration status/state cache
	private array $stateCache = [];

	// migration applied cache
	private array $appliedCache = [];

	private bool $migrationTableExists = false;

	public function __construct(
		private Capsule $capsule,
		private LoggerInterface $fileLogger
	) {}

	public function hasMailRecipients(): bool {
		return $this->check(
			Migrations::MAIL_RECIPIENTS
		);
	}

	public function hasCreatedDay(): bool {
		return $this->check(
			Migrations::CREATED_DAY
		);
	}

	public function hasMailLogData(): bool {
		return $this->check(
			Migrations::MAIL_LOG_DATA
		);
	}

	private function check(string $key): bool {
		if (array_key_exists($key, $this->cache)) {
			return $this->cache[$key];
		}

		if (!isset(Migrations::MIGRATION_CLASSES[$key])) {
			throw new RuntimeException("Unknown migration key: {$key}");
		}

		$migrationClass = Migrations::MIGRATION_CLASSES[$key];

		$migration = new $migrationClass();

		return $this->cache[$key] = $migration->isApplied();
	}

	public function getAppliedStatus(): array {
		$status = [];

		foreach (Migrations::MIGRATIONS as $migration) {
			$status[$migration] = $this->check($migration);
		}

		return $status;
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

	public function isMigrationApplied(string $migration): bool {
		if (array_key_exists($migration, $this->appliedCache)) {
			return $this->appliedCache[$migration];
		}

		if (!isset(Migrations::MIGRATION_CLASSES[$migration])) {
			throw new RuntimeException("Unknown migration: {$migration}");
		}

		if (!$this->isMigrationCompleted($migration)) {
			return $this->appliedCache[$migration] = false;
		}

		$migrationClass = Migrations::MIGRATION_CLASSES[$migration];

		$migrationInstance = new $migrationClass();

		return $this->appliedCache[$migration] =
			$migrationInstance->verify();
	}

	public function getMigrationStates(): array {
		$states = [];

		foreach (Migrations::MIGRATIONS as $migration) {
			$states[$migration] = $this->getMigrationState($migration);
		}

		return $states;
	}

	public function setMigrationTableExists(bool $exists): void {
		$this->migrationTableExists = $exists;
	}

	public function hasMigrationTable(): bool {
		return $this->migrationTableExists;
	}

}
