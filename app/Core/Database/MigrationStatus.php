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
use App\Configuration\Config;

use App\Core\Cache\CacheInterface;

use App\Inventory\Migrations;

use Illuminate\Database\QueryException;

use Psr\Log\LoggerInterface;

use RuntimeException;
use Throwable;

final class MigrationStatus
{
	// migration status/state cache
	private array $stateCache = [];

	private bool $cacheLoaded = false;

	private bool $migrationTableExists = false;

	/*
	 The cache is injected rather than fetched from App::cache(): this is
	 constructed in Kernel::boot() before initApp(), so App::instance()
	 would still throw. Nullable because Redis is optional.
	*/
	public function __construct(
		private Capsule $capsule,
		private LoggerInterface $fileLogger,
		private ?CacheInterface $cache = null
	) {}

	// Kernel calls this. If a REQUIRED migration is not complete we throw.
	// Even new mails are not accepted until the migration is completed.
	public function verifyRequiredMigrations(): void {
		foreach (Migrations::REQUIRED as $migration) {
			if (!$this->isMigrationCompleted($migration)) {
				throw new RuntimeException(
					"Required migration '{$migration}' is not completed. See: "
					. Migrations::MIGRATION_HELP[$migration]
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

			$this->persistState();
		} catch (QueryException $e) {
			if ($e->getCode() === '42S02') {
				// Table disappeared between schema verification and here.
				// Degrade to the "no migrations table" path rather than
				// re-querying on every getMigrationState() call.
				$this->fileLogger->error(
					AppConfig::MIGRATIONS_TABLE .
					" vanished while warming the migration state cache"
				);

				$this->stateCache = [];
				$this->migrationTableExists = false;

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

	/*
	 Read-only status accessors for consumers (services, controllers,
	 importers). These read stateCache, warmed once at boot and synced by
	 AbstractMigration::recordMigrationStatus(), so they cost no query.
	 The migration runner does not use these -- see AbstractMigration.
	*/

	public function getMigrationState(string $migration): ?string {
		if (!in_array($migration, Migrations::MIGRATIONS, true)) {
			throw new RuntimeException("Unknown migration : {$migration}");
		}

		if (!$this->cacheLoaded) {
			$this->warmCache();
		}

		return $this->stateCache[$migration] ?? null;
	}

	/*
	 Mirror the migration state to Redis after a successful read, so it
	 stays readable when the database is not.

	 Written only on the success path: the no-migrations-table early
	 return and the 42S02 branch must not overwrite a good copy with an
	 empty one. An empty-but-readable table IS written, though - that is
	 "nothing is recorded", not "we do not know", and a stale copy
	 claiming completion is the one thing a future reader must never see.

	 Write-only for now. Any future reader must consult this ONLY after a
	 database read has failed - treating it as a substitute for a live
	 read would defeat Kernel::verifyRequiredMigrations(), and it is a
	 status cache, never a schema cache: it cannot tell you whether the
	 tables still exist.
	*/
	private function persistState(): void {
		if ($this->cache === null) {
			return;
		}

		$alias = rawurlencode(trim((string) ($_ENV['MY_API_SERVER_ALIAS'] ?? 'unknown')));
		$key = (string) Config::get('migration_status_redis_key') . ':' . $alias;

		try {
			// JSON_FORCE_OBJECT so an empty state is "{}" and not "[]"
			// the shape stays the same whatever the table contains
			$payload = json_encode($this->stateCache, JSON_FORCE_OBJECT);

			if ($payload === false) {
				return;
			}

			$this->cache->set($key, $payload);
		} catch (Throwable $e) {
			// diagnostics must never break the boot they are diagnosing
			$this->fileLogger->warning(
				"Cannot persist migration status to Redis: " . $e->getMessage()
			);
		}
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

	public function ipCreatedDayIndexCompleted(): bool {
		return $this->isMigrationCompleted(Migrations::IP_CREATED_DAY_INDEX);
	}

	public function mailLogTokensCompleted(): bool {
		return $this->isMigrationCompleted(Migrations::MAIL_LOG_TOKENS);
	}

	public function dropMailLogColumnsCompleted(): bool {
		return $this->isMigrationCompleted(Migrations::DROP_MAIL_LOG_COLUMNS);
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

	public function ipCreatedDayIndexRunning(): bool {
		return $this->isMigrationRunning(Migrations::IP_CREATED_DAY_INDEX);
	}

	public function dropMailLogColumnsRunning(): bool {
		return $this->isMigrationRunning(Migrations::DROP_MAIL_LOG_COLUMNS);
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
