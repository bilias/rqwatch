<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Console;

//use App\Console\RqwatchCliCommand;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\LockableTrait;

use App\Inventory\Migrations;

#[AsCommand(
	name: 'db:migrate_mail_log_tokens',
	description: 'Add creates mail_log_tokens table',
	help: 'This command creates mail_log_tokens table
',
)]
class MigrateMailLogTokens extends MigrateCliCommand
{
	private string $app_name = "db:mail_log_tokens";
	private const string MIGRATION = Migrations::MAIL_LOG_TOKENS;
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
