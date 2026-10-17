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

use App\Services\MapService;
use App\Services\MapHitService;

use Throwable;

#[AsCommand(
	name: 'db:map_hits_backfill',
	description: 'Record map hits again for stored mails',
	help: 'This command records the map hits of the mails of the last 31 days, or of the
whole mail log with -a, from their stored symbols. A mail\'s existing hits are
replaced, so the result reflects the current maps and symbols. Run it after
the map hits migration, and again after changing map symbols.
',
)]
class MapHitsBackfill extends RqwatchCliCommand
{
	private string $app_name = "db:map_hits_backfill";
	private const int DAYS = 31;
	private const int BATCH = 1000;
	// between batches, in microseconds
	private const int SLEEP = 200000;

	use LockableTrait;

	#[\Override]
	protected function configure(): void {
		$this
			->addOption('run', 'r', InputOption::VALUE_NONE, 'Record the hits')
			->addOption('all', 'a', InputOption::VALUE_NONE, 'The whole mail log, not the last ' . self::DAYS . ' days')
			->addOption('batch', 'b', InputOption::VALUE_REQUIRED, 'Batch size', self::BATCH)
		;
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		if (!$this->lock()) {
			$output->writeln('<comment>Already running in another process.</comment>');
			$this->fileLogger->warning("{$this->app_name} Already running in another process");
			return Command::FAILURE;
		}

		if (!MapService::mapHitsEnabled()) {
			$output->writeln("<error>Map hits migration not completed. Run db:migrate_map_hits first</error>");
			return Command::FAILURE;
		}

		$batch = (int) $input->getOption('batch');
		if ($batch < 1) {
			$output->writeln("<error>Batch size must be a positive number</error>");
			return Command::FAILURE;
		}

		$days = $input->getOption('all') ? null : self::DAYS;
		$window = $days === null ? 'the whole mail log' : "the last {$days} days";

		$service = new MapHitService();

		try {
			$start = $service->backfillStartId($days);
			$count = $start === null ? 0 : $service->backfillCount($start, $days);
		} catch (Throwable $e) {
			$output->writeln("<error>Query error: {$e->getMessage()}</error>");
			$this->fileLogger->error("{$this->app_name} query error: " . $e->getMessage());
			return Command::FAILURE;
		}

		if ($start === null || $count < 1) {
			$output->writeln("<info>No mails in {$window}</info>", OutputInterface::VERBOSITY_VERBOSE);
			return Command::SUCCESS;
		}

		$output->writeln("<info>{$count} mails in {$window}, from id {$start}</info>", OutputInterface::VERBOSITY_VERBOSE);

		if (!$input->getOption('run')) {
			$output->writeln("<info>Use -r to record their hits</info>", OutputInterface::VERBOSITY_VERBOSE);
			$this->printRuntime($output);
			return Command::SUCCESS;
		}

		$this->fileLogger->info("{$this->app_name} started for {$count} mails in {$window}");

		$after = $start - 1;
		$done = 0;
		while (true) {
			try {
				[$last, $mails] = $service->backfillBatch($after, $batch, $days);
			} catch (Throwable $e) {
				$output->writeln("<error>Backfill stopped after id {$after}: {$e->getMessage()}</error>");
				$this->fileLogger->error("{$this->app_name} stopped after id {$after}: " . $e->getMessage());
				return Command::FAILURE;
			}
			if ($last === null) {
				break;
			}

			$done += $mails;
			$after = $last;
			$output->writeln("Processed {$done}/{$count} mails, last id {$last}", OutputInterface::VERBOSITY_VERBOSE);

			usleep(self::SLEEP);
		}

		$output->writeln("<info>{$done} mails processed</info>", OutputInterface::VERBOSITY_VERBOSE);
		$this->fileLogger->info("{$this->app_name} {$done} mails processed in {$window}");

		$this->printRuntime($output);
		return Command::SUCCESS;
	}

}
