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
use Illuminate\Database\Schema\Blueprint;

use Psr\Log\LoggerInterface;

use App\Configuration\AppConfig;
use App\Configuration\MigrationList;

use Closure;
use RuntimeException;

abstract class AbstractMigration {

	public function __construct(
		protected Capsule $capsule,
		protected ?LoggerInterface $fileLogger
	) {}

	public function getName(): string {
		throw new RuntimeException('Migration getName() not implemented');
	}

	public function verify(): bool {
		throw new RuntimeException('Migration verify() not implemented');
	}

	protected function hasTable(string $table): bool {
		return $this->capsule->schema()->hasTable($table);
	}

	protected function hasColumn(string $table, string $column): bool {
		return $this->capsule->schema()->hasColumn($table, $column);
	}

	protected function createTable(string $tableName, Closure $callback): void {
		$this->capsule->schema()->create($tableName, $callback);
	}

	protected function alterTable(string $tableName, Closure $callback): void {
		$this->capsule->schema()->table($tableName, $callback);
	}

	protected function hasMigration(string $name): bool {
		return $this->capsule
			->table(AppConfig::MIGRATIONS_TABLE)
			->where('migration', $name)
			->exists();
	}

	protected function recordMigration(string $migration): void {
		$this->capsule
			->table(AppConfig::MIGRATIONS_TABLE)
			->insert([
				'migration' => $migration,
			]);
	}

	protected function ensureMigrationsTable(): void {
		if (!$this->hasTable(AppConfig::MIGRATIONS_TABLE)) {
			$this->fileLogger->warning("DB Schema does not have 'MIGRATIONS_TABLE', creating it.");
			$this->createMigrationsTable();
		}
	}

	protected function createMigrationsTable(): void {
		$this->createTable(
			AppConfig::MIGRATIONS_TABLE,
			function (Blueprint $table) {
				$table->increments('id');
				$table->string('migration', 255)->unique();
				$table->timestamp('executed_at')->useCurrent();
			}
		);
	}

	private function migrateCreatedDay(): void {
		if ($this->capsule->schema()->hasColumn(
			AppConfig::MAIL_LOGS_TABLE,
			'created_day'
		)) {
			$this->recordMigration(self::MIGRATION_ADD_CREATED_DAY);
			return;
		}

		$this->fileLogger?->info("Running migration: " . self::MIGRATION_ADD_CREATED_DAY);
		$this->capsule->getConnection()->statement("
			ALTER TABLE " . AppConfig::MAIL_LOGS_TABLE . "
			ADD COLUMN created_day DATE
				AS (DATE(created_at)) STORED
				AFTER updated_at,
			ADD INDEX created_day_index (created_day)
		");

		$this->fileLogger?->info("Migration: " . self::MIGRATION_ADD_CREATED_DAY . " finished");
		$this->recordMigration(self::MIGRATION_ADD_CREATED_DAY);
	}

}
