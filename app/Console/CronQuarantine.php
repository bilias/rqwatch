<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Console;

use App\Console\RqwatchCliCommand;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\LockableTrait;

use App\Core\Config;
use App\Utils\Helper;
use App\Services\MailLogService;

use Psr\Log\LoggerInterface;

use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsCommand(
	name: 'cron:quarantine',
	description: 'Clear Quarantine',
	help: 'This command scans the Rqwatch database and cleans the Quarantine.
',
)]
class CronQuarantine extends RqwatchCliCommand
{
	private string $app_name = "cron:quarantine";
	private ?LoggerInterface $fileLogger = null;
	private ?LoggerInterface $syslogLogger = null;

	use LockableTrait;

	public function __construct(LoggerInterface $fileLogger, LoggerInterface $syslogLogger) {
		// set command name
		//parent::__construct($this->app_name);
		parent::__construct();

		$this->fileLogger = $fileLogger;
		$this->syslogLogger = $syslogLogger;
	}

	protected function configure(): void {
		$this
			// ->addArgument('param', InputArgument::REQUIRED, 'Parameter for service')
			->addOption('delete', 'd', InputOption::VALUE_NONE, 'Delete entries from quarantine')
			->addOption('local', 'l', InputOption::VALUE_NONE, 'Clean quarantine for local server only')
			->addOption('show', 's', InputOption::VALUE_NONE, 'Show entries in quaranting pending to be deleted')
		;
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if (!$this->lock()) {
			$output->writeln('<comment>Already running in another process.</comment>');
			$this->fileLogger->warning("{$this->app_name} Already running in another process");
			return Command::FAILURE;
		}

		//$param = $input->getArgument('param');
		$delete_quarantine = $input->getOption('delete');
		$show_local_only = $input->getOption('local');
		$show_quaranatine = $input->getOption('show');

		$service = new MailLogService($this->fileLogger);

		// MailLog Collection
		$local = '';
		if ($show_local_only) {
			$server = $_ENV['MY_API_SERVER_ALIAS'];
			$logs = $service->getQuarantine($output, $server);
			$local = " on server: {$server}";
		} else {
			$logs = $service->getQuarantine($output);
		}

		$days = (int) ($_ENV['QUARANTINE_DAYS'] ?? 365);
		$cutoffDate = new \DateTime();
		$cutoffDate->sub(new \DateInterval("P{$days}D")); // Subtract days
		$qtime = $cutoffDate->format('Y-m-d H:i:s');

		if (($count = count($logs)) < 1) {
			$output->writeln("<info>No entries found in quarantine before {$qtime}{$local}</info>",
				OutputInterface::VERBOSITY_VERBOSE);
			$this->fileLogger->debug("{$this->app_name} No entries found in quarantine before {$qtime} {$local}");
			$this->printRuntime($output);
			return Command::SUCCESS;
		} else {
			$output->writeln("<info>{$count} entries found in quarantin before {$qtime}{$local}</info>",
				OutputInterface::VERBOSITY_VERBOSE);
			$this->fileLogger->info("{$this->app_name} {$count} entries found in quarantin before {$qtime}{$local}");
		}

		// identify empty mail_location logs. Just to track their id for debugging
		$removedLogs = $logs->filter(function ($log) {
			return $log->mail_location === null;
		});

		if (count($removedLogs) > 0) {
			// get the ids based on filter above
			$removedIds = $removedLogs->pluck('id')->all();
			foreach ($removedIds as $id) {
				$output->writeln("<comment>Empty `mail_location` for id: {$id}</comment>, disabling quarantine clean{$local}",
					OutputInterface::VERBOSITY_VERBOSE);
				$this->fileLogger->warning("{$this->app_name} Empty `mail_location` for id: {$id}, disabling quarantine clean{$local}");
			}
		}
		// don't need these anymore
		unset($removedIds);
		unset($removedLogs);

		// filter out empty mail_location logs
		$logs = $logs->reject(function ($log) {
			return $log->mail_location === null;
		});

		if (($count = count($logs)) < 1) {
			$output->writeln("<info>No entries remain with a valid mail_location{$local}</info>",
				OutputInterface::VERBOSITY_VERBOSE);
			$this->fileLogger->warning("{$this->app_name} No entries remain with a valid mail_location{$local}");
			$this->printRuntime($output);
			return Command::SUCCESS;
		}

		$output->writeln("<info>{$count} entries remain with a valid mail_location{$local}</info>",
			OutputInterface::VERBOSITY_VERBOSE);
		$this->fileLogger->info("{$this->app_name} {$count} entries remain with a valid mail_location{$local}");

		// Ensure indexes are sequential after reject()
		$logs = $logs->values();

		if ($show_quaranatine) {
			$output->writeln("<comment>Quarantine pending delete{$local}:</comment>",
				OutputInterface::VERBOSITY_NORMAL);
			foreach ($logs as $log) {
				$output->writeln("QID: {$log->qid}, to: {$log->rcpt_to}",
					OutputInterface::VERBOSITY_NORMAL);
			}
		}

		if (!$delete_quarantine) {
			$output->writeln("<info>Use -d to delete entries from quarantine{$local}</info>",
				OutputInterface::VERBOSITY_VERBOSE);
			$this->printRuntime($output);
			return Command::SUCCESS;
		}

		// CLEAR QUARANTINE
		$service->cleanQuarantine($logs, $output);

		//$logs_ar = $logs->toArray();

		$this->printRuntime($output);
		return Command::SUCCESS;
	}
}
