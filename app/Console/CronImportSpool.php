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

use App\Services\Import\MailLogSpool;

#[AsCommand(
	name: 'cron:import_spool',
	description: 'Import spooled mail metadata',
	help: 'This command imports mail metadata that the API spooled to Redis
because the database insert failed, and removes it from the spool once
imported. Run it with -l on each API server: only the server that
received a mail can check that its raw file is still on disk.
',
)]
class CronImportSpool extends RqwatchCliCommand
{
	private string $app_name = "cron:import_spool";

	private const int BATCH = 500;

	use LockableTrait;

	#[\Override]
	protected function configure(): void {
		$this
			->addOption('import', 'i', InputOption::VALUE_NONE, 'Import spooled entries into the database')
			->addOption('batch', 'b', InputOption::VALUE_OPTIONAL, 'Batch size', self::BATCH)
			->addOption('local', 'l', InputOption::VALUE_NONE, 'Only entries spooled by the local server')
			->addOption('show', 's', InputOption::VALUE_NONE, 'Show spooled entries')
		;
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		/*
		 Two passes draining the same keys would import the same mail
		 twice: drain() deletes a key only after its insert commits.
		*/
		if (!$this->lock()) {
			$output->writeln('<comment>Already running in another process.</comment>');
			$this->fileLogger->warning("{$this->app_name} Already running in another process");
			return Command::FAILURE;
		}

		$do_import = $input->getOption('import');
		$show_spool = $input->getOption('show');
		$local_only = $input->getOption('local');

		// VALUE_OPTIONAL yields null for a bare --batch, so the declared
		// default does not apply; normalise before any consumer.
		$batch = (int) $input->getOption('batch');
		if ($batch < 1) {
			$batch = self::BATCH;
		}

		$spool = new MailLogSpool();

		$local = '';
		if ($local_only) {
			$local = " on server: " . ($_ENV['MY_API_SERVER_ALIAS'] ?? 'unknown');
		}

		$pending = $spool->pending($local_only);

		if ($pending < 1) {
			$output->writeln("<info>No spooled entries found{$local}</info>",
				OutputInterface::VERBOSITY_VERBOSE);
			$this->fileLogger->debug("{$this->app_name} No spooled entries found{$local}");
			$this->printRuntime($output);
			return Command::SUCCESS;
		}

		$output->writeln("<info>{$pending} spooled entries found{$local}</info>",
			OutputInterface::VERBOSITY_VERBOSE);
		$this->fileLogger->info("{$this->app_name} {$pending} spooled entries found{$local}");

		if ($show_spool) {
			$output->writeln("<comment>Spooled entries pending import{$local}:</comment>",
				OutputInterface::VERBOSITY_NORMAL);
			$spool->drain($local_only, $batch, true, $output);
		}

		if (!$do_import) {
			$output->writeln("<info>Use -i to import spooled entries{$local}</info>",
				OutputInterface::VERBOSITY_VERBOSE);
			$this->printRuntime($output);
			return Command::SUCCESS;
		}

		$result = $spool->drain($local_only, $batch, false, $output);

		$output->writeln(
			"<info>{$result['inserted']} imported, {$result['skipped']} skipped"
			. " of {$result['found']} spooled{$local}</info>",
			OutputInterface::VERBOSITY_VERBOSE
		);
		$this->fileLogger->info(
			"{$this->app_name} {$result['inserted']} imported, {$result['skipped']} skipped"
			. " of {$result['found']} spooled{$local}"
		);

		/*
		 A stopped pass means the database refused an insert, so the rest
		 stays spooled for the next run. Report FAILURE so a crontab
		 MAILTO or a monitor notices, rather than exiting 0 on a pass that
		 imported nothing.
		*/
		if ($result['stopped']) {
			$output->writeln("<comment>Import stopped early; remaining entries stay spooled{$local}</comment>",
				OutputInterface::VERBOSITY_NORMAL);
			$this->printRuntime($output);
			return Command::FAILURE;
		}

		$this->printRuntime($output);
		return Command::SUCCESS;
	}

}
