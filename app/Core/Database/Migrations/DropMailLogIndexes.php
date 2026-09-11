<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Core\Database\Migrations;

use App\Configuration\AppConfig;
use App\Inventory\Migrations;

use Symfony\Component\Console\Output\OutputInterface;

use RuntimeException;

class DropMailLogIndexes extends AbstractMigration {

	protected const string MIGRATION_NAME = Migrations::DROP_MAIL_LOG_INDEXES;

	private const array INDEXES = [
		'id_action_index',
		'created_at_index',
		'rcpt_to_index',
	];

	public function run(int $batch, int $sleep, bool $force, OutputInterface $output): bool {
		$this->ensureMigrationsTable();

		$name = $this->getName();
		$descr = $this->getDescr();
		$details = "'{$descr}' ($name)";

		// completed and verified
		if ($this->isApplied()) {
			$output->writeln("<comment>Migration $details is already applied\n</comment>");
			return true;
		}

		// inverted: this migration removes schema, so "verified" means the
		// indexes are gone. A fresh install never had them.
		if ($this->verifySchema()) {
			$output->writeln("<comment>Migration $details exists, recording status\n</comment>");
			$this->recordMigrationStatus(Migrations::STATUS_COMPLETED);
			return true;
		}

		$this->fileLogger->info("Starting migration $name");
		$output->writeln("<comment>Starting migration $details</comment>");

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
		$indexes = $this->presentIndexes();

		if (empty($indexes)) {
			return;
		}

		// a configured max_statement_time could kill the ALTER while waiting
		// on the MDL and leave the status at RUNNING. Session only.
		$this->capsule
			->getConnection()
			->statement('SET SESSION max_statement_time = 0');

		$this->dropIndexes($indexes, $output);

		if (!$this->verifySchema()) {
			throw new RuntimeException(
				"Failed to drop " . implode(', ', $indexes)
				. " from " . AppConfig::MAIL_LOGS_TABLE
			);
		}
	}

	/*
	 One ALTER for both indexes, raw rather than two Blueprint dropIndex()
	 calls, because Blueprint emits one ALTER per call and that would be two
	 TOI windows on Galera instead of one. DROP INDEX is metadata-only and
	 near-instant: no rebuild, and reversible with CREATE INDEX.
	*/
	private function dropIndexes(array $indexes, OutputInterface $output): void {
		$drops = array_map(
			fn (string $index) => "DROP INDEX `{$index}`",
			$indexes
		);

		$this->capsule->getConnection()->statement(
			"ALTER TABLE `" . AppConfig::MAIL_LOGS_TABLE . "` "
			. implode(', ', $drops)
			. ", ALGORITHM=INPLACE, LOCK=NONE"
		);

		$output->writeln(
			"<info>Dropped " . implode(', ', $indexes) . "</info>"
		);
	}

	// only the indexes actually present, so a partially applied table does
	// not fail on a missing one
	private function presentIndexes(): array {
		$present = [];

		foreach (self::INDEXES as $index) {
			if ($this->hasIndex(AppConfig::MAIL_LOGS_TABLE, $index)) {
				$present[] = $index;
			}
		}

		return $present;
	}

	protected function verifySchema(): bool {
		return empty($this->presentIndexes());
	}

}
