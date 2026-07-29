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

use RuntimeException;

class MigrationRunner {

	public const string MIGRATION_MAIL_RECIPIENTS = '20260729_migrate_mail_recipients';
	public const string MIGRATION_ADD_CREATED_DAY = '20260729_add_created_day';

	// put migration constants bellow to enable migration
	public const array MIGRATIONS = [
		self::MIGRATION_MAIL_RECIPIENTS,
		self::MIGRATION_ADD_CREATED_DAY,
	];

	public function __construct(
		private ?LoggerInterface $fileLogger,
		private Capsule $capsule
	) {}

	public function check(): void {
		$this->ensureMigrationsTable();

		$executedMigrations = array_flip($this->getExecutedMigrations());

		foreach (self::MIGRATIONS as $migration) {
			if (!array_key_exists($migration, $executedMigrations)) {
				$this->verifyMigration($migration);
			}
		}
	}

	private function hasTable($table): bool {
		return $this->capsule->schema()->hasTable($table);
	}

	private function ensureMigrationsTable(): void {
		if (!$this->hasTable(AppConfig::MIGRATIONS_TABLE)) {
			$this->fileLogger->warning("DB Schema does not have 'MIGRATIONS_TABLE', creating it.");
			$this->createMigrationsTable();
		}
	}

	private function createMigrationsTable(): void {
		$this->capsule->schema()->create(AppConfig::MIGRATIONS_TABLE, function (Blueprint $table) {
			$table->increments('id');
			$table->string('migration', 255)->unique();
			$table->timestamp('executed_at')->useCurrent();
		});
	}

	public function hasMigration(string $name): bool {
		return $this->capsule
			->table(AppConfig::MIGRATIONS_TABLE)
			->where('migration', $name)
			->exists();
	}

	private function getExecutedMigrations(): array {
		return $this->capsule
			->table(AppConfig::MIGRATIONS_TABLE)
			->pluck('migration')
			->toArray();
	}

	private function verifyMigration(string $migration): void {
		switch ($migration) {
			// special case
			case self::MIGRATION_MAIL_RECIPIENTS:
				$this->verifyMailRecipientsMigration(self::MIGRATION_MAIL_RECIPIENTS);
				break;
			case self::MIGRATION_ADD_CREATED_DAY:
				$this->verifyCreatedDayMigration(self::MIGRATION_ADD_CREATED_DAY);
				break;
			default:
				throw new RuntimeException("Unknown migration: $migration");
		}
	}

	private function recordMigration(string $name): void {
		$this->capsule
			->table(AppConfig::MIGRATIONS_TABLE)
			->insert([
				'migration' => $name,
			]);
	}

	private function verifyMailRecipientsMigration(string $migration): void {
		if (!$this->hasTable(AppConfig::MAIL_LOG_RECIPIENTS_TABLE)) {
			$this->fileLogger->warning("DB Migration $migration requires manual execution");
			$this->fileLogger->info("See: https://github.com/bilias/rqwatch/blob/master/docs/MAIL_RECIPIENTS_UPDATE.md");
		} else {
			$this->fileLogger->info("DB Migration $migration already completed, recording status.");
			$this->recordMigration($migration);
		}
	}

	private function verifyCreatedDayMigration(string $migration): void {
		if (!$this->capsule->schema()->hasColumn(
			AppConfig::MAIL_LOGS_TABLE,
			'created_day'
		)) {
			$this->fileLogger->warning("DB Migration $migration requires manual execution");
			$this->fileLogger->info("See: https://github.com/bilias/rqwatch/blob/master/docs/CREATED_DAY_UPDATE.md");
		} else {
			$this->fileLogger->info("DB Migration $migration already completed, recording status.");
			$this->recordMigration($migration);
		}
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
