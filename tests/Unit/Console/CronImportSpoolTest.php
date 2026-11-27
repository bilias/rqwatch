<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 cron:import_spool with a stub Redis client and RecordingDatabase. Only one
 run at a time. The spooled entries (this server's with -l) are counted,
 listed with -s and imported with -i, a batch (-b, 500 by default or when
 below 1) at a time; a pass the database stopped fails the run, the rest
 left spooled.
*/

namespace Tests\Unit\Console;

use App\Configuration\Config;
use App\Console\CronImportSpool;
use App\Core\App;
use App\Core\AppContainer;
use App\Core\Cache\RedisCache;
use App\Core\Database\MigrationStatus;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Redis;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Tests\Unit\PrivateLock;
use Tests\Unit\RecordingDatabase;
use Tests\Unit\UnitTestCase;

final class CronImportSpoolTest extends UnitTestCase
{
	private const string MX1 = 'rq_spool:mail:mx1:';
	private const string MX2 = 'rq_spool:mail:mx2:';

	/** @var array<string, string> the stub Redis: key => value */
	private array $store = [];

	private RecordingDatabase $db;

	private string $dir = '';

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->db = new RecordingDatabase($this->createStub(PDO::class));
		Config::set('import_spool_redis_key', 'rq_spool');
		Config::set('import_spool_ttl', 60);
		$this->setEnv('MY_API_SERVER_ALIAS', 'mx1');
		$this->setEnv('REDIS_ENABLE', 'true');
		$redis = $this->createStub(Redis::class);
		$redis->method('scan')->willReturnCallback(function (mixed &$iterator, string $pattern): array {
			$iterator = 0;
			$prefix = substr($pattern, 0, -1);
			return array_values(array_filter(array_keys($this->store), fn (string $key): bool => str_starts_with($key, $prefix)));
		});
		$redis->method('ttl')->willReturn(60);
		$redis->method('get')->willReturnCallback(fn (string $key): string|false => $this->store[$key] ?? false);
		$redis->method('del')->willReturnCallback(function (string $key): int {
			unset($this->store[$key]);
			return 1;
		});
		$redis->method('set')->willReturn(true);
		$cache = new RedisCache($this->log);
		(new ReflectionProperty(RedisCache::class, 'client'))->setValue($cache, $redis);
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, $this->db->capsule, new MigrationStatus(null, $this->log), $cache, false));
		$this->dir = sys_get_temp_dir() . '/rqwatch-cis-' . bin2hex(random_bytes(4));
		mkdir($this->dir);
	}

	#[\Override]
	protected function tearDown(): void {
		$files = glob("{$this->dir}/*");
		foreach ($files === false ? [] : $files as $file) {
			unlink($file);
		}
		rmdir($this->dir);
		RecordingDatabase::forget();
		parent::tearDown();
	}

	private function spooled(string $key, string $qid): void {
		$this->store[$key] = json_encode(['data' => ['qid' => $qid, 'mail_stored' => 0], 'recipients' => ['bob@example.org']], JSON_THROW_ON_ERROR);
	}

	/**
	 * @param array<string, mixed> $options
	 * @return array{int, string}
	 */
	private function cron(array $options): array {
		$tester = new CommandTester(PrivateLock::give(new CronImportSpool(), $this->dir));
		$status = $tester->execute($options, ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);
		return [$status, $tester->getDisplay()];
	}

	/** @return list<mixed> the qids imported */
	private function imported(): array {
		return array_column($this->db->rows('mail_logs'), 'qid');
	}

	public function testImport(): void {
		$this->spooled(self::MX1 . 'a', 'Q1');
		$this->spooled(self::MX2 . 'b', 'Q2');
		[$status, $display] = $this->cron(['-i' => true]);
		$this->assertSame(Command::SUCCESS, $status);
		$this->assertSame("2 spooled entries found\nImported Q1 as id 101\nImported Q2 as id 102\n2 imported, 0 skipped of 2 spooled\n", $display);
		$this->assertSame(['Q1', 'Q2'], $this->imported());
		$this->assertSame([], $this->store);
		$this->assertContains('info cron:import_spool 2 spooled entries found', $this->log->lines);
		$this->assertContains('info cron:import_spool 2 imported, 0 skipped of 2 spooled', $this->log->lines);
	}

	// -s lists them, -l this server's only; nothing imported without -i
	public function testShowLocal(): void {
		$this->spooled(self::MX1 . 'a', 'Q1');
		$this->spooled(self::MX2 . 'b', 'Q2');
		[$status, $display] = $this->cron(['-s' => true, '-l' => true]);
		$this->assertSame(Command::SUCCESS, $status);
		$this->assertSame("1 spooled entries found on server: mx1\nSpooled entries pending import on server: mx1:\n"
			. "QID: Q1, stored: 0, key: rq_spool:mail:mx1:a\nUse -i to import spooled entries on server: mx1\n", $display);
		$this->assertSame([], $this->imported());
	}

	// -l with no alias: 'unknown'
	public function testLocalWithoutAlias(): void {
		$this->setEnv('MY_API_SERVER_ALIAS', null);
		[, $display] = $this->cron(['-l' => true]);
		$this->assertSame("No spooled entries found on server: unknown\n", $display);
	}

	public function testNothing(): void {
		[$status, $display] = $this->cron(['-i' => true]);
		$this->assertSame([Command::SUCCESS, "No spooled entries found\n"], [$status, $display]);
		$this->assertSame(['debug cron:import_spool No spooled entries found'], $this->log->lines);
	}

	/** @return array<string, array{array<string, string>, list<string>}> the batch option, the qids imported */
	public static function batches(): array {
		return [
			'one' => [['-b' => '1'], ['Q1']],
			'the default' => [[], ['Q1', 'Q2']],
			'zero' => [['-b' => '0'], ['Q1', 'Q2']],
		];
	}

	/**
	 * @param array<string, string> $batch
	 * @param list<string> $qids
	 */
	#[DataProvider('batches')]
	public function testBatch(array $batch, array $qids): void {
		$this->spooled(self::MX1 . 'a', 'Q1');
		$this->spooled(self::MX1 . 'b', 'Q2');
		$this->cron(['-i' => true] + $batch);
		$this->assertSame($qids, $this->imported());
	}

	// the database refusing an insert stops the pass, and fails the run
	public function testStopped(): void {
		$this->spooled(self::MX1 . 'a', 'Q1');
		$this->spooled(self::MX1 . 'b', 'Q2');
		$this->db->failInsert['Q1'] = new RuntimeException('server has gone away');
		[$status, $display] = $this->cron(['-i' => true, '-l' => true]);
		$this->assertSame(Command::FAILURE, $status);
		$this->assertStringEndsWith("0 imported, 0 skipped of 2 spooled on server: mx1\nImport stopped early; remaining entries stay spooled on server: mx1\n", $display);
		$this->assertCount(2, $this->store);
	}

	public function testAlreadyRunning(): void {
		$lock = (new LockFactory(new FlockStore($this->dir)))->createLock('cron:import_spool');
		$this->assertTrue($lock->acquire());
		[$status, $display] = $this->cron(['-i' => true]);
		$this->assertSame([Command::FAILURE, "Already running in another process.\n"], [$status, $display]);
		$this->assertSame(['warning cron:import_spool Already running in another process'], $this->log->lines);
		$lock->release();
	}
}
