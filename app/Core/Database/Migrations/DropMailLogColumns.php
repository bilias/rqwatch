<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Core\Database\Migrations;

use Illuminate\Database\Schema\Blueprint;

use App\Core\App;

use App\Configuration\AppConfig;
use App\Inventory\Migrations;

use App\Models\MailLogData;

use Symfony\Component\Console\Output\OutputInterface;

use Illuminate\Database\QueryException;

use RuntimeException;

class DropMailLogColumns extends AbstractMigration {

	protected const string MIGRATION_NAME = Migrations::DROP_MAIL_LOG_COLUMNS;

	public function run(int $batch, int $sleep, bool $force, OutputInterface $output): bool {
		$this->ensureMigrationsTable();

		$name = $this->getName();
		$descr = $this->getDescr();
		$details = "'{$descr}' ($name)";

		// completed and verified
		if ($this->isApplied()) {
			$output->writeln("<comment>Migration $details is already recorded\n</comment>");
			return true;
		}

		/*
		 mail_log_data becomes the only source for these columns, so it has
		 to be complete first. This checks the recorded status, not the data:
		 verifying coverage needs an anti-join against mail_log_data, whose
		 primary key IS its clustered index, so it reads every headers blob
		 and takes a very long time. docs/DB_MAILLOG_DROP_COLUMNS.md has those
		 queries as a manual pre-flight for the maintenance window.
		*/
		if (!App::migrationStatus()->mailLogDataCompleted()) {
			$required = Migrations::MIGRATION_DESCR[Migrations::MAIL_LOG_DATA];

			$this->fileLogger->error(
				"Migration $name requires '{$required}' (" . Migrations::MAIL_LOG_DATA . ") to be completed first"
			);

			$output->writeln(
				"<error>Migration $details requires '{$required}' to be completed first</error>"
			);

			return false;
		}

		/*
		 Inverted, unlike every other verifySchema() in this tree: this
		 migration removes schema, so "verified" means the columns are gone.
		 A fresh install from db-init.sql never had them, so it takes the
		 adoption path and records COMPLETED without running an ALTER.
		*/
		if ($this->verifySchema()) {
			$output->writeln("<comment>Migration $details exists, recording status\n</comment>");
			$this->recordMigrationStatus(Migrations::STATUS_COMPLETED);
			return true;
		}

		$this->fileLogger->info("Starting migration $name");
		$output->writeln("<comment>Starting migration $details</comment>");
		$output->writeln(
			"<question>Trying an instant metadata-only drop. If the server "
			. "refuses it, mail_logs is rebuilt instead and writes are "
			. "blocked until that finishes.</question>"
		);

		try {
			$this->recordMigrationStatus(Migrations::STATUS_RUNNING);

			$this->runMigration($output);
			$this->recordMigrationStatus(Migrations::STATUS_COMPLETED);
		} catch (\Throwable $e) {
			$this->fileLogger->error(
				"Migration $name failed: " . $e->getMessage()
			);

			$output->writeln(
				"<error>Migration $details failed: {$e->getMessage()}</error>"
			);

			return false;
		}

		$this->fileLogger->info("Migration $name completed");
		$output->writeln("<comment>Migration $details completed\n</comment>");
		return true;
	}

	private function runMigration(OutputInterface $output): void {
		$columns = $this->presentColumns();

		if (empty($columns)) {
			return;
		}

		/*
		 A configured max_statement_time would kill the rebuild partway and
		 leave the status at RUNNING. 0 disables the limit, session only.
		*/
		$this->capsule
			->getConnection()
			->statement('SET SESSION max_statement_time = 0');

		if (!$this->dropColumnsInstant($columns, $output)) {
			$this->dropColumnsInplace($columns, $output);
		}

		if (!$this->verifySchema()) {
			throw new RuntimeException(
				"Failed to drop " . implode(', ', $columns)
				. " from " . AppConfig::MAIL_LOGS_TABLE
			);
		}
	}

	/*
	 ALGORITHM=INSTANT is metadata only: no rebuild, no lock beyond a brief
	 MDL, and under Galera the TOI window is milliseconds instead of the
	 whole rebuild. It needs MariaDB 10.4+ (MDEV-15562) and a row format
	 other than COMPRESSED. It does NOT reclaim space -- the dropped values
	 stay in the existing pages until the table is rebuilt, which is what
	 db:optimize_table is for.
	*/
	private function dropColumnsInstant(array $columns, OutputInterface $output): bool {
		try {
			$this->alterTable(
				AppConfig::MAIL_LOGS_TABLE,
				function (Blueprint $table) use ($columns) {
					// one dropColumn() -> one comma-joined ALTER
					$table->dropColumn($columns)->instant();
				}
			);
		} catch (QueryException $e) {
			$this->fileLogger->warning(
				"ALGORITHM=INSTANT drop not accepted, falling back to a "
				. "rebuild: " . $e->getMessage()
			);

			$output->writeln(
				"<comment>ALGORITHM=INSTANT was refused by the server</comment>"
			);

			return false;
		}

		$output->writeln("<info>Columns dropped instantly, no rebuild</info>");

		return true;
	}

	/*
	 Fallback for servers without instant DROP COLUMN. This rebuilds the
	 table. LOCK=NONE keeps a standalone server writable during the rebuild,
	 but on a Galera cluster the DDL replicates under TOI and blocks writes
	 cluster-wide for the duration regardless.
	*/
	private function dropColumnsInplace(array $columns, OutputInterface $output): void {
		$output->writeln(
			"<question>Rebuilding " . AppConfig::MAIL_LOGS_TABLE
			. ". On a Galera cluster this blocks writes on every node until "
			. "it finishes.</question>"
		);

		$drops = array_map(
			fn (string $column) => "DROP COLUMN `{$column}`",
			$columns
		);

		$this->capsule->getConnection()->statement(
			"ALTER TABLE `" . AppConfig::MAIL_LOGS_TABLE . "` "
			. implode(', ', $drops)
			. ", ALGORITHM=INPLACE, LOCK=NONE"
		);
	}

	/*
	 Only the columns actually present, so a partially dropped table does
	 not fail on a missing one.
	*/
	private function presentColumns(): array {
		$present = [];

		foreach (MailLogData::DATA_COLUMNS as $column) {
			if ($this->hasColumn(AppConfig::MAIL_LOGS_TABLE, $column)) {
				$present[] = $column;
			}
		}

		return $present;
	}

	protected function verifySchema(): bool {
		return empty($this->presentColumns());
	}

}
