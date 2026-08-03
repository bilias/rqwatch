<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Core\Database;

use App\Configuration\AppConfig;

use App\Core\App;

use App\Inventory\Migrations;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

use Psr\Log\LoggerInterface;

use Closure;
use RuntimeException;
use InvalidArgumentException;

abstract class AbstractMigration {

	protected const MIGRATION_NAME = '';

	protected Capsule $capsule;
	protected LoggerInterface $fileLogger;

	public function __construct() {
		$this->capsule = App::capsule();
		$this->fileLogger = App::fileLogger();
	}

	abstract protected function verifySchema(): bool;

	public function verify(): bool {
		return $this->verifySchema();
	}

	public function getName(): string {
		return static::MIGRATION_NAME;
	}

	public function getDescr(): string {
		return Migrations::MIGRATION_DESCR[$this->getName()]
			?? throw new RuntimeException(
					"No description for migration '{$this->getName()}'."
				);
	}

	protected function hasTable(string $table): bool {
		return $this->capsule->schema()->hasTable($table);
	}

	protected function hasColumn(string $table, string $column): bool {
		return $this->capsule->schema()->hasColumn($table, $column);
	}

	protected function hasIndex(string $table, string $index): bool {
		return !empty(
			$this->capsule->getConnection()->select(
				"SHOW INDEX FROM `$table` WHERE Key_name = ?",
				[$index]
			)
		);
	}

	protected function createTable(string $tableName, Closure $callback): void {
		$this->capsule->schema()->create($tableName, $callback);
	}

	protected function alterTable(string $tableName, Closure $callback): void {
		$this->capsule->schema()->table($tableName, $callback);
	}

	/*
	protected function hasMigration(): bool {
		$this->ensureMigrationsTable();

		return $this->capsule
			->table(AppConfig::MIGRATIONS_TABLE)
			->where('migration', $this->getName())
			->where('status', Migrations::STATUS_COMPLETED)
			->exists();
	}
	*/

	protected function hasMigration(): bool {
		return $this->getMigrationStatus() !== null;
	}

	protected function getMigrationStatus(): ?string {
		$this->ensureMigrationsTable();

		return $this->capsule
			->table(AppConfig::MIGRATIONS_TABLE)
			->where('migration', $this->getName())
			->value('status');
	}

	protected function isMigrationRunning(): bool {
		return $this->getMigrationStatus() === Migrations::STATUS_RUNNING;
	}

	protected function isMigrationCompleted(): bool {
		return $this->getMigrationStatus() === Migrations::STATUS_COMPLETED;
	}

	protected function isMigrationFailed(): bool {
		return $this->getMigrationStatus() === Migrations::STATUS_FAILED;
	}

	public function verifyMigration(): bool {
		if ($this->isApplied()) {
			return true;
		}

		$this->fileLogger->warning(
			"DB Migration {$this->getName()} requires manual execution"
		);
		$this->fileLogger->warning(
			"See: " . Migrations::MIGRATION_HELP[$this->getName()]
		);

		return false;
	}

	public function isApplied(): bool {
		return $this->isMigrationCompleted() && $this->verifySchema();
	}

	protected function recordMigrationStatus(string $status): void {
		$this->ensureMigrationsTable();

		if (!in_array($status, Migrations::STATUSES, true)) {
			throw new InvalidArgumentException(
				"Unknown migration status '{$status}'."
			);
		}

		$data = [
			'migration'   => $this->getName(),
			'status'      => $status,
			'status_at' => date('Y-m-d H:i:s'),
		];

		$this->capsule
			->table(AppConfig::MIGRATIONS_TABLE)
			->upsert(
				[ $data ],
				[ 'migration' ],
				[ 'status', 'status_at' ]
			);
	}

	public function ensureMigrationsTable(): void {
		if (!$this->hasTable(AppConfig::MIGRATIONS_TABLE)) {
			$this->fileLogger->warning("DB Schema does not have 'MIGRATIONS_TABLE', creating it.");
			$this->createMigrationsTable();
		}

		if (!$this->hasTable(AppConfig::MIGRATIONS_TABLE)) {
			throw new RuntimeException(
				"Failed to create " . AppConfig::MIGRATIONS_TABLE . " table"
			);
		}
	}

	protected function createMigrationsTable(): void {
		$this->createTable(
			AppConfig::MIGRATIONS_TABLE,
			function (Blueprint $table) {
				$table->string('migration', 255)->primary();
				$table->string('status', 32)->default(Migrations::STATUS_PENDING);
				$table->timestamp('status_at')->useCurrent();
			}
		);
	}

}
