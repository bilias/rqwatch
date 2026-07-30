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

	abstract public function getName(): string;
	abstract protected function verify(): bool;

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

	protected function hasMigration(): bool {
		return $this->capsule
			->table(AppConfig::MIGRATIONS_TABLE)
			->where('migration', $this->getName())
			->exists();
	}

	public function verifyMigration(): bool {
		$recorded = $this->hasMigration();
		$verified = $this->verify();

		if ($recorded && $verified) {
			return true;
		}

		$this->fileLogger?->warning(
			"DB Migration {$this->getName()} requires manual execution"
		);
		$this->fileLogger?->warning(
			"See: " . MigrationList::MIGRATION_HELP[$this->getName()]
		);

		return false;
	}

	protected function recordMigration(): void {
		$this->capsule
			->table(AppConfig::MIGRATIONS_TABLE)
			->insert([
				'migration' => $this->getName(),
			]);
	}

	protected function ensureMigrationsTable(): void {
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
				$table->increments('id');
				$table->string('migration', 255)->unique();
				$table->timestamp('executed_at')->useCurrent();
			}
		);
	}

}
