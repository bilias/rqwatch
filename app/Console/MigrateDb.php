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
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\LockableTrait;

use App\Inventory\Migrations;

#[AsCommand(
	name: 'db:migrate',
	description: 'Perform pending database migrations',
	help: 'This command performs pending database migrations
',
)]
class MigrateDB extends MigrateCliCommand
{
	private string $app_name = "db:migrate";

	// default seconds to sleep between migrations
	private $default_sleep = 2;

	use LockableTrait;

	#[\Override]
	protected function configure(): void {
		$this
			// ->addArgument('param', InputArgument::REQUIRED, 'Parameter for service')
			// ->addOption('batch', 'b', InputOption::VALUE_OPTIONAL, 'Batch size', $this->default_batch_size)
			// ->addOption('sleep', 's', InputOption::VALUE_OPTIONAL, 'Microseconds to sleep between each batch', $this->default_sleep)
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

		$force = $input->getOption('force');

		foreach (Migrations::MIGRATIONS as $migration_str) {
			// run each migration
			$migration = $this->createMigration($migration_str);

			$migration->ensureMigrationsTable();

			// completed and verified
			if (!$force && $migration->isApplied()) {

				$name = $migration->getName();
				$descr = $migration->getDescr();
				$details = "'{$descr}' ($name)";

				$output->writeln(
					"<info>Migration $details is already applied</info>"
				);
				continue;
			}

			// take defaults from Inventory
			$batch = Migrations::MIGRATION_BATCH[$migration_str];
			$sleep = Migrations::MIGRATION_SLEEP[$migration_str];

			if (!$migration->run($batch, $sleep, $force, $output)) {
				return Command::FAILURE;
			}

			sleep($this->default_sleep);
		}

		return Command::SUCCESS;
	}

}
