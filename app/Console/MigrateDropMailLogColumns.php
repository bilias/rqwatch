<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\LockableTrait;

use App\Inventory\Migrations;

#[AsCommand(
	name: 'db:migrate_drop_mail_log_columns',
	description: 'Drop the migrated symbols, fuzzy_hashes and headers columns from mail_logs',
	help: 'This command drops the symbols, fuzzy_hashes and headers columns
from the mail_logs table. Their data lives in mail_log_data and the
application reads it from there only.

Run it manually, in a maintenance window. It is not part of db:migrate and
it is not a required migration, so the application runs with or without it.

DROP COLUMN rebuilds mail_logs: writes are blocked for the duration and the
server needs free space for a second copy of the table. The drop is not
reversible -- verify mail_log_data coverage first, see docs/DB_DROP_COLUMNS.md
',
)]
class MigrateDropMailLogColumns extends MigrateCliCommand
{
	private string $app_name = "db:migrate_drop_mail_log_columns";
	private const string MIGRATION = Migrations::DROP_MAIL_LOG_COLUMNS;
	private const int BATCH = Migrations::MIGRATION_BATCH[self::MIGRATION];
	private const int SLEEP = Migrations::MIGRATION_SLEEP[self::MIGRATION];

	use LockableTrait;

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		if (!$this->lock()) {
			$output->writeln('<comment>Already running in another process.</comment>');
			$this->fileLogger->warning("{$this->app_name} Already running in another process");
			return Command::FAILURE;
		}

		// run the migration
		$migration = $this->createMigration(self::MIGRATION);

		if (!$migration->run(self::BATCH, self::SLEEP, false, $output)) {
			return Command::FAILURE;
		}

		return Command::SUCCESS;
	}

}
