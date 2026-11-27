<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 cron:cleanupdb on RecordingDatabase. Only one run at a time. Nothing is
 done unless DATABASE_DAYS (366 by default) is above QUARANTINE_DAYS. The
 mails logged before DATABASE_DAYS ago (this server's with -l, which needs
 MY_API_SERVER_ALIAS) are counted, listed with -s and deleted with -d, a
 batch (-b, 1000 by default or when below 1) at a time.
*/

namespace Tests\Unit\Console;

use App\Console\CronCleanupDb;
use App\Core\App;
use App\Core\AppContainer;
use App\Core\Database\MigrationStatus;
use Illuminate\Database\Eloquent\Model;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Tests\Unit\PrivateLock;
use Tests\Unit\RecordingDatabase;
use Tests\Unit\UnitTestCase;

// as a cron: a command line, each test in a process of its own
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CronCleanupDbTest extends UnitTestCase
{
	private const string BEFORE = '/before (\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)/';

	private RecordingDatabase $db;

	private string $dir = '';

	/** @var list<int> the old mails' ids, until deleted */
	private array $old = [];

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		define('CLI_MODE', true);
		$this->db = new RecordingDatabase($this->createStub(PDO::class));
		$this->db->capsule->bootEloquent();
		$this->db->answer = $this->answer(...);
		$this->db->affect = function (string $sql, array $bindings): int {
			$before = count($this->old);
			$this->old = array_values(array_diff($this->old, array_filter($bindings, 'is_int')));
			return $before - count($this->old);
		};
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, $this->db->capsule, new MigrationStatus(null, $this->log), null, false));
		$this->dir = sys_get_temp_dir() . '/rqwatch-ccd-' . bin2hex(random_bytes(4));
		mkdir($this->dir);
		$this->setEnv('DATABASE_DAYS', '400');
		$this->setEnv('QUARANTINE_DAYS', '30');
		$this->setEnv('MY_API_SERVER_ALIAS', 'mx1');
		$this->setEnv('DEBUG_SEARCH_SQL', null);
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
		if (str_starts_with($sql, 'select count(*)')) {
			return [['aggregate' => count($this->old)]];
		}
		preg_match('/limit (\d+)$/', $sql, $limit);
		/** @var mixed $last */
		$last = array_slice($bindings, -1)[0] ?? 0;
		$after = str_contains($sql, '`id` > ?') && is_int($last) ? $last : 0;
		$ids = array_slice(array_values(array_filter($this->old, fn (int $id): bool => $id > $after)), 0, (int) ($limit[1] ?? 0));
		return array_map(fn (int $id): array => ['id' => $id, 'qid' => "Q{$id}"], $ids);
	}

	/**
	 * @param array<string, mixed> $options
	 * @return array{int, string}
	 */
	private function cron(array $options): array {
		$tester = new CommandTester(PrivateLock::give(new CronCleanupDb(), $this->dir));
		$status = $tester->execute($options, ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);
		return [$status, $tester->getDisplay()];
	}

	// the cutoff said, DATABASE_DAYS ago
	private function cutoff(string $display): string {
		$this->assertSame(1, preg_match(self::BEFORE, $display, $match));
		$this->assertEqualsWithDelta(strtotime('-400 days'), strtotime($match[1]), 5);
		return $match[1];
	}

	public function testDelete(): void {
		$this->old = [3, 5, 8];
		[$status, $display] = $this->cron(['-d' => true, '-b' => '2']);
		$this->assertSame(Command::SUCCESS, $status);
		$cutoff = $this->cutoff($display);
		$this->assertSame("3 entries found in database before {$cutoff}\n3 entries deleted from database\n", $display);
		$this->assertSame([
			['delete from `mail_logs` where `id` in (?, ?)', [3, 5]],
			['delete from `mail_logs` where `id` in (?)', [8]],
		], $this->db->changes);
		$this->assertSame([
			"info cron:cleanupdb 3 entries found in database before {$cutoff}",
			'info cron:cleanupdb 3 entries deleted from database',
		], $this->log->lines);
		$day = date('Y-m-d', (int) strtotime('-400 days'));
		$this->assertSame(['select count(*) as aggregate from `mail_logs` where `created_day` < ?', [$day]], $this->db->selects[0] ?? null);
		$this->assertSame(['select `id`, `qid` from `mail_logs` where `created_day` < ? order by `id` asc limit 2', [$day]], $this->db->selects[1] ?? null);
	}

	// listed with -s, a batch at a time; nothing deleted without -d
	public function testShow(): void {
		$this->old = [3, 5, 8];
		[$status, $display] = $this->cron(['-s' => true, '-b' => '2']);
		$this->assertSame(Command::SUCCESS, $status);
		$cutoff = $this->cutoff($display);
		$this->assertSame("3 entries found in database before {$cutoff}\nDatabase pending delete:\nQID: Q3\nQID: Q5\nQID: Q8\nUse -d to delete entries from database\n", $display);
		$this->assertSame([], $this->db->changes);
		$this->assertStringEndsWith('limit 2', $this->db->selects[1][0] ?? '');
	}

	/** @return array<string, array{?string}> */
	public static function defaultBatches(): array {
		return ['none given' => [null], 'zero' => ['0']];
	}

	// 1000 a batch by default
	#[DataProvider('defaultBatches')]
	public function testDefaultBatch(?string $batch): void {
		$this->old = [3];
		$this->cron(['-d' => true] + ($batch === null ? [] : ['-b' => $batch]));
		$this->assertStringEndsWith('limit 1000', $this->db->selects[1][0] ?? '');
	}

	public function testLocal(): void {
		$this->old = [3];
		[, $display] = $this->cron(['-l' => true]);
		$cutoff = $this->cutoff($display);
		$this->assertSame("1 entries found in database before {$cutoff} on server: mx1\nUse -d to delete entries from database on server: mx1\n", $display);
		$this->assertSame('select count(*) as aggregate from `mail_logs` where `created_day` < ? and `server` = ?', $this->db->selects[0][0] ?? null);
		$this->assertSame('mx1', $this->db->selects[0][1][1] ?? null);
	}

	public function testLocalWithoutAlias(): void {
		$this->setEnv('MY_API_SERVER_ALIAS', null);
		[$status, $display] = $this->cron(['-l' => true]);
		$this->assertSame([Command::FAILURE, "--local needs MY_API_SERVER_ALIAS in .env\n"], [$status, $display]);
		$this->assertSame(['error cron:cleanupdb --local needs MY_API_SERVER_ALIAS in .env'], $this->log->lines);
	}

	public function testNothing(): void {
		[$status, $display] = $this->cron(['-d' => true, '-l' => true]);
		$this->assertSame(Command::SUCCESS, $status);
		$cutoff = $this->cutoff($display);
		$this->assertSame("No entries found in database before {$cutoff} on server: mx1\n", $display);
		$this->assertSame(["debug cron:cleanupdb No entries found in database before {$cutoff} on server: mx1"], $this->log->lines);
		$this->assertSame([], $this->db->changes);
	}

	/** @return array<string, array{?string, string}> DATABASE_DAYS, QUARANTINE_DAYS */
	public static function daysRefused(): array {
		return [
			'the same' => ['30', '30'],
			'fewer' => ['20', '30'],
			'the default, fewer' => [null, '400'],
			'no quarantine days' => ['400', '0'],
		];
	}

	#[DataProvider('daysRefused')]
	public function testDaysRefused(?string $days, string $quarantine): void {
		$this->setEnv('DATABASE_DAYS', $days);
		$this->setEnv('QUARANTINE_DAYS', $quarantine);
		$this->old = [3];
		[$status, $display] = $this->cron(['-d' => true]);
		$this->assertSame([Command::FAILURE, "DATABASE_DAYS must be higher than QUARANTINE_DAYS\n"], [$status, $display]);
		$this->assertSame(['error cron:cleanupdb DATABASE_DAYS must be higher than QUARANTINE_DAYS'], $this->log->lines);
		$this->assertSame([], $this->db->selects);
	}

	// 366 by default
	public function testDefaultDays(): void {
		$this->setEnv('DATABASE_DAYS', null);
		$this->cron([]);
		$this->assertSame(date('Y-m-d', (int) strtotime('-366 days')), $this->db->selects[0][1][0] ?? null);
	}

	public function testAlreadyRunning(): void {
		$lock = (new LockFactory(new FlockStore($this->dir)))->createLock('cron:cleanupdb');
		$this->assertTrue($lock->acquire());
		[$status, $display] = $this->cron([]);
		$this->assertSame([Command::FAILURE, "Already running in another process.\n"], [$status, $display]);
		$this->assertSame(['warning cron:cleanupdb Already running in another process'], $this->log->lines);
		$lock->release();
	}
}
