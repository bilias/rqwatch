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

use App\Configuration\AppConfig;
use App\Inventory\Migrations;

use Symfony\Component\Console\Output\OutputInterface;

use RuntimeException;
use Throwable;

class MailLogViruses extends AbstractMigration {

	protected const string MIGRATION_NAME = Migrations::MAIL_LOG_VIRUSES;

	// one row per antivirus option of each symbol; opt is NULL when the symbol has none
	private const string JSON_TABLE = <<<'SQL'
		JSON_TABLE(md.symbols, '$[*]' COLUMNS (
			av  VARCHAR(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin PATH '$.name',
			grp VARCHAR(64) PATH '$.group',
			NESTED PATH '$.options[*]' COLUMNS (
				opt VARCHAR(1024) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin PATH '$'
			)
		)) AS jt
		SQL;

	public function run(int $batch, int $sleep, bool $force, OutputInterface $output): bool {
		$this->ensureMigrationsTable();

		$name = $this->getName();
		$descr = $this->getDescr();
		$details = "'{$descr}' ($name)";

		// completed and verified
		if (!$force && $this->isApplied()) {
			$output->writeln("<comment>Migration $details is already applied\n</comment>");
			return true;
		}

		// no adopt branch: an existing table may lack rows, and the backfill only fills what is missing

		if ($batch < 1) {
			$output->writeln("<error>Batch size must be a positive number</error>");
			return false;
		}

		$this->fileLogger->info("Starting migration $name");
		$output->writeln("<comment>Starting migration $details in batches of {$batch}</comment>");

		try {
			$this->ensureVirusesTable($output);
			$this->recordMigrationStatus(Migrations::STATUS_RUNNING);

			$this->runMigration($batch, $sleep, $output);
			$this->recordMigrationStatus(Migrations::STATUS_COMPLETED);
		} catch (Throwable $e) {
			$this->fileLogger->error(
				"Migration $name failed: " . $e->getMessage()
			);

			$output->writeln(
				"<error>Migration $details failed: {$e->getMessage()}</error>"
			);

			$this->recordMigrationFailed();

			return false;
		}

		$this->fileLogger->info("Migration $name completed");
		$output->writeln("<comment>Migration $details completed\n</comment>");
		return true;
	}

	private function runMigration(int $batch, int $sleep, OutputInterface $output): void {
		$output->write("<info>Pending virus mails for migration: </info>");

		// virus mails without rows; the loop uses the same predicate as $total
		$pending = fn () => $this->capsule::table(AppConfig::MAIL_LOGS_TABLE . ' as ml')
			->where('ml.has_virus', 1)
			->whereNotExists(function ($query) {
				$query->selectRaw('1')
					->from(AppConfig::MAIL_LOG_VIRUSES_TABLE . ' as v')
					->whereColumn('v.mail_log_id', 'ml.id');
			});

		$total = $pending()->count();
		$output->writeln("<info>{$total}</info>");
		if ($total === 0) {
			return;
		}

		$lastId = 0;
		$done = 0;
		$inserted = 0;

		while (true) {
			$ids = $pending()
				->where('ml.id', '>', $lastId)
				->orderBy('ml.id')
				->limit($batch)
				->pluck('ml.id')
				->all();

			if ($ids === []) {
				break;
			}

			$select = $this->capsule::table(AppConfig::MAIL_LOGS_TABLE . ' as ml')
				->distinct()
				->selectRaw("ml.id, ml.created_day, LEFT(jt.av, 128), LEFT(COALESCE(jt.opt, ''), 255)")
				->join(AppConfig::MAIL_LOG_DATA_TABLE . ' as md', 'md.mail_log_id', '=', 'ml.id')
				->crossJoin($this->capsule->getConnection()->raw(self::JSON_TABLE))
				->whereIn('ml.id', $ids)
				->where('jt.grp', 'antivirus')
				->whereNotNull('jt.av');

			// ignore only matters for rows the importer wrote meanwhile
			$inserted += $this->capsule::table(AppConfig::MAIL_LOG_VIRUSES_TABLE)
				->insertOrIgnoreUsing(['mail_log_id', 'created_day', 'av', 'name'], $select);

			// $lastId steps past mails whose symbols yield no row
			$lastId = (int) end($ids);
			$done += count($ids);
			$remaining = max(0, $total - $done);
			$output->writeln(
				"<info>Mails done: {$done}, Remaining: {$remaining},"
				. " Rows inserted: {$inserted}</info>"
			);
			usleep($sleep);
		}
	}

	private function ensureVirusesTable(OutputInterface $output): void {
		if ($this->hasTable(AppConfig::MAIL_LOG_VIRUSES_TABLE)) {
			return;
		}

		$this->fileLogger->info("Creating table " . AppConfig::MAIL_LOG_VIRUSES_TABLE);
		$output->writeln("<comment>Creating table " . AppConfig::MAIL_LOG_VIRUSES_TABLE . "</comment>");

		$this->createTable(
			AppConfig::MAIL_LOG_VIRUSES_TABLE,
			function (Blueprint $table) {
				$table->unsignedInteger('mail_log_id');
				$table->date('created_day');
				$table->string('av', 128)->charset('utf8mb4')->collation('utf8mb4_bin');
				$table->string('name', 255)->charset('utf8mb4')->collation('utf8mb4_bin')->default('');

				$table->primary(['mail_log_id', 'av', 'name']);
				$table->index(['name', 'created_day'], 'name_day_index');
				$table->index('created_day', 'day_index');

				$table->foreign('mail_log_id', 'fk_mail_log_viruses_mail_logs')
					->references('id')
					->on(AppConfig::MAIL_LOGS_TABLE)
					->onDelete('cascade');
			}
		);

		if (!$this->hasTable(AppConfig::MAIL_LOG_VIRUSES_TABLE)) {
			throw new RuntimeException("Failed to create " . AppConfig::MAIL_LOG_VIRUSES_TABLE . " table");
		}
	}

	protected function verifySchema(): bool {
		return $this->hasTable(AppConfig::MAIL_LOG_VIRUSES_TABLE);
	}

}
