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
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\LockableTrait;

use App\Configuration\AppConfig;

use App\Core\App;

#[AsCommand(
	name: 'db:optimize_table',
	description: 'Rebuild a table to reclaim disk space',
	help: 'This command rebuilds a table with OPTIMIZE TABLE to reclaim
space that deleted rows or dropped columns still occupy.

Run it manually, in a maintenance window. On InnoDB, OPTIMIZE TABLE is
mapped to ALTER TABLE ... FORCE, which rebuilds the table and needs free
space for a second copy. On a Galera cluster the DDL replicates under TOI
and blocks writes on every node until it finishes.

An instant column drop (db:migrate_drop_mail_log_columns) does not reclaim
space on its own -- this is the step that does.
',
)]
class OptimizeTable extends RqwatchCliCommand
{
	private string $app_name = "db:optimize_table";

	use LockableTrait;

	#[\Override]
	protected function configure(): void {
		$this->addArgument(
			'table', // name
			InputArgument::OPTIONAL, // mode
			'Table to rebuild', // description
			AppConfig::MAIL_LOGS_TABLE // default
		);
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		if (!$this->lock()) {
			$output->writeln('<comment>Already running in another process.</comment>');
			$this->fileLogger->warning("{$this->app_name} Already running in another process");
			return Command::FAILURE;
		}

		$table = (string) $input->getArgument('table');

		if (!in_array($table, self::tables(), true)) {
			$output->writeln("<error>Unknown table '{$table}'</error>");
			$output->writeln(
				"<comment>Known tables: " . implode(', ', self::tables()) . "</comment>"
			);

			return Command::FAILURE;
		}

		$before = $this->tableSize($table);

		$output->writeln("<comment>Rebuilding `{$table}`, currently {$before}</comment>");
		$output->writeln("<question>This will take some time, please be patient</question>");

		$this->fileLogger->info("{$this->app_name} rebuilding {$table} ({$before})");

		try {
			$connection = App::capsule()->getConnection();

			// a configured limit would kill the rebuild partway
			$connection->statement('SET SESSION max_statement_time = 0');
			$connection->statement("OPTIMIZE TABLE `{$table}`");
		} catch (\Throwable $e) {
			$this->fileLogger->error(
				"{$this->app_name} failed to rebuild {$table}: " . $e->getMessage()
			);

			$output->writeln("<error>Rebuild failed: {$e->getMessage()}</error>");

			return Command::FAILURE;
		}

		$after = $this->tableSize($table);

		$this->fileLogger->info(
			"{$this->app_name} rebuilt {$table}: {$before} -> {$after}"
		);

		$output->writeln("<info>Rebuilt `{$table}`: {$before} -> {$after}</info>");

		$this->printRuntime($output);

		return Command::SUCCESS;
	}

	private function tableSize(string $table): string {
		$row = App::capsule()->getConnection()->selectOne(
			"SELECT data_length, index_length, data_free
			   FROM information_schema.TABLES
			  WHERE table_schema = DATABASE() AND table_name = ?",
			[$table]
		);

		if ($row === null) {
			return 'unknown size';
		}

		return sprintf(
			'data %s, index %s, free %s',
			$this->humanBytes((int) $row->data_length),
			$this->humanBytes((int) $row->index_length),
			$this->humanBytes((int) $row->data_free)
		);
	}

	private function humanBytes(int $bytes): string {
		$units = ['B', 'K', 'M', 'G', 'T'];
		$i = 0;

		while ($bytes >= 1024 && $i < count($units) - 1) {
			$bytes = intdiv($bytes, 1024);
			$i++;
		}

		return $bytes . $units[$i];
	}

	private static function tables(): array {
		return [
			AppConfig::MAIL_LOGS_TABLE,
			AppConfig::MAIL_LOG_DATA_TABLE,
			AppConfig::MAIL_LOG_RECIPIENTS_TABLE,
			AppConfig::MAIL_LOG_TOKENS_TABLE,
		];
	}

}
