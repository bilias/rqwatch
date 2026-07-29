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

	public const string MIGRATE_MAIL_RECIPIENTS = '20260729_migrate_mail_recipients';

	public const array MIGRATIONS = [
		self::MIGRATE_MAIL_RECIPIENTS,
	];

	public function __construct(
		private ?LoggerInterface $fileLogger,
		private Capsule $capsule
	) {}

	public function check(): void {
		$this->ensureMigrationsTable();

		foreach (self::MIGRATIONS as $migration) {
			if (!$this->hasMigration($migration)) {
				$this->verifyMigration($migration);
			}
		}
	}

	private function hasTable($table): bool {
		return $this->capsule->schema()->hasTable($table);
	}

	private function ensureMigrationsTable(): void {
		if (!$this->hasTable('migrations')) {
			$this->fileLogger->warning("DB Schema does not have 'migrations' table, creating it.");
			$this->createMigrationsTable();
		}
	}

	private function createMigrationsTable(): void {
		$this->capsule->schema()->create('migrations', function (Blueprint $table) {
			$table->increments('id');
			$table->string('migration', 255)->unique();
			$table->timestamp('executed_at')->useCurrent();
		});
	}

	public function hasMigration(string $name): bool {
		return $this->capsule
			->table('migrations')
			->where('migration', $name)
			->exists();
	}

	private function verifyMigration(string $migration): void {
		switch ($migration) {
			// special case
			case self::MIGRATE_MAIL_RECIPIENTS :
				if (!$this->hasTable(AppConfig::MAIL_LOG_RECIPIENTS_TABLE)) {
					$this->fileLogger->warning("DB Migration '$migration' requires manual execution");
					$this->fileLogger->info("See: https://github.com/bilias/rqwatch/blob/master/docs/MAIL_RECIPIENTS_UPDATE.md");
				} else {
					$this->fileLogger->info("DB Migration '$migration' already completed, recording status.");
					$this->recordMigration($migration);
				}
				break;
			default:
				throw new RuntimeException("Unknown migration: $migration");
		}
	}

	private function recordMigration(string $name): void {
		$this->capsule
			->table('migrations')
			->insert([
				'migration' => $name,
			]);
	}

}
