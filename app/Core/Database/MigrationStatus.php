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

use Psr\Log\LoggerInterface;

use App\Inventory\Migrations;
use App\Core\Database\Migrations\MailRecipientsMigration;
use App\Core\Database\Migrations\MailLogDataMigration;

use RuntimeException;

final class MigrationStatus
{
	private array $cache = [];

	public function __construct(
		private Capsule $capsule,
		private ?LoggerInterface $fileLogger
	) {}

	public function hasMailRecipients(): bool {
		return $this->check(
			Migrations::MAIL_RECIPIENTS
		);
	}

	public function hasCreatedDay(): bool {
		return $this->check(
			Migrations::ADD_CREATED_DAY
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

		$migration = new $migrationClass(
			$this->capsule,
			$this->fileLogger
		);

		return $this->cache[$key] = $migration->isApplied();
	}

	public function getMigrationStatus(): array {
		$status = [];

		foreach (Migrations::MIGRATIONS as $migration) {
			$status[$migration] = $this->check($migration);
		}

		return $status;
	}

}
