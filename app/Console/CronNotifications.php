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

use App\Core\RouteName;
use App\Core\Config;
use App\Utils\Helper;
use App\Services\MailLogService;
use App\Services\UserService;

use Psr\Log\LoggerInterface;

use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsCommand(
	name: 'cron:notifications',
	description: 'Notifications for stored mails',
	help: 'This command scans the Rqwatch database for new undelivered stored mails
and then sends notification mails to recipients.
',
)]
class CronNotifications extends RqwatchCliCommand
{
	private string $app_name = "cron:notifications";
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
			// ->setDescription('Notifications for stored mails')
			// ->addArgument('param', InputArgument::REQUIRED, 'Parameter for service')
			->addOption('local', 'l', InputOption::VALUE_NONE, 'Notifications for local server only')
			->addOption('mail', 'm', InputOption::VALUE_NONE, 'Send notification mails')
			->addOption('show', 's', InputOption::VALUE_NONE, 'Show pending notifications')
			->addOption('blacklisted', 'b', InputOption::VALUE_NONE, 'Send notifications for blacklisted mails')
		;
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		if (!$this->lock()) {
			$output->writeln('<comment>Already running in another process.</comment>');
			$this->fileLogger->warning("{$this->app_name} Already running in another process");
			return Command::FAILURE;
		}

		//$param = $input->getArgument('param');
		$send_mails = $input->getOption('mail');
		$show_mails = $input->getOption('show');
		$show_local_only = $input->getOption('local');
		$send_blacklisted = $input->getOption('blacklisted');

		$service = new MailLogService($this->fileLogger);

		// MailLog Collection
		$local = '';
		if ($show_local_only) {
			$server = $_ENV['MY_API_SERVER_ALIAS'];
			$logs = $service->getUnnotified($output, $server);
			$local = " on server: {$server}";
		} else {
			$logs = $service->getUnnotified($output);
		}

		if (($count = count($logs)) < 1) {
			$output->writeln("<info>No entries found for notitication{$local}</info>",
				OutputInterface::VERBOSITY_VERBOSE);
			$this->fileLogger->debug("{$this->app_name} No entries found for notitication{$local}");
			$this->printRuntime($output);
			return Command::SUCCESS;
		} else {
			$output->writeln("<info>{$count} entries found for notification{$local}</info>",
				OutputInterface::VERBOSITY_VERBOSE);
			$this->fileLogger->debug("{$this->app_name} {$count} entries found for notification{$local}");
		}

		// identify empty rcpt_to logs. Just to track their id for debugging
		$removedLogs = $logs->filter(function ($log) {
			//return $log->rcpt_to === 'unknown';
			// if recipients are loaded, this catches true empties too
			$rcpt = trim((string) $log->rcpt_to);
			return $rcpt === '' || $rcpt === 'unknown';
		});

		if (count($removedLogs) > 0) {
			// get the ids based on filter above
			$removedIds = $removedLogs->pluck('id')->all();
			foreach ($removedIds as $id) {
				$output->writeln("<comment>Empty `rcpt_to` for id: {$id}</comment>, disabling notification{$local}",
					OutputInterface::VERBOSITY_VERBOSE);
				$this->fileLogger->warning("{$this->app_name} Empty `rcpt_to` for id: {$id}, disabling notification{$local}");
			}
		}
		// don't need these anymore
		unset($removedIds);
		unset($removedLogs);

		// filter out empty rcpt_to logs
		$logs = $logs->reject(function ($log) {
			// return $log->rcpt_to === 'unknown';
			$rcpt = trim((string) $log->rcpt_to);
			return $rcpt === '' || $rcpt === 'unknown';
		});

		// identify blacklisted mails and remove them if send_blacklisted is not set
		// Just to track their id for debugging
		if (!$send_blacklisted) {
			$removedLogs = $logs->filter(function ($log) {
				return Helper::checkForBlacklist($log->symbols);
			});

			if (count($removedLogs) > 0) {
				// get the ids based on filter above
				$removedIds = $removedLogs->pluck('id')->all();
				foreach ($removedIds as $id) {
					$output->writeln("<comment>Blacklisted mail with id: {$id}</comment>, disabling notification{$local}",
						OutputInterface::VERBOSITY_VERBOSE);
				}
			}
			// don't need these anymore
			unset($removedIds);
			unset($removedLogs);

			// filter out blacklisted mails
			$logs = $logs->reject(function ($log) {
				return Helper::checkForBlacklist($log->symbols);
			});
		}

		// Filter out logs for users with notifications disabled
		$userService = new UserService($this->fileLogger);

		$removedLogs = $logs->filter(function ($log) use ($userService) {
			return $userService->notificationsDisabledFor($log->rcpt_to);
		});

		if ($removedLogs->isNotEmpty()) {
			foreach ($removedLogs as $log) {
				$output->writeln("<comment>Notifications disabled for recipient: {$log->rcpt_to} (mail id: {$log->id})</comment>{$local}", OutputInterface::VERBOSITY_VERBOSE);
			}
		}

		unset($removedLogs);

		$logs = $logs->reject(function ($log) use ($userService) {
			return $userService->notificationsDisabledFor($log->rcpt_to);
		});

		$notification_score = Config::get('notification_score');

		// identify empty score > $notification_score
		$removedLogs = $logs->filter(function ($log) use ($notification_score) {
			return $log->score > $notification_score;
		});

		if (count($removedLogs) > 0) {
			// get the ids based on filter above
			$removedIds = $removedLogs->pluck('id')->all();
			foreach ($removedIds as $id) {
				$output->writeln("<comment>Score higher than {$notification_score} for id: {$id}</comment>, disabling notification{$local}",
					OutputInterface::VERBOSITY_VERBOSE);
				$this->fileLogger->debug("{$this->app_name} Score higher than {$notification_score} for id: {$id}, disabling notification{$local}");
			}
		}
		// don't need these anymore
		unset($removedIds);
		unset($removedLogs);

		// filter out mails with score > $notification_score
		$logs = $logs->reject(function ($log) use ($notification_score) {
			return $log->score > $notification_score;
		});

		if (($count = count($logs)) < 1) {
			$output->writeln("<info>No entries remain for notification{$local}</info>",
				OutputInterface::VERBOSITY_VERBOSE);
			$this->fileLogger->debug("{$this->app_name} No entries remain for notification{$local}");
			$this->printRuntime($output);
			return Command::SUCCESS;
		}

		$output->writeln("<info>{$count} entries remain with a valid recipient{$local}</info>",
			OutputInterface::VERBOSITY_VERBOSE);
		$this->fileLogger->info("{$this->app_name} {$count} entries remain with a valid recipient{$local}");

		// Ensure indexes are sequential after reject()
		$logs = $logs->values();

		if ($show_mails) {
			$output->writeln("<comment>Notifications pending{$local}:</comment>",
				OutputInterface::VERBOSITY_NORMAL);
			foreach ($logs as $log) {
				$output->writeln("QID: {$log->qid}, to: {$log->rcpt_to}",
					OutputInterface::VERBOSITY_NORMAL);
			}
		}

		if (!$send_mails) {
			$output->writeln("<info>Use -m to send notification mails{$local}</info>",
				OutputInterface::VERBOSITY_VERBOSE);
			$this->printRuntime($output);
			return Command::SUCCESS;
		}

		// SEND NOTIFICATION MAILS
		$ar = [];
		foreach ($logs as $key => $log) {
			$ar[$key] = Helper::format_symbols($log->symbols, $log->score, $log->has_virus);
			$log->symbols = $ar[$key]['symbols'];
			$log->virus_found = $ar[$key]['virus_found'];

			$virus_name = '';
			if (!empty($ar[$key]['virus_found'])) {
				$log->virus_name = $ar[$key]['virus_found'];
			}
		}

		//$logs_ar = $logs->toArray();

		// get detail link url
		$routes = include __DIR__.'/../../config/routes.php';
		$context = new RequestContext();
		$context->setHost($_ENV['WEB_HOST_NOTIFICATIONS']);
		$context->setScheme($_ENV['WEB_SCHEME']);
		$context->setBaseUrl($_ENV['WEB_BASE']);
		$urlGenerator = new UrlGenerator($routes, $context);

		foreach ($logs as $log) {
			$detailurl = $urlGenerator->generate(RouteName::DETAIL->value, [
				'type' => 'id',
				'value' => $log->id,
			], UrlGeneratorInterface::ABSOLUTE_URL);

			if (empty($_ENV['MAILER_FROM'])) {
				$output->writeln("<error>MAILER_FROM is empty. Please define it in .env{$local}</error>");
				$this->syslogLogger->error("MAILER_FROM is empty. Please define it in .env{$local}");
				return Command::FAILURE;
			}

			if (!$service->notifyHtmlMail($log, $detailurl)) {
				$output->writeln("<error>Sending notification mail with QID: {$log->qid} to {$log->rcpt_to} failed{$local}</error>");
				$this->syslogLogger->error("Sending notification mail with QID: {$log->qid} to {$log->rcpt_to} failed{$local}");

				return Command::FAILURE;
			} else {
				$output->writeln("<info>Sent notification mail for QID: {$log->qid} to {$log->rcpt_to}{$local}</info>",
					OutputInterface::VERBOSITY_VERBOSE);
				$this->syslogLogger->info("Sent notification mail for QID: {$log->qid} to '{$log->rcpt_to}'{$local}");
			}
		}

		$this->printRuntime($output);
		return Command::SUCCESS;
	}
}
