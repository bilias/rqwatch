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
			'mail_recipients',
			MailRecipientsMigration::class
		);
	}

	public function hasMailLogData(): bool {
		return $this->check(
			'mail_log_data',
			MailLogDataMigration::class
		);
	}

	private function check(string $key, string $migrationClass): bool {
		if (array_key_exists($key, $this->cache)) {
			return $this->cache[$key];
		}

		$migration = new $migrationClass(
			$this->capsule,
			$this->fileLogger
		);

		return $this->cache[$key] = $migration->isApplied();
	}

}
