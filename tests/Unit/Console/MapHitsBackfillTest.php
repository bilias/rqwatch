<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 db:map_hits_backfill on RecordingDatabase. Only one run at a time, and
 only after the map hits migration. The mails of the last 31 days, or of
 the whole log with -a, are counted from the first one; with -r their hits
 are recorded again, a batch (-b, 1000 by default; a positive number) at a
 time, each batch reported. A failing query stops the run, logged.
*/

namespace Tests\Unit\Console;

use App\Console\MapHitsBackfill;
use App\Core\App;
use App\Core\AppContainer;
use App\Core\Database\MigrationStatus;
use App\Inventory\Migrations;
use Illuminate\Database\Eloquent\Model;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Tests\Unit\PrivateLock;
use Tests\Unit\RecordingDatabase;
use Tests\Unit\UnitTestCase;

final class MapHitsBackfillTest extends UnitTestCase
{
	private RecordingDatabase $db;

	private MigrationStatus $status;

	private string $dir = '';

	/** @var list<int> the mails' ids */
	private array $mails = [];

	/** @var string|null the text of the query to fail */
	private ?string $failOn = null;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->db = new RecordingDatabase($this->createStub(PDO::class));
		$this->db->capsule->bootEloquent();
		$this->db->answer = $this->answer(...);
		$this->status = new MigrationStatus(null, $this->log);
		$this->status->setMigrationState(Migrations::MAP_HITS, Migrations::STATUS_COMPLETED);
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, $this->db->capsule, $this->status, null, false));
		$this->dir = sys_get_temp_dir() . '/rqwatch-mhb-' . bin2hex(random_bytes(4));
		mkdir($this->dir);
	}

	#[\Override]
	protected function tearDown(): void {
		$files = glob("{$this->dir}/*");
		foreach ($files === false ? [] : $files as $file) {
			unlink($file);
		}
		rmdir($this->dir);
		Model::unsetConnectionResolver();
		RecordingDatabase::forget();
		parent::tearDown();
	}

	/**
	 * @param list<mixed> $bindings
	 * @return list<array<string, mixed>>
	 */
	private function answer(string $sql, array $bindings): array {
		if ($this->failOn !== null && str_contains($sql, $this->failOn)) {
			throw new RuntimeException('server has gone away');
		}
		if (str_starts_with($sql, 'select min(`id`)')) {
			return [['aggregate' => $this->mails === [] ? null : min($this->mails)]];
		}
		if (str_starts_with($sql, 'select count(*)')) {
			return [['aggregate' => count($this->mails)]];
		}
		if (str_contains($sql, 'as `ml`')) {
			/** @var mixed $first */
			$first = $bindings[0] ?? 0;
			$after = is_int($first) ? $first : 0;
			preg_match('/limit (\d+)$/', $sql, $limit);
			$ids = array_slice(array_values(array_filter($this->mails, fn (int $id): bool => $id > $after)), 0, (int) ($limit[1] ?? 0));
			return array_map(fn (int $id): array => ['id' => $id, 'qid' => "Q{$id}", 'symbols' => '[]'], $ids);
		}
		return [];
	}

	/**
	 * @param array<string, mixed> $options
	 * @return array{int, string}
	 */
	private function backfill(array $options): array {
		$tester = new CommandTester(PrivateLock::give(new MapHitsBackfill(), $this->dir));
		$status = $tester->execute($options, ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);
		return [$status, $tester->getDisplay()];
	}

	public function testBackfill(): void {
		$this->mails = [4, 5, 9];
		[$status, $display] = $this->backfill(['-r' => true, '-b' => '2']);
		$this->assertSame(Command::SUCCESS, $status);
		$this->assertSame("3 mails in the last 31 days, from id 4\nProcessed 2/3 mails, last id 5\nProcessed 3/3 mails, last id 9\n3 mails processed\n", $display);
		$this->assertSame([
			'info db:map_hits_backfill started for 3 mails in the last 31 days',
			'info db:map_hits_backfill 3 mails processed in the last 31 days',
		], $this->log->lines);
		$this->assertSame([
			['select min(`id`) as aggregate from `mail_logs` where created_day >= CURDATE() - INTERVAL ? DAY', [30]],
			['select count(*) as aggregate from `mail_logs` where `id` >= ? and created_day >= CURDATE() - INTERVAL ? DAY', [4, 30]],
		], array_slice($this->db->selects, 0, 2));
		$this->assertSame([3, 30], $this->db->selects[2][1] ?? null);
		$this->assertStringEndsWith('limit 2', $this->db->selects[2][0] ?? '');
	}

	// -a: the whole log; without -r only counted; the default batch 1000
	public function testAllCounted(): void {
		$this->mails = [4];
		[$status, $display] = $this->backfill(['-a' => true]);
		$this->assertSame([Command::SUCCESS, "1 mails in the whole mail log, from id 4\nUse -r to record their hits\n"], [$status, $display]);
		$this->assertSame([[], [4]], [$this->db->selects[0][1] ?? null, $this->db->selects[1][1] ?? null]);
		$this->backfill(['-a' => true, '-r' => true]);
		$this->assertStringEndsWith('limit 1000', $this->db->selects[4][0] ?? '');
	}

	public function testNoMails(): void {
		[$status, $display] = $this->backfill(['-r' => true]);
		$this->assertSame([Command::SUCCESS, "No mails in the last 31 days\n"], [$status, $display]);
		$this->assertCount(1, $this->db->selects);
	}

	// a first id but none counted from it
	public function testNoneCounted(): void {
		$this->db->answer = fn (string $sql): array => [['aggregate' => str_contains($sql, 'min(') ? 4 : 0]];
		[, $display] = $this->backfill(['-r' => true]);
		$this->assertSame("No mails in the last 31 days\n", $display);
	}

	/** @return array<string, array{string}> */
	public static function badBatches(): array {
		return ['zero' => ['0'], 'not a number' => ['5x'], 'negative' => ['-1']];
	}

	#[DataProvider('badBatches')]
	public function testBadBatch(string $batch): void {
		[$status, $display] = $this->backfill(['-b' => $batch]);
		$this->assertSame([Command::FAILURE, "Batch size must be a positive number\n"], [$status, $display]);
	}

	public function testBeforeMigration(): void {
		$this->status->setMigrationState(Migrations::MAP_HITS, Migrations::STATUS_RUNNING);
		[$status, $display] = $this->backfill(['-r' => true]);
		$this->assertSame([Command::FAILURE, "Map hits migration not completed. Run db:migrate_map_hits first\n"], [$status, $display]);
	}

	public function testCountFails(): void {
		$this->mails = [4];
		$this->failOn = 'count(*)';
		[$status, $display] = $this->backfill(['-r' => true]);
		$this->assertSame([Command::FAILURE, "Query error: server has gone away\n"], [$status, $display]);
		$this->assertSame(['error db:map_hits_backfill query error: server has gone away'], $this->log->lines);
	}

	public function testBatchFails(): void {
		$this->mails = [4];
		$this->failOn = 'as `ml`';
		[$status, $display] = $this->backfill(['-r' => true]);
		$this->assertSame(Command::FAILURE, $status);
		$this->assertStringEndsWith("Backfill stopped after id 3: server has gone away\n", $display);
		$this->assertSame('error db:map_hits_backfill stopped after id 3: server has gone away', $this->log->lines[1] ?? null);
	}

	public function testAlreadyRunning(): void {
		$lock = (new LockFactory(new FlockStore($this->dir)))->createLock('db:map_hits_backfill');
		$this->assertTrue($lock->acquire());
		[$status, $display] = $this->backfill([]);
		$this->assertSame([Command::FAILURE, "Already running in another process.\n"], [$status, $display]);
		$this->assertSame(['warning db:map_hits_backfill Already running in another process'], $this->log->lines);
		$lock->release();
	}
}
