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

use RuntimeException;

class MigrationRunner extends AbstractMigration {

	public function check(array $migrations): void {
		$this->ensureMigrationsTable();

		$executedMigrations = array_flip($this->getExecutedMigrations());

		foreach ($migrations as $migration) {
			if (!array_key_exists($migration, $executedMigrations)) {
				$this->verifyMigration($migration);
			}
		}
	}

	private function getExecutedMigrations(): array {
		return $this->capsule
			->table(AppConfig::MIGRATIONS_TABLE)
			->pluck('migration')
			->toArray();
	}

	private function verifyMigration(string $migration): void {
		if (!array_key_exists($migration, MigrationList::MIGRATION_CLASSES)) {
			throw new RuntimeException("Unknown migration: $migration");
		}

		$class = MigrationList::MIGRATION_CLASSES[$migration];
		if (!is_subclass_of($class, AbstractMigration::class)) {
			throw new RuntimeException("$class is not a valid migration");
		}

		$instance = new $class($this->capsule, $this->fileLogger);
		$instance->verify();
	}

}
