<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Console;

//use App\Console\RqwatchCliCommand;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\LockableTrait;

use App\Inventory\Migrations;

#[AsCommand(
	name: 'db:migrate_mail_log_data',
	description: 'Migrate data from mail_logs to mail_log_Data',
	help: 'This command migrates data from mail_logs to mail_log_Data
',
)]
class MigrateMailLogData extends MigrateCliCommand
{
	private string $app_name = "db:migrate_mail_log_data";
	private const string MIGRATION = Migrations::MAIL_LOG_DATA;
	private const int BATCH = Migrations::MIGRATION_BATCH[self::MIGRATION];
	private const int SLEEP = Migrations::MIGRATION_SLEEP[self::MIGRATION];

	use LockableTrait;

	public function __construct() {
		// set command name
		//parent::__construct($this->app_name);
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this
			// ->addArgument('param', InputArgument::REQUIRED, 'Parameter for service')
			->addOption('batch', 'b', InputOption::VALUE_OPTIONAL, 'Batch size', self::BATCH)
			->addOption('sleep', 's', InputOption::VALUE_OPTIONAL, 'Microseconds to sleep between each batch', self::SLEEP)
			->addOption('force', 'f', InputOption::VALUE_NONE, 'Force restart/continue migration');
		;
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		if (!$this->lock()) {
			$output->writeln('<comment>Already running in another process.</comment>');
			$this->fileLogger->warning("{$this->app_name} Already running in another process");
			return Command::FAILURE;
		}

		$batch = $input->getOption('batch');
		$sleep = $input->getOption('sleep');
		$force = $input->getOption('force');

		// run the migration
		$migration = Migrations::create(
			self::MIGRATION,
			$this->capsule,
			$this->fileLogger
		);

		if (!$migration->run($batch, $sleep, $force, $output)) {
			return Command::FAILURE;
		}

		return Command::SUCCESS;
	}

}
