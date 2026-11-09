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

use App\Core\App;

use App\Models\MailLog;
use App\Services\MailLogService;
use App\Utils\Helper;

use DateTime;
use DateInterval;
use Throwable;

#[AsCommand(
	name: 'cron:quarantine',
	description: 'Clean Quarantine',
	help: 'This command scans the Rqwatch database and cleans the Quarantine.
',
)]
final class CronQuarantine extends RqwatchCliCommand
{
	private string $app_name = "cron:quarantine";

	// Grouping dirs created by Helper::store_raw_mail() for mails with a
	// missing ("unknown") or malformed ("invalid") qid.
	private const QUARANTINE_GROUP_DIRS = ['unknown', 'invalid'];

	use LockableTrait;

	#[\Override]
	protected function configure(): void {
		$this
			// ->addArgument('param', InputArgument::REQUIRED, 'Parameter for service')
			->addOption('delete', 'd', InputOption::VALUE_NONE, 'Delete entries from quarantine')
			->addOption('local', 'l', InputOption::VALUE_NONE, 'Clean quarantine for local server only')
			->addOption('prune', 'p', InputOption::VALUE_NONE, 'Prune empty directories from quarantine, and with -d the old mail directories no row names')
			->addOption('show', 's', InputOption::VALUE_NONE, 'Show entries in quaranting pending to be deleted')
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
		$delete_quarantine = $input->getOption('delete');
		$show_local_only = $input->getOption('local');
		$prune_quaranatine = $input->getOption('prune');
		$show_quarantine = $input->getOption('show');

		$days = (int) (Helper::env_string('QUARANTINE_DAYS') ?? 366);

		// the rows past QUARANTINE_DAYS, then what no row names, then the
		// date directories either left empty
		$this->cleanOldMail($output, $days, $delete_quarantine, $show_local_only, $show_quarantine);

		if ($prune_quaranatine) {
			$this->removeUnreferencedMailDirs($output, $days, $delete_quarantine, $show_quarantine);
			$this->pruneEmptyQuarantineDateDirs($output);
		}

		$this->printRuntime($output);
		return Command::SUCCESS;
	}

	// deletes the quarantined files of the rows past QUARANTINE_DAYS (with -d) and clears their mail_stored
	private function cleanOldMail(OutputInterface $output, int $days, bool $delete_quarantine, bool $show_local_only, bool $show_quarantine): void {
		$service = new MailLogService();

		// MailLog Collection
		$local = '';
		if ($show_local_only) {
			$server = Helper::serverAlias();
			$logs = $service->getQuarantine($output, $server);
			$local = " on server: {$server}";
		} else {
			$logs = $service->getQuarantine($output);
		}

		$cutoffDate = new DateTime();
		$cutoffDate->sub(new DateInterval("P{$days}D")); // Subtract days
		$qtime = $cutoffDate->format('Y-m-d H:i:s');

		if (($count = count($logs)) < 1) {
			$output->writeln("<info>No entries found in quarantine before {$qtime}{$local}</info>",
				OutputInterface::VERBOSITY_VERBOSE);
			$this->fileLogger->debug("{$this->app_name} No entries found in quarantine before {$qtime} {$local}");
			return;
		} else {
			$output->writeln("<info>{$count} entries found in quarantine before {$qtime}{$local}</info>",
				OutputInterface::VERBOSITY_VERBOSE);
			$this->fileLogger->info("{$this->app_name} {$count} entries found in quarantine before {$qtime}{$local}");
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
			return;
		}

		$output->writeln("<info>{$count} entries remain with a valid mail_location{$local}</info>",
			OutputInterface::VERBOSITY_VERBOSE);
		$this->fileLogger->info("{$this->app_name} {$count} entries remain with a valid mail_location{$local}");

		// Ensure indexes are sequential after reject()
		$logs = $logs->values();

		if ($show_quarantine) {
			$output->writeln("<comment>Quarantine pending delete{$local}:</comment>",
				OutputInterface::VERBOSITY_NORMAL);
			foreach ($logs as $log) {
				$output->writeln("QID: {$log->qid}",
					OutputInterface::VERBOSITY_NORMAL);
			}
		}

		if (!$delete_quarantine) {
			$output->writeln("<info>Use -d to delete entries from quarantine{$local}</info>",
				OutputInterface::VERBOSITY_VERBOSE);
			return;
		}

		// CLEAR QUARANTINE
		$service->cleanQuarantine($logs, $output);
		//$logs_ar = $logs->toArray();
	}

	/*
	 Removes the mail directories past QUARANTINE_DAYS that no mail_logs row
	 names: what a spooled mail leaves when its entry is never imported (an
	 unusable payload, a TTL that ran out, a Redis that lost the spool).
	 cleanQuarantine() deletes by row, so nothing else ever would, and they
	 keep their date directory from being pruned.

	 Only what store_raw_mail() creates is touched: <date>/<qid>/,
	 <date>/unknown/<id>/ and <date>/invalid/<id>/ holding just mail.eml,
	 under a date directory past the cutoff and before today. Any row naming
	 the directory keeps it, whatever its mail_stored. A spooled mail past
	 QUARANTINE_DAYS is past retention anyway: drain() imports it with
	 mail_stored = 0 when its file is gone. Without -d nothing is removed.
	*/
	private function removeUnreferencedMailDirs(OutputInterface $output, int $days, bool $delete, bool $show): void {
		$root = Helper::env_string('QUARANTINE_DIR');
		$rootReal = $root === null || $root === '' ? false : realpath($root);
		if ($rootReal === false || !is_dir($rootReal)) {
			return;
		}
		$entries = @scandir($rootReal);
		if ($entries === false) {
			return;
		}

		$cutoff = min(date('Y-m-d', (int) strtotime("-{$days} days")), date('Y-m-d', (int) strtotime('-1 day')));

		foreach ($entries as $day) {
			$dayDir = $rootReal . DIRECTORY_SEPARATOR . $day;
			if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $day) || $day >= $cutoff || is_link($dayDir) || !is_dir($dayDir)) {
				continue;
			}

			try {
				$named = $this->namedMailDirs($day);
			} catch (Throwable $e) {
				$this->fileLogger->error("{$this->app_name} cannot read the mail locations of {$day}, not sweeping it: "
					. $e->getMessage());
				continue;
			}

			foreach ($this->mailDirs($dayDir) as $mailDir) {
				if (isset($named[$mailDir])) {
					continue;
				}
				if (!$delete) {
					if ($show) {
						$output->writeln("Unreferenced, would remove: {$mailDir}", OutputInterface::VERBOSITY_NORMAL);
					}
					continue;
				}
				if (Helper::deleteDirectory($mailDir)) {
					$output->writeln("<info>Removed unreferenced {$mailDir}</info>", OutputInterface::VERBOSITY_VERBOSE);
					$this->fileLogger->info("{$this->app_name} removed unreferenced quarantine directory {$mailDir}");
				}
			}
		}
	}

	/**
	 * The mail directories store_raw_mail() made under $dayDir: <qid>/ and
	 * unknown/ or invalid/ <id>/, each holding only mail.eml
	 * @return list<string>
	 */
	private function mailDirs(string $dayDir): array {
		$dirs = [];
		// an unreadable directory is not swept
		$names = @scandir($dayDir);
		foreach ($names === false ? [] : $names as $name) {
			$path = $dayDir . DIRECTORY_SEPARATOR . $name;
			if ($name === 'unknown' || $name === 'invalid') {
				$ids = @scandir($path);
				foreach ($ids === false ? [] : $ids as $id) {
					if (preg_match('/^[a-f0-9]+$/D', $id)) {
						$dirs[] = $path . DIRECTORY_SEPARATOR . $id;
					}
				}
			} elseif (preg_match('/^[a-zA-Z0-9]+$/D', $name)) {
				$dirs[] = $path;
			}
		}
		return array_values(array_filter($dirs, function (string $dir): bool {
			$names = @scandir($dir);
			return !is_link($dir) && is_dir($dir) && $names !== false
				&& array_values(array_diff($names, ['.', '..'])) === ['mail.eml']
				&& is_file($dir . DIRECTORY_SEPARATOR . 'mail.eml') && !is_link($dir . DIRECTORY_SEPARATOR . 'mail.eml');
		}));
	}

	/**
	 * The directories of every mail_logs row whose mail_location is under the
	 * date directory $day, resolved as on disk
	 * @return array<string, true>
	 */
	private function namedMailDirs(string $day): array {
		$named = [];
		$locations = MailLog::query()->toBase()
			->where('mail_location', 'like', "%/{$day}/%")
			->pluck('mail_location');
		foreach ($locations as $location) {
			if (!is_string($location)) {
				continue;
			}
			$dir = realpath(dirname($location));
			if ($dir !== false) {
				$named[$dir] = true;
			}
		}
		return $named;
	}

	private function pruneEmptyQuarantineDateDirs(OutputInterface $output): void {
		$root = Helper::env_string('QUARANTINE_DIR');

		if ($root === null || $root === '') {
			$output->writeln(
				'<comment>QUARANTINE_DIR not set; skipping prune</comment>',
				OutputInterface::VERBOSITY_NORMAL
			);
			return;
		}

		$rootReal = realpath($root);
		$rootReal = rtrim($rootReal !== false ? $rootReal : $root, DIRECTORY_SEPARATOR);

		if (!is_dir($rootReal)) {
			$output->writeln(
				"<comment>Quarantine root not found: {$rootReal}; skipping prune</comment>",
				OutputInterface::VERBOSITY_NORMAL
			);
			return;
		}

		$entries = @scandir($rootReal);
		if ($entries === false) {
			$output->writeln(
				"<comment>Quarantine root not readable: {$rootReal}; skipping prune</comment>",
				OutputInterface::VERBOSITY_NORMAL
			);
			return;
		}

		foreach ($entries as $name) {
			if ($name === '.' || $name === '..') {
				continue;
			}

			// Only YYYY-MM-DD
			if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $name)) {
				continue;
			}

			// Validate real calendar date
			[$y, $m, $d] = explode('-', $name);
			if (!checkdate((int)$m, (int)$d, (int)$y)) {
				continue;
			}

			$dateDir = $rootReal . DIRECTORY_SEPARATOR . $name;
			$dateDirReal = realpath($dateDir);
			$dateDirReal = rtrim($dateDirReal !== false ? $dateDirReal : $dateDir, DIRECTORY_SEPARATOR);

			// Safety: must be directory and inside root
			if (!is_dir($dateDirReal) ||
				!str_starts_with($dateDirReal, $rootReal . DIRECTORY_SEPARATOR)) {
				continue;
			}

			// store_raw_mail() puts qid-less mails under <date>/unknown/<uniqid>
			// and malformed-qid mails under <date>/invalid/<uniqid>, so
			// cleanQuarantine()'s dirname() leaves these group dirs behind.
			// Prune them first, otherwise they keep the date dir non-empty.
			foreach (self::QUARANTINE_GROUP_DIRS as $group) {
				$groupDir = $dateDirReal . DIRECTORY_SEPARATOR . $group;

				if (!is_dir($groupDir) || is_link($groupDir)) {
					continue;
				}

				// Delete group dirs only if empty; an unreadable one is left alone
				$groupItems = @scandir($groupDir);
				if ($groupItems === false || count(array_diff($groupItems, ['.', '..'])) > 0) {
					continue;
				}

				if (@rmdir($groupDir)) {
					$output->writeln(
					    "<info>Pruned empty quarantine group dir {$groupDir}</info>",
					    OutputInterface::VERBOSITY_VERBOSE
					);
				}
			}

			// Delete date dir only if empty; an unreadable one is left alone
			$items = @scandir($dateDirReal);
			if ($items === false || count(array_diff($items, ['.', '..'])) > 0) {
				continue;
			}

			if (@rmdir($dateDirReal)) {
				$output->writeln(
				    "<info>Pruned empty quarantine date dir {$dateDirReal}</info>",
				    OutputInterface::VERBOSITY_VERBOSE
				);
			}
		}
	}

}
