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
//use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\LockableTrait;

use App\Services\MailLogService;

use Psr\Log\LoggerInterface;

use DateTime;
use DateInterval;

#[AsCommand(
	name: 'cron:cleanupdb',
	description: 'Cleanup Database',
	help: 'This command scans the Rqwatch database and cleans the Database from old records.
',
)]
class CronCleanupDb extends RqwatchCliCommand
{
	private string $app_name = "cron:cleanupdb";
	private ?LoggerInterface $fileLogger;
	private ?LoggerInterface $syslogLogger;

	use LockableTrait;

	public function __construct(LoggerInterface $fileLogger, LoggerInterface $syslogLogger) {
		// set command name
		//parent::__construct($this->app_name);
		parent::__construct();

		$this->fileLogger = $fileLogger;
		$this->syslogLogger = $syslogLogger;
	}

	#[\Override]
	protected function configure(): void {
		$this
			// ->addArgument('param', InputArgument::REQUIRED, 'Parameter for service')
			->addOption('delete', 'd', InputOption::VALUE_NONE, 'Delete entries from database')
			->addOption('local', 'l', InputOption::VALUE_NONE, 'Delete entries for local server only')
			->addOption('show', 's', InputOption::VALUE_NONE, 'Show entries to be deleted from database')
		;
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		if (!$this->lock()) {
			$output->writeln('<comment>Already running in another process.</comment>');
			$this->fileLogger->warning("{$this->app_name} Already running in another process");
			return Command::FAILURE;
		}

		$days = (int) ($_ENV['DATABASE_DAYS'] ?? 366);
		$qdays = (int) ($_ENV['QUARANTINE_DAYS']);

		if (empty($qdays) || ($days <= $qdays)) {
			$output->writeln("<comment>DATABASE_DAYS must by higher that QUARANTINE_DAYS</comment>",
				OutputInterface::VERBOSITY_NORMAL);
			return Command::SUCCESS;
		}

		//$param = $input->getArgument('param');
		$delete_db = $input->getOption('delete');
		$show_db = $input->getOption('show');
		$local_only = $input->getOption('local');

		$service = new MailLogService($this->fileLogger);

		// MailLog Collection
		$local = '';
		if ($local_only) {
			$server = $_ENV['MY_API_SERVER_ALIAS'];
			$logs = $service->getCleanUpDb($output, $server);
			$local = " on server: {$server}";
		} else {
			$logs = $service->getCleanUpDb($output);
		}

		$cutoffDate = new DateTime();
		$cutoffDate->sub(new DateInterval("P{$days}D")); // Subtract days
		$del_time = $cutoffDate->format('Y-m-d H:i:s');

		if (($count = count($logs)) < 1) {
			$output->writeln("<info>No entries found in database before {$del_time}{$local}</info>",
				OutputInterface::VERBOSITY_VERBOSE);
			$this->fileLogger->debug("{$this->app_name} No entries found in database before {$del_time} {$local}");
			$this->printRuntime($output);
			return Command::SUCCESS;
		} else {
			$output->writeln("<info>{$count} entries found in database before {$del_time}{$local}</info>",
				OutputInterface::VERBOSITY_VERBOSE);
			$this->fileLogger->info("{$this->app_name} {$count} entries found in database before {$del_time}{$local}");
		}

		if ($show_db) {
			$output->writeln("<comment>Database pending delete{$local}:</comment>",
				OutputInterface::VERBOSITY_NORMAL);
			foreach ($logs as $log) {
				$output->writeln("QID: {$log->qid}, to: {$log->rcpt_to}",
					OutputInterface::VERBOSITY_NORMAL);
			}
		}

		if (!$delete_db) {
			$output->writeln("<info>Use -d to delete entries from database{$local}</info>",
				OutputInterface::VERBOSITY_VERBOSE);
			$this->printRuntime($output);
			return Command::SUCCESS;
		}

		// DATABASE DELETE ENTRIES
		$deleted = $service->cleanDb($logs);

		$output->writeln("<info>{$deleted} entries deleted from database{$local}</info>",
			OutputInterface::VERBOSITY_VERBOSE);

		$this->printRuntime($output);
		return Command::SUCCESS;
	}

}
