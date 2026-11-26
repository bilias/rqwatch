<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Emptying the spool: pending() and drain(). Both count the keys under one
 node's alias or under every node's; without Redis there is nothing, and a
 SCAN that fails counts none, logged. drain() takes the keys in order, as
 many as the batch, and imports each through MailLogWriter: an entry it
 cannot read or use is skipped and kept; a failed import stops the pass and
 keeps the rest; an imported entry is deleted (a failed delete is logged,
 the mail still counts). A local drain imports a mail whose raw file is
 gone as not stored. --show-only lists and imports nothing. After a pass,
 each node's counter is set to the entries it has left, with the TTL.
 A stub client stands in for Redis, and a stub connection for the
 database: its transaction() gets the writer's mail and returns an id or
 throws. With no migration completed, nothing else is queried.
*/

namespace Tests\Unit\Import;

use App\Configuration\Config;
use App\Core\App;
use App\Core\AppContainer;
use App\Core\Cache\RedisCache;
use App\Core\Database\MigrationStatus;
use App\Services\Import\MailLogSpool;
use Closure;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Redis;
use RedisException;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use Tests\Unit\UnitTestCase;

final class SpoolDrainTest extends UnitTestCase
{
	private const string MX1 = 'rq_spool:mail:mx1:';
	// the alias 'mx:2', its ':' encoded
	private const string MX2 = 'rq_spool:mail:mx%3A2:';

	/** @var array<string, string> the stub Redis: key => value */
	private array $store = [];

	/** @var list<array{string, string, mixed}> set() calls: key, value, options */
	private array $sets = [];

	/** @var array<string, RedisException> keys whose get or del fails */
	private array $failGet = [];

	/** @var array<string, RedisException> */
	private array $failDel = [];

	private ?RedisException $failScan = null;

	private ?RedisException $failSet = null;

	/** @var array<string, list<array<string, mixed>>> the rows the writer inserted, by table */
	private array $rows = [];

	/** @var array<string, Throwable> qid => what its insert throws */
	private array $failInsert = [];

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		Config::set('import_spool_redis_key', 'rq_spool');
		Config::set('import_spool_ttl', 60);
		$this->setEnv('MY_API_SERVER_ALIAS', 'mx1');
		$this->setEnv('REDIS_ENABLE', 'true');
		// without a warning: a field may be missing from a spooled mail
		set_error_handler(fn (int $no, string $message): never => throw new \ErrorException($message, 0, $no));
	}

	#[\Override]
	protected function tearDown(): void {
		restore_error_handler();
		(new ReflectionProperty(Capsule::class, 'instance'))->setValue(null, null);
		parent::tearDown();
	}

	private function spool(): MailLogSpool {
		$redis = $this->createStub(Redis::class);
		$redis->method('scan')->willReturnCallback(function (mixed &$iterator, string $pattern): array {
			if ($this->failScan !== null) {
				throw $this->failScan;
			}
			$iterator = 0;
			$prefix = substr($pattern, 0, -1);
			return array_values(array_filter(array_keys($this->store), fn (string $key): bool => str_starts_with($key, $prefix)));
		});
		$redis->method('ttl')->willReturn(60);
		$redis->method('get')->willReturnCallback(function (string $key): string|false {
			if (isset($this->failGet[$key])) {
				throw $this->failGet[$key];
			}
			return $this->store[$key] ?? false;
		});
		$redis->method('del')->willReturnCallback(function (string $key): int {
			if (isset($this->failDel[$key])) {
				throw $this->failDel[$key];
			}
			unset($this->store[$key]);
			return 1;
		});
		$redis->method('set')->willReturnCallback(function (string $key, mixed $value, mixed $options = null): bool {
			if ($this->failSet !== null) {
				throw $this->failSet;
			}
			$this->sets[] = [$key, is_string($value) ? $value : get_debug_type($value), $options];
			return true;
		});
		$cache = new RedisCache($this->log);
		(new ReflectionProperty(RedisCache::class, 'client'))->setValue($cache, $redis);

		$pdo = $this->createStub(PDO::class);
		$pdo->method('lastInsertId')->willReturnCallback(fn (): string => (string) (100 + count($this->rows['mail_logs'] ?? [])));
		$connection = new class ($pdo, $this->insert(...)) extends Connection {
			/** @param Closure(string, array<array-key, mixed>): void $record */
			public function __construct(PDO $pdo, private Closure $record) {
				parent::__construct($pdo);
			}

			/**
			 * the statement the writer's query builder made, recorded and not run
			 * @param string $query
			 * @param array<array-key, mixed> $bindings
			 */
			#[\Override]
			public function insert($query, $bindings = []): bool {
				($this->record)($query, $bindings);
				return true;
			}
		};
		$capsule = new Capsule();
		$capsule->addConnection(['driver' => 'rqwatch-stub']);
		$capsule->getDatabaseManager()->extend('rqwatch-stub', fn (): Connection => $connection);
		// MailLogWriter reaches its connection through the static Capsule::connection()
		$capsule->setAsGlobal();

		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, $capsule, new MigrationStatus(null, $this->log), $cache, false));
		return new MailLogSpool();
	}

	/**
	 * an INSERT the writer made: its rows by table; a mail_logs row of a qid in
	 * $failInsert throws instead
	 * @param array<array-key, mixed> $bindings
	 */
	private function insert(string $sql, array $bindings): void {
		if (preg_match('/^insert into "(\w+)" \(([^)]*)\) values /D', $sql, $match) !== 1) {
			throw new LogicException("Not an INSERT: {$sql}");
		}
		$columns = array_map(fn (string $column): string => trim($column, '"'), explode(', ', $match[2]));
		foreach (array_chunk(array_values($bindings), count($columns)) as $values) {
			$row = array_combine($columns, $values);
			/** @var mixed $qid */
			$qid = $row['qid'] ?? null;
			if ($match[1] === 'mail_logs' && is_string($qid) && isset($this->failInsert[$qid])) {
				throw $this->failInsert[$qid];
			}
			$this->rows[$match[1]][] = $row;
		}
	}

	/** @return list<array<string, mixed>> the mail_logs rows written */
	private function imported(): array {
		return $this->rows['mail_logs'] ?? [];
	}

	/**
	 * a spooled entry as push() writes it
	 * @param array<string, mixed> $data
	 * @param list<string> $recipients
	 */
	private function spooled(string $key, array $data, array $recipients = ['bob@example.org']): void {
		$this->store[$key] = json_encode(['data' => $data, 'recipients' => $recipients], JSON_THROW_ON_ERROR);
	}

	private static function console(): BufferedOutput {
		return new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
	}

	/** @return list<string> */
	private static function written(BufferedOutput $output): array {
		return array_values(array_filter(explode("\n", $output->fetch()), fn (string $line): bool => $line !== ''));
	}

	public function testWithoutRedis(): void {
		$this->setEnv('REDIS_ENABLE', 'false');
		$spool = $this->spool();
		$output = self::console();
		$this->assertSame(0, $spool->pending(false));
		$this->assertSame(['found' => 0, 'inserted' => 0, 'skipped' => 0, 'stopped' => false], $spool->drain(false, 10, false, $output));
		$this->assertSame(['Redis is not enabled; nothing to do'], self::written($output));
		$this->assertSame([], $this->log->lines);
	}

	public function testPending(): void {
		$this->store = [self::MX1 . 'a' => '{}', self::MX1 . 'b' => '{}', self::MX2 . 'a' => '{}', 'rq_spool:count:mx1' => '3'];
		$spool = $this->spool();
		$this->assertSame([2, 3], [$spool->pending(true), $spool->pending(false)]);
		$this->assertSame([], $this->log->lines);
	}

	// no alias: the entries filed as 'unknown', logged
	public function testPendingWithoutAlias(): void {
		$this->setEnv('MY_API_SERVER_ALIAS', ' ');
		$this->store = [self::MX1 . 'a' => '{}', 'rq_spool:mail:unknown:a' => '{}'];
		$this->assertSame(1, $this->spool()->pending(true));
		$this->assertSame(['error [MailLogSpool] MY_API_SERVER_ALIAS is empty'], $this->log->lines);
	}

	public function testPendingScanFails(): void {
		$this->failScan = new RedisException('Connection lost');
		$this->assertSame(0, $this->spool()->pending(false));
		$this->assertSame(['error RedisCache listByPrefix: Connection lost', 'error [MailLogSpool] pending: Connection lost'], $this->log->lines);
	}

	public function testDrainScanFails(): void {
		$this->failScan = new RedisException('Connection lost');
		// decorated: the whole line in the error style
		$output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
		$this->assertSame(['found' => 0, 'inserted' => 0, 'skipped' => 0, 'stopped' => false], $this->spool()->drain(false, 10, false, $output));
		$this->assertSame("\e[37;41mCannot list spooled entries: Connection lost\e[39;49m\n", $output->fetch());
		$this->assertSame(['error RedisCache listByPrefix: Connection lost', 'error [MailLogSpool] drain list: Connection lost'], $this->log->lines);
		$this->assertSame([], $this->sets, 'no counter touched');
	}

	// every node's entries, in key order ('%' before '1'), as many as the batch
	public function testDrainAll(): void {
		$this->spooled(self::MX1 . 'b', ['qid' => 'Q2']);
		$this->spooled(self::MX2 . 'd', ['qid' => 'Q4']);
		$this->spooled(self::MX1 . 'a', ['qid' => 'Q1'], ['alice@example.org', 'bob@example.org']);
		$this->spooled(self::MX2 . 'c', ['qid' => 'Q3']);
		$output = self::console();
		$this->assertSame(['found' => 4, 'inserted' => 3, 'skipped' => 0, 'stopped' => false], $this->spool()->drain(false, 3, false, $output));
		$this->assertSame([['qid' => 'Q3'], ['qid' => 'Q4'], ['qid' => 'Q1']], $this->imported());
		$this->assertSame([
			['mail_log_id' => 101, 'recipient_email' => 'bob@example.org'],
			['mail_log_id' => 102, 'recipient_email' => 'bob@example.org'],
			['mail_log_id' => 103, 'recipient_email' => 'alice@example.org'],
			['mail_log_id' => 103, 'recipient_email' => 'bob@example.org'],
		], $this->rows['mail_log_recipients'] ?? []);
		$this->assertSame([self::MX1 . 'b'], array_keys($this->store), 'the imported entries deleted');
		$this->assertSame(['Imported Q3 as id 101', 'Imported Q4 as id 102', 'Imported Q1 as id 103'], self::written($output));
		$this->assertSame([
			'info [MailLogSpool] Q3 saved in DB [id: 101] by cron:import_spool', 'info Q3 saved in DB [id: 101] by cron:import_spool',
			'info [MailLogSpool] Q4 saved in DB [id: 102] by cron:import_spool', 'info Q4 saved in DB [id: 102] by cron:import_spool',
			'info [MailLogSpool] Q1 saved in DB [id: 103] by cron:import_spool', 'info Q1 saved in DB [id: 103] by cron:import_spool',
		], $this->log->lines);
		// the counters of the nodes seen, from what is left
		$this->assertSame([
			['rq_spool:count:mx%3A2', '0', ['ex' => 60]],
			['rq_spool:count:mx1', '1', ['ex' => 60]],
		], $this->sets);
	}

	// the imported line shows only with -v
	public function testImportedLineVerbose(): void {
		$this->spooled(self::MX1 . 'a', ['qid' => 'Q1']);
		$output = new BufferedOutput();
		$this->spool()->drain(true, 10, false, $output);
		$this->assertSame('', $output->fetch());
	}

	// this node's entries only; its counter is set even when it had none
	public function testDrainLocal(): void {
		$this->spooled(self::MX2 . 'a', ['qid' => 'Q9']);
		$this->assertSame(['found' => 0, 'inserted' => 0, 'skipped' => 0, 'stopped' => false], $this->spool()->drain(true, 10, false));
		$this->assertSame([], $this->imported());
		$this->assertSame([['rq_spool:count:mx1', '0', ['ex' => 60]]], $this->sets);
	}

	public function testShowOnly(): void {
		$this->spooled(self::MX1 . 'a', ['qid' => 'Q1', 'mail_stored' => 1]);
		$this->spooled(self::MX1 . 'b', ['qid' => 'Q2', 'mail_stored' => '1']);
		$this->spooled(self::MX1 . 'c', ['qid' => 'Q3']);
		$this->spooled(self::MX1 . 'd', ['qid' => 7, 'mail_stored' => [1]]);
		$output = self::console();
		$this->assertSame(['found' => 4, 'inserted' => 0, 'skipped' => 0, 'stopped' => false], $this->spool()->drain(true, 10, true, $output));
		$this->assertSame([
			'QID: Q1, stored: 1, key: ' . self::MX1 . 'a',
			'QID: Q2, stored: 1, key: ' . self::MX1 . 'b',
			'QID: Q3, stored: 0, key: ' . self::MX1 . 'c',
			'QID: unknown, stored: array, key: ' . self::MX1 . 'd',
		], self::written($output));
		$this->assertSame([[], 4, []], [$this->imported(), count($this->store), $this->sets]);
		$this->assertSame([], $this->log->lines);
	}

	/** @return array<string, array{string}> */
	public static function unusable(): array {
		return [
			'not JSON' => ['{'],
			'not an object' => ['"text"'],
			'no data' => ['{"recipients":[]}'],
			'no recipients' => ['{"data":{"qid":"Q1"}}'],
			'data not an object' => ['{"data":"Q1","recipients":[]}'],
			'recipients not a list' => ['{"data":{"qid":"Q1"},"recipients":"bob@example.org"}'],
			'a field named by a number' => ['{"data":{"qid":"Q1","0":"x"},"recipients":[]}'],
			'a location not text' => ['{"data":{"qid":"Q1","mail_location":1},"recipients":[]}'],
			'a recipient not text' => ['{"data":{"qid":"Q1"},"recipients":["bob@example.org",1]}'],
		];
	}

	// skipped and kept; the next entry still imports
	#[DataProvider('unusable')]
	public function testUnusableSkipped(string $raw): void {
		$this->store[self::MX1 . 'a'] = $raw;
		$this->spooled(self::MX1 . 'b', ['qid' => 'Q2']);
		$output = self::console();
		$this->assertSame(['found' => 2, 'inserted' => 1, 'skipped' => 1, 'stopped' => false], $this->spool()->drain(true, 10, false, $output));
		$this->assertSame([['qid' => 'Q2']], $this->imported());
		$this->assertSame([self::MX1 . 'a'], array_keys($this->store));
		$this->assertSame(['Unusable payload in ' . self::MX1 . 'a', 'Imported Q2 as id 101'], self::written($output));
		$this->assertSame('error [MailLogSpool] unusable payload in ' . self::MX1 . 'a', $this->log->lines[0]);
	}

	// a location of null is no file to check
	public function testNullLocationUsable(): void {
		$this->spooled(self::MX1 . 'a', ['qid' => 'Q1', 'mail_location' => null]);
		$this->spool()->drain(true, 10, false);
		$this->assertSame([['qid' => 'Q1', 'mail_location' => null]], $this->imported());
	}

	// an expired key reads as false: unusable
	public function testReadFails(): void {
		$this->spooled(self::MX1 . 'a', ['qid' => 'Q1']);
		$this->spooled(self::MX1 . 'b', ['qid' => 'Q2']);
		$this->failGet[self::MX1 . 'a'] = new RedisException('Connection lost');
		$this->assertSame(['found' => 2, 'inserted' => 1, 'skipped' => 1, 'stopped' => false], $this->spool()->drain(true, 10, false));
		$this->assertSame('error [MailLogSpool] cannot read ' . self::MX1 . 'a: Connection lost', $this->log->lines[0]);
		$this->assertSame([['qid' => 'Q2']], $this->imported());
	}

	/** @return array<string, array{Throwable, string}> what the insert throws, the reason logged */
	public static function insertFailures(): array {
		return [
			'its own message' => [new RuntimeException('Deadlock'), 'Deadlock'],
			'the cause' => [new RuntimeException('Query failed', 0, new RuntimeException('Server has gone away')), 'Server has gone away'],
		];
	}

	// the pass stops: the failed entry and the rest stay, the counter is still set
	#[DataProvider('insertFailures')]
	public function testInsertFailureStops(Throwable $thrown, string $reason): void {
		$this->spooled(self::MX1 . 'a', ['qid' => 'Q1']);
		$this->spooled(self::MX1 . 'b', ['qid' => 'Q2']);
		$this->spooled(self::MX1 . 'c', ['qid' => 'Q3']);
		$this->failInsert['Q2'] = $thrown;
		$output = self::console();
		$this->assertSame(['found' => 3, 'inserted' => 1, 'skipped' => 0, 'stopped' => true], $this->spool()->drain(true, 10, false, $output));
		$failed = 'import of Q2 failed, stopping (' . self::MX1 . "b): {$reason}";
		$this->assertSame(['Imported Q1 as id 101', $failed], self::written($output));
		$this->assertSame(["critical [MailLogSpool] {$failed}", "critical {$failed}"], array_slice($this->log->lines, 2));
		$this->assertSame([self::MX1 . 'b', self::MX1 . 'c'], array_keys($this->store));
		$this->assertSame([['rq_spool:count:mx1', '2', ['ex' => 60]]], $this->sets);
	}

	// the row is in: the mail counts, a duplicate on the next pass is logged
	public function testDeleteFails(): void {
		$this->spooled(self::MX1 . 'a', ['qid' => 'Q1']);
		$this->failDel[self::MX1 . 'a'] = new RedisException('Connection lost');
		$this->assertSame(['found' => 1, 'inserted' => 1, 'skipped' => 0, 'stopped' => false], $this->spool()->drain(true, 10, false));
		$stuck = 'Q1 imported as 101 but ' . self::MX1 . 'a remains, may import twice: Connection lost';
		$this->assertSame(["error [MailLogSpool] {$stuck}", "error {$stuck}"], array_slice($this->log->lines, 0, 2));
		$this->assertSame([['rq_spool:count:mx1', '1', ['ex' => 60]]], $this->sets);
	}

	public function testCounterFails(): void {
		$this->failSet = new RedisException('READONLY');
		$this->assertSame(['found' => 0, 'inserted' => 0, 'skipped' => 0, 'stopped' => false], $this->spool()->drain(true, 10, false));
		$this->assertSame(['error [MailLogSpool] cannot resync counter for mx1: READONLY'], $this->log->lines);
	}

	// a key that is the bare prefix has no alias: its counter is 'unknown'
	public function testKeyWithoutAlias(): void {
		$this->store['rq_spool:mail:'] = '{';
		$this->spool()->drain(false, 10, false);
		$this->assertSame([['rq_spool:count:unknown', '0', ['ex' => 60]]], $this->sets);
	}

	/** @return array<string, array{bool, ?string, bool}> local, location, imported as not stored */
	public static function rawFiles(): array {
		return [
			'local, gone' => [true, '/nonexistent/rqwatch/Q1', true],
			'local, present' => [true, __FILE__, false],
			'local, no location' => [true, '', false],
			'all nodes, gone' => [false, '/nonexistent/rqwatch/Q1', false],
		];
	}

	// only this node can see the file: a local drain imports a gone file as not stored
	#[DataProvider('rawFiles')]
	public function testRawFileGone(bool $local, ?string $location, bool $gone): void {
		$this->spooled(self::MX1 . 'a', ['qid' => 'Q1', 'mail_stored' => 1, 'mail_location' => $location]);
		$this->spool()->drain($local, 10, false);
		$this->assertSame([['qid' => 'Q1', 'mail_stored' => $gone ? 0 : 1, 'mail_location' => $gone ? null : $location]], $this->imported());
		$warned = "Q1 raw file is gone: {$location}, importing with mail_stored = 0";
		$this->assertSame($gone ? ["warning [MailLogSpool] {$warned}", "warning {$warned}"] : [], array_slice($this->log->lines, 0, $gone ? 2 : 0));
		$this->assertCount($gone ? 4 : 2, $this->log->lines);
	}

	// every message goes to the output only when there is one
	public function testWithoutOutput(): void {
		$this->setEnv('REDIS_ENABLE', 'false');
		$this->assertSame(['found' => 0, 'inserted' => 0, 'skipped' => 0, 'stopped' => false], $this->spool()->drain(true, 10, false));
		$this->setEnv('REDIS_ENABLE', 'true');
		$this->failScan = new RedisException('Connection lost');
		$this->assertSame(['found' => 0, 'inserted' => 0, 'skipped' => 0, 'stopped' => false], $this->spool()->drain(true, 10, false));
		$this->failScan = null;
		$this->store[self::MX1 . 'a'] = '{';
		$this->spooled(self::MX1 . 'b', ['qid' => 'Q2']);
		$this->spooled(self::MX1 . 'c', ['qid' => 'Q3']);
		$this->failInsert['Q3'] = new RuntimeException('Duplicate entry');
		$this->assertSame(['found' => 3, 'inserted' => 0, 'skipped' => 1, 'stopped' => false], $this->spool()->drain(true, 10, true));
		$this->assertSame(['found' => 3, 'inserted' => 1, 'skipped' => 1, 'stopped' => true], $this->spool()->drain(true, 10, false));
	}

	// an alias of digits is an int as an array key: its counter is still found
	public function testDigitsAlias(): void {
		$this->setEnv('MY_API_SERVER_ALIAS', '7');
		$this->spooled('rq_spool:mail:7:a', ['qid' => 'Q1']);
		$spool = $this->spool();
		$this->assertSame(['found' => 1, 'inserted' => 1, 'skipped' => 0, 'stopped' => false], $spool->drain(true, 10, false));
		$this->spooled('rq_spool:mail:7:b', ['qid' => 'Q2']);
		$this->assertSame(['found' => 1, 'inserted' => 1, 'skipped' => 0, 'stopped' => false], $spool->drain(false, 10, false));
		$this->assertSame([['rq_spool:count:7', '0', ['ex' => 60]], ['rq_spool:count:7', '0', ['ex' => 60]]], $this->sets);
		$this->assertCount(4, $this->log->lines, 'the two imports only');
	}

	// unset, the TTL is 0 (push() spools nothing then)
	public function testNoTtl(): void {
		Config::set('import_spool_ttl', null);
		$this->spool()->drain(true, 10, false);
		$this->assertSame([['rq_spool:count:mx1', '0', ['ex' => 0]]], $this->sets);
	}
}
