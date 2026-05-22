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

use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Helper\QuestionHelper;

use App\Utils\Helper;

use Psr\Log\LoggerInterface;

use Illuminate\Database\Capsule\Manager as Capsule;

#[AsCommand(
	name: 'mail:migrate_mail_recipients',
	description: 'Migrate mail recipients from mail_logs/rcpt_to to mail_log_recipients',
	help: 'This command migrates mail recipients from mail_logs/rcpt_to to mail_log_recipients
',
)]
class MigrateMailRecipients extends RqwatchCliCommand
{
	private string $app_name = "mail:migrate_mail_recipients";
	private ?LoggerInterface $fileLogger;
	private ?LoggerInterface $syslogLogger;
	private $default_batch_size = 10000;
	// micro seconds (default 1/5 of a second)
	private $default_sleep = 200000;

	use LockableTrait;

	public function __construct(
		LoggerInterface $fileLogger,
		LoggerInterface $syslogLogger,
		Capsule $capsule
	) {
		// set command name
		//parent::__construct($this->app_name);
		parent::__construct();

		$this->fileLogger = $fileLogger;
		$this->syslogLogger = $syslogLogger;
		$this->capsule = $capsule;
	}

	#[\Override]
	protected function configure(): void {
		$this
			// ->addArgument('param', InputArgument::REQUIRED, 'Parameter for service')
			->addOption('batch', 'b', InputOption::VALUE_OPTIONAL, 'Batch size', $this->default_batch_size)
			->addOption('sleep', 's', InputOption::VALUE_OPTIONAL, 'Microseconds to sleep between each batch', $this->default_sleep)
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

		$output->writeln("<comment>Starting migration of mail_log recipients in batches of {$batch}</comment>");
		$output->writeln("<comment>This will take some time, please be patient</comment>");
		$output->write("<info>Total recipients: </info>");

		$baseQuery = $this->capsule::table($_ENV['MAILLOGS_TABLE'] . ' as ml')
			->select('ml.id', 'ml.rcpt_to', 'r.mail_log_id')
			->leftJoin($_ENV['MAIL_RECIPIENTS_TABLE'] . ' as r', 'r.mail_log_id', '=', 'ml.id');
			//->whereNull('r.mail_log_id');
			//->where('ml.rcpt_to', '!=', 'unknown');

		$total = (clone $baseQuery)->count('ml.id');

		$unknown = (clone $baseQuery)
							->where('ml.rcpt_to', '=', 'unknown')
							->count('ml.id');

		$total = $total - $unknown;
		$output->writeln("<info>{$total}</info>");
		if ($total == 0) {
			return Command::SUCCESS;
		}

		$lastId = 0;
		$scanned = 0;
		$migrated = 0;

		while (true) {
			$query = (clone $baseQuery)
				->where('ml.id', '>', $lastId)
				->orderBy('ml.id')
				->limit($batch);

			/*
			$sql = $query->toSql();
			$bindings = implode(', ', $query->getBindings());
			$output->writeln("SQL iteration, lastId={$lastId}: {$sql} | Bindings: {$bindings}");
			*/
			$logs = $query->get();

			if ($logs->isEmpty()) {
				break;
			}

			$batchRows = [];
			$inserted = 0;
			foreach ($logs as $log) {

				// already migrated mail_log id
				if ($log->mail_log_id !== null) {
					continue;
				}

				/*
				$rcptTo = trim(strtolower((string)$log->rcpt_to));
				if ($rcptTo === '' || $rcptTo === 'unknown') {
					continue;
				}

				$recipients = array_unique(array_filter(
					array_map(
						static fn(string $email) => trim(strtolower($email)),
						explode(',', $log->rcpt_to)
					)
				));
				*/

				if ($log->rcpt_to === null || $log->rcpt_to === '') {
					continue;
				}

				$recipients = [];
				foreach (explode(',', $log->rcpt_to) as $email) {
					$email = strtolower(trim($email));
					if ($email === '' || $email === 'unknown') {
						continue;
					}

					$recipients[$email] = true;
				}

				$recipients = array_keys($recipients);

				if (empty($recipients)) {
					continue;
				}

				//$rows = [];
				foreach ($recipients as $email) {
					// $rows[] = [
					$batchRows[] = [
						'mail_log_id'     => $log->id,
						'recipient_email' => $email,
					];
				}

				//$this->capsule->getConnection()->beginTransaction();

				/*
				try {
					$inserted += $this->capsule::table($_ENV['MAIL_RECIPIENTS_TABLE'])->insertOrIgnore($rows);
					//$this->capsule->getConnection()->commit();
				} catch (\Exception $e) {
					//$this->capsule->getConnection()->rollBack();
					throw $e;
				}
				*/
			}

			// insert in batches
			if (!empty($batchRows)) {
				try {
					$inserted += $this->capsule::table($_ENV['MAIL_RECIPIENTS_TABLE'])->insertOrIgnore($batchRows);
				} catch (\Exception $e) {
					throw $e;
				}
			}

			$lastId = $logs->last()->id;
			$scanned += $logs->count();
			$migrated += $inserted;
			$remaining = max(0, $total - $scanned);
			$output->writeln("<info>Scanned: {$scanned}, Remaining: {$remaining}, Migrated: {$migrated} (recipients)</info>"
);
			usleep($sleep);
		}

		return Command::SUCCESS;
	}

}
