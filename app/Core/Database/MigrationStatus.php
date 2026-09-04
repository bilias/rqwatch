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

	private bool $cacheLoaded = false;

	private bool $migrationTableExists = false;

	public function __construct(
		private Capsule $capsule,
		private LoggerInterface $fileLogger
	) {}

	public function verifyRequiredMigrations(): void {
		foreach (Migrations::REQUIRED as $migration) {
			if (!$this->isMigrationCompleted($migration)) {
				throw new RuntimeException(
					"Required migration '{$migration}' is not completed."
				);
			}
		}
	}

	public function warmCache(): void {
		if ($this->cacheLoaded) {
			return;
		}

		if (!$this->migrationTableExists) {
			return;
		}

		try {
			$this->stateCache = $this->capsule
				->table(AppConfig::MIGRATIONS_TABLE)
				->pluck('status', 'migration')
				->all();

			$this->cacheLoaded = true;
		} catch (QueryException $e) {
			if ($e->getCode() === '42S02') {
				// Table does not exist (or disappeared)
				$this->stateCache = [];
				return;
			}

			throw $e;
		}
	}

	public function setMigrationState(string $migration, ?string $status): void {
		if (!in_array($migration, Migrations::MIGRATIONS, true)) {
			throw new RuntimeException("Unknown migration: {$migration}");
		}

		$this->stateCache[$migration] = $status;
	}

	public function getMigrationState(string $migration): ?string {
		if (!in_array($migration, Migrations::MIGRATIONS, true)) {
			throw new RuntimeException("Unknown migration : {$migration}");
		}

		if (!$this->cacheLoaded) {
			$this->warmCache();
		}

		return $this->stateCache[$migration] ?? null;
	}

	public function mailLogDataState(): ?string {
		return $this->getMigrationState(Migrations::MAIL_LOG_DATA);
	}

	public function isMigrationRunning(string $migration): bool {
		return $this->getMigrationState($migration)
			=== Migrations::STATUS_RUNNING;
	}

	public function isMigrationCompleted(string $migration): bool {
		return $this->getMigrationState($migration)
			=== Migrations::STATUS_COMPLETED;
	}

	public function mailRecipientsCompleted(): bool {
		return $this->isMigrationCompleted(Migrations::MAIL_RECIPIENTS);
	}

	public function mailLogDataCompleted(): bool {
		return $this->isMigrationCompleted(Migrations::MAIL_LOG_DATA);
	}

	public function createdDayCompleted(): bool {
		return $this->isMigrationCompleted(Migrations::CREATED_DAY);
	}

	public function idActionIndexCompleted(): bool {
		return $this->isMigrationCompleted(Migrations::ID_ACTION_INDEX);
	}

	public function mailLogTokensCompleted(): bool {
		return $this->isMigrationCompleted(Migrations::MAIL_LOG_TOKENS);
	}

	public function mailLogDataRunning(): bool {
		return $this->isMigrationRunning(Migrations::MAIL_LOG_DATA);
	}

	public function mailRecipientsRunning(): bool {
		return $this->isMigrationRunning(Migrations::MAIL_RECIPIENTS);
	}

	public function createdDayRunning(): bool {
		return $this->isMigrationRunning(Migrations::CREATED_DAY);
	}

	public function idActionIndexRunning(): bool {
		return $this->isMigrationRunning(Migrations::ID_ACTION_INDEX);
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
