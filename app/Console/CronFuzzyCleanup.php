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

use App\Configuration\Config;

use App\Services\FuzzyService;

#[AsCommand(
	name: 'cron:fuzzy_cleanup',
	description: 'Clean expired fuzzy learn records',
	help: 'This command deletes fuzzy learn records with no hit or added weight within
$fuzzy_learn_expire_days. Rspamd has dropped their hashes by then, so only the
Rqwatch records are deleted.
',
)]
class CronFuzzyCleanup extends RqwatchCliCommand
{
	private string $app_name = "cron:fuzzy_cleanup";

	use LockableTrait;

	#[\Override]
	protected function configure(): void {
		$this
			->addOption('delete', 'd', InputOption::VALUE_NONE, 'Delete expired records')
			->addOption('local', 'l', InputOption::VALUE_NONE, 'Records of the local server only')
			->addOption('show', 's', InputOption::VALUE_NONE, 'Show expired records')
		;
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		if (!$this->lock()) {
			$output->writeln('<comment>Already running in another process.</comment>');
			$this->fileLogger->warning("{$this->app_name} Already running in another process");
			return Command::FAILURE;
		}

		// updated_at comes with the hits migration, and hits are only counted after it
		if (!FuzzyService::hitsEnabled()) {
			$output->writeln("<comment>Fuzzy hits migration not completed, nothing to do</comment>",
				OutputInterface::VERBOSITY_VERBOSE);
			return Command::SUCCESS;
		}

		$days = (int) Config::get('fuzzy_learn_expire_days');
		if ($days < 1) {
			$output->writeln("<comment>\$fuzzy_learn_expire_days is 0, cleanup disabled</comment>",
				OutputInterface::VERBOSITY_VERBOSE);
			return Command::SUCCESS;
		}

		$server = $input->getOption('local') ? (string) ($_ENV['MY_API_SERVER_ALIAS'] ?? '') : null;
		$local = $server !== null ? " on server: {$server}" : '';

		$service = new FuzzyService();
		$query = $service->getExpired($days, $server);

		if (($count = (clone $query)->count()) < 1) {
			$output->writeln("<info>No fuzzy records unchanged for {$days} days{$local}</info>",
				OutputInterface::VERBOSITY_VERBOSE);
			$this->printRuntime($output);
			return Command::SUCCESS;
		}

		$output->writeln("<info>{$count} fuzzy records unchanged for {$days} days{$local}</info>",
			OutputInterface::VERBOSITY_VERBOSE);
		$this->fileLogger->info("{$this->app_name} {$count} fuzzy records unchanged for {$days} days{$local}");

		if ($input->getOption('show')) {
			foreach ((clone $query)->get() as $row) {
				$output->writeln("QID: {$row->qid} server: {$row->api_server} learned: {$row->created_at} " .
					"updated: {$row->updated_at} last hit: " . ($row->last_hit_at ?? 'never'));
			}
		}

		if (!$input->getOption('delete')) {
			$output->writeln("<info>Use -d to delete them</info>", OutputInterface::VERBOSITY_VERBOSE);
			$this->printRuntime($output);
			return Command::SUCCESS;
		}

		$deleted = $service->purgeExpired($query);

		$output->writeln("<info>{$deleted} fuzzy records deleted{$local}</info>",
			OutputInterface::VERBOSITY_VERBOSE);
		$this->fileLogger->info("{$this->app_name} {$deleted} fuzzy records deleted{$local}");

		$this->printRuntime($output);
		return Command::SUCCESS;
	}

}
