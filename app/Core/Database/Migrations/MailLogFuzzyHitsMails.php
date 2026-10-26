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

use Symfony\Component\Console\Output\OutputInterface;

use DateTimeImmutable;
use RuntimeException;
use Throwable;

class MailLogFuzzyHitsMails extends AbstractMigration {

	protected const string MIGRATION_NAME = Migrations::MAIL_LOG_FUZZY_HITS_MAILS;

	private const int INSERT_CHUNK = 1000;

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

		// the backfill reads hits and last_hit_at
		if (!App::migrationStatus()->mailLogFuzzyHitsCompleted()) {
			$required = Migrations::MIGRATION_DESCR[Migrations::MAIL_LOG_FUZZY_HITS];

			$this->fileLogger->error(
				"Migration $name requires '{$required}' (" . Migrations::MAIL_LOG_FUZZY_HITS . ") to be completed first"
			);

			$output->writeln(
				"<error>Migration $details requires '{$required}' to be completed first</error>"
			);

			return false;
		}

		// no adopt branch: an existing table may lack rows, and the backfill only fills what is missing

		if ($batch < 1) {
			$output->writeln("<error>Batch size must be a positive number</error>");
			return false;
		}

		$this->fileLogger->info("Starting migration $name");
		$output->writeln("<comment>Starting migration $details in batches of {$batch} day(s)</comment>");

		try {
			$this->ensureHitsTable($output);
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

	/*
	 * For each learned mail with hits and fewer linked mails than hits, link
	 * the mails from its learn to its last hit that carry one of its hashes.
	 */
	private function runMigration(int $batch, int $sleep, OutputInterface $output): void {
		$db = $this->capsule->getConnection();

		$linked = $db->table(AppConfig::MAIL_LOG_FUZZY_HITS_TABLE)
			->selectRaw('fuzzy_id, COUNT(*) AS n')
			->groupBy('fuzzy_id')
			->pluck('n', 'fuzzy_id')
			->all();

		$rows = $db->table(AppConfig::MAIL_LOG_FUZZY_TABLE)
			->select('id', 'mail_log_id', 'qid', 'hashes', 'hits', 'created_at', 'last_hit_at')
			->where('hits', '>', 0)
			->whereNotNull('last_hit_at')
			->orderBy('id')
			->get()
			->filter(fn ($row) => (int) ($linked[$row->id] ?? 0) < (int) $row->hits)
			->values();

		$total = $rows->count();
		$output->writeln("<info>Learned mails with hits to link: {$total}</info>");

		$done = 0;
		foreach ($rows as $row) {
			$hashes = array_values(array_filter((array) json_decode((string) $row->hashes, true), 'is_string'));
			$inserted = $hashes === [] ? 0 : $this->linkRecord($row, $hashes, $batch, $sleep);

			$done++;
			$count = (int) ($linked[$row->id] ?? 0) + $inserted;
			$output->writeln(
				"<info>[{$done}/{$total}] {$row->qid} (id {$row->id}): {$count} mail(s) linked, {$row->hits} hit(s)</info>"
			);
		}
	}

	// links one learned mail in windows of $batch days; returns the rows inserted
	private function linkRecord(object $row, array $hashes, int $batch, int $sleep): int {
		$db = $this->capsule->getConnection();

		$first = new DateTimeImmutable(substr((string) $row->created_at, 0, 10));
		$last = new DateTimeImmutable(substr((string) $row->last_hit_at, 0, 10));
		$inserted = 0;

		for ($from = $first; $from <= $last; $from = $from->modify("+{$batch} days")) {
			$to = min($from->modify('+' . ($batch - 1) . ' days'), $last);

			// constant day bounds, so created_day_index is used
			$query = $db->table(AppConfig::MAIL_LOGS_TABLE . ' as ml')
				->join(AppConfig::MAIL_LOG_DATA_TABLE . ' as md', 'md.mail_log_id', '=', 'ml.id')
				->whereBetween('ml.created_day', [$from->format('Y-m-d'), $to->format('Y-m-d')])
				->where('ml.created_at', '>=', $row->created_at)
				->where(function ($q) use ($hashes) {
					foreach ($hashes as $hash) {
						$q->orWhereRaw('JSON_CONTAINS(md.fuzzy_hashes, JSON_QUOTE(?))', [$hash]);
					}
				});
			if ($row->mail_log_id !== null) {
				$query->where('ml.id', '<>', $row->mail_log_id);
			}
			$ids = $query->pluck('ml.id')->all();

			// ignore only matters for rows the importer wrote meanwhile
			foreach (array_chunk($ids, self::INSERT_CHUNK) as $chunk) {
				$inserted += $db->table(AppConfig::MAIL_LOG_FUZZY_HITS_TABLE)->insertOrIgnore(
					array_map(fn ($id) => ['fuzzy_id' => (int) $row->id, 'mail_log_id' => (int) $id], $chunk)
				);
			}

			usleep($sleep);
		}

		return $inserted;
	}

	private function ensureHitsTable(OutputInterface $output): void {
		if ($this->hasTable(AppConfig::MAIL_LOG_FUZZY_HITS_TABLE)) {
			return;
		}

		$this->fileLogger->info("Creating table " . AppConfig::MAIL_LOG_FUZZY_HITS_TABLE);
		$output->writeln("<comment>Creating table " . AppConfig::MAIL_LOG_FUZZY_HITS_TABLE . "</comment>");

		$this->createTable(
			AppConfig::MAIL_LOG_FUZZY_HITS_TABLE,
			function (Blueprint $table) {
				$table->unsignedInteger('fuzzy_id');
				$table->unsignedInteger('mail_log_id');

				$table->primary(['fuzzy_id', 'mail_log_id']);
				$table->index('mail_log_id', 'mail_log_id_index');

				$table->foreign('fuzzy_id', 'fk_mail_log_fuzzy_hits_mail_log_fuzzy')
					->references('id')
					->on(AppConfig::MAIL_LOG_FUZZY_TABLE)
					->onDelete('cascade');

				$table->foreign('mail_log_id', 'fk_mail_log_fuzzy_hits_mail_logs')
					->references('id')
					->on(AppConfig::MAIL_LOGS_TABLE)
					->onDelete('cascade');
			}
		);

		if (!$this->hasTable(AppConfig::MAIL_LOG_FUZZY_HITS_TABLE)) {
			throw new RuntimeException("Failed to create " . AppConfig::MAIL_LOG_FUZZY_HITS_TABLE . " table");
		}
	}

	protected function verifySchema(): bool {
		return $this->hasTable(AppConfig::MAIL_LOG_FUZZY_HITS_TABLE);
	}

}
