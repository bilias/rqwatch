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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\LockableTrait;

use App\Inventory\Migrations;

#[AsCommand(
	name: 'db:migrate_mail_log_viruses',
	description: 'Create mail_log_viruses and fill it from the stored symbols',
	help: 'This command creates the mail_log_viruses table and records the antivirus
symbols and virus names of every virus mail from mail_log_data. Re-running it
fills only the mails that have no rows yet.
',
)]
class MigrateMailLogViruses extends MigrateCliCommand
{
	private string $app_name = "db:migrate_mail_log_viruses";
	private const string MIGRATION = Migrations::MAIL_LOG_VIRUSES;
	private const int BATCH = Migrations::MIGRATION_BATCH[self::MIGRATION];
	private const int SLEEP = Migrations::MIGRATION_SLEEP[self::MIGRATION];

	use LockableTrait;

	#[\Override]
	protected function configure(): void {
		$this
			->addOption('batch', 'b', InputOption::VALUE_REQUIRED, 'Virus mails per batch', self::BATCH)
			->addOption('sleep', 's', InputOption::VALUE_REQUIRED, 'Microseconds to sleep between each batch', self::SLEEP)
			->addOption('force', 'f', InputOption::VALUE_NONE, 'Fill missing rows even if the migration is completed')
		;
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		if (!$this->lock()) {
			$output->writeln('<comment>Already running in another process.</comment>');
			$this->fileLogger->warning("{$this->app_name} Already running in another process");
			return Command::FAILURE;
		}

		$batch = (int) $input->getOption('batch');
		$sleep = max(0, (int) $input->getOption('sleep'));
		$force = (bool) $input->getOption('force');

		$migration = $this->createMigration(self::MIGRATION);

		if (!$migration->run($batch, $sleep, $force, $output)) {
			return Command::FAILURE;
		}

		return Command::SUCCESS;
	}

}
