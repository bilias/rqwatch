<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 RedisCache::ttl() for every reply phpredis can give: a TTL, -2 (no key),
 -1 (no expiry), false, the client itself (MULTI or pipeline mode) and an
 exception, RedisCache::get() for a value, a missing key and MULTI mode,
 and RedisCache::incr() for a count, MULTI mode and an exception. set()
 with and without a TTL, has(), delete() and expire(); the prefix scans
 follow SCAN's cursor, and their errors are logged and thrown. A stub
 client replaces the lazy connection; the connection itself is built
 lazily from REDIS_DSN, which must give a phpredis Redis client.
*/

namespace Tests\Unit\Core;

use App\Core\Cache\RedisCache;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use Redis;
use RuntimeException;
use RedisException;
use ReflectionProperty;
use Tests\Unit\UnitTestCase;

final class RedisCacheTest extends UnitTestCase
{
	/** @var list<array{string, mixed, mixed}> key, value, options of each SET */
	private array $setCalls = [];

	/** @return array<string, array{int|false, ?int, ?string}> reply, result, log line */
	public static function replyCases(): array {
		return [
			'a TTL' => [300, 300, null],
			'zero' => [0, 0, null],
			'no key' => [-2, null, "debug RedisCache ttl got -2 (expired/not found) for key 'k'"],
			'no expiry' => [-1, null, "warning RedisCache ttl got -1 for key 'k'"],
			'false' => [false, null, "warning RedisCache ttl got bool for key 'k'"],
		];
	}

	#[DataProvider('replyCases')]
	public function testReply(int|false $reply, ?int $result, ?string $line): void {
		$redis = $this->createStub(Redis::class);
		$redis->method('ttl')->willReturn($reply);
		$this->assertSame($result, $this->cache($redis)->ttl('k'));
		$this->assertSame($line === null ? [] : [$line], $this->log->lines);
	}

	// in MULTI mode phpredis queues the command and returns the client
	public function testMultiMode(): void {
		$redis = $this->createStub(Redis::class);
		$redis->method('ttl')->willReturnSelf();
		$this->assertNull($this->cache($redis)->ttl('k'));
		// the stub's class name, a Redis subclass
		$this->assertSame(["warning RedisCache ttl got " . get_debug_type($redis) . " for key 'k'"], $this->log->lines);
	}

	public function testException(): void {
		$redis = $this->createStub(Redis::class);
		$redis->method('ttl')->willThrowException(new RedisException('Connection lost'));
		$this->assertNull($this->cache($redis)->ttl('k'));
		$this->assertSame(['error RedisCache ttl: Connection lost'], $this->log->lines);
	}

	// get(): the value, or false for a missing key and anything that is not a string
	public function testGetString(): void {
		$redis = $this->createStub(Redis::class);
		$redis->method('get')->willReturn('v');
		$this->assertSame('v', $this->cache($redis)->get('k'));
	}

	public function testGetMissing(): void {
		$redis = $this->createStub(Redis::class);
		$redis->method('get')->willReturn(false);
		$this->assertFalse($this->cache($redis)->get('k'));
		$this->assertSame([], $this->log->lines);
	}

	// in MULTI mode phpredis returns the client
	public function testGetMultiMode(): void {
		$redis = $this->createStub(Redis::class);
		$redis->method('get')->willReturnSelf();
		$this->assertFalse($this->cache($redis)->get('k'));
		// a miss, but not a silent one
		$this->assertSame(["warning RedisCache get got " . get_debug_type($redis) . " for key 'k'"], $this->log->lines);
	}

	// incr(): the new count; 0 for a reply that is not one, null when Redis fails
	public function testIncr(): void {
		$redis = $this->createStub(Redis::class);
		$redis->method('incr')->willReturn(5);
		$this->assertSame(5, $this->cache($redis)->incr('k'));
	}

	public function testIncrMultiMode(): void {
		$redis = $this->createStub(Redis::class);
		$redis->method('incr')->willReturnSelf();
		$this->assertSame(0, $this->cache($redis)->incr('k'));
	}

	public function testIncrFails(): void {
		$redis = $this->createStub(Redis::class);
		$redis->method('incr')->willThrowException(new RedisException('Connection lost'));
		$this->assertNull($this->cache($redis)->incr('k'));
		$this->assertSame(['error RedisCache incr: Connection lost'], $this->log->lines);
	}

	public function testSet(): void {
		$redis = $this->createStub(Redis::class);
		$redis->method('set')->willReturnCallback(function (string $key, mixed $value, mixed $option = null): bool {
			$this->setCalls[] = [$key, $value, $option];
			return true;
		});
		$cache = $this->cache($redis);
		$this->assertTrue($cache->set('k', 'v', 60));
		$this->assertTrue($cache->set('k', 'v'));
		$this->assertSame([['k', 'v', ['ex' => 60]], ['k', 'v', null]], $this->setCalls);
	}

	public function testSetRefused(): void {
		$redis = $this->createStub(Redis::class);
		$redis->method('set')->willReturn(false);
		$this->assertFalse($this->cache($redis)->set('k', 'v', 60));
		$this->assertFalse($this->cache($redis)->set('k', 'v'));
	}

	/** @return array<string, array{int|false, bool}> */
	public static function counts(): array {
		return ['none' => [0, false], 'one' => [1, true], 'two' => [2, true], 'false' => [false, false]];
	}

	#[DataProvider('counts')]
	public function testHasAndDelete(int|false $reply, bool $result): void {
		$redis = $this->createMock(Redis::class);
		$redis->expects($this->once())->method('exists')->with('k')->willReturn($reply);
		$redis->expects($this->once())->method('del')->with('k')->willReturn($reply);
		$cache = $this->cache($redis);
		$this->assertSame($result, $cache->has('k'));
		$this->assertSame($result, $cache->delete('k'));
	}

	public function testExpire(): void {
		$redis = $this->createMock(Redis::class);
		$redis->expects($this->once())->method('expire')->with('k', 60)->willReturn(true);
		$this->assertTrue($this->cache($redis)->expire('k', 60));
	}

	public function testExpireFails(): void {
		$redis = $this->createStub(Redis::class);
		$redis->method('expire')->willThrowException(new RedisException('Connection lost'));
		$this->assertFalse($this->cache($redis)->expire('k', 60));
		$this->assertSame(['error RedisCache expire: Connection lost'], $this->log->lines);
	}

	public function testCountByPrefix(): void {
		$redis = $this->createMock(Redis::class);
		$redis->expects($this->exactly(3))->method('scan')->with($this->anything(), 'p:*', 100)
			->willReturnCallback(self::scanReplies([[['p:a', 'p:b'], 7], [false, 9], [['p:c'], 0]]));
		$this->assertSame(3, $this->cache($redis)->countByPrefix('p:'));
	}

	public function testDeleteByPrefix(): void {
		$redis = $this->createMock(Redis::class);
		$redis->expects($this->exactly(5))->method('scan')->with($this->anything(), 'p:*', 100)
			->willReturnCallback(self::scanReplies([[['p:a', 'p:b'], 7], [[], 8], [false, 9], [['p:c'], 10], [['p:d'], 0]]));
		$redis->expects($this->exactly(3))->method('del')->willReturnCallback(
			fn (mixed $keys): mixed => match ($keys) {
				['p:a', 'p:b'] => 2,
				['p:c'] => $redis,
				['p:d'] => 1,
				default => throw new RuntimeException('unexpected DEL'),
			}
		);
		// the client itself (MULTI mode) deletes nothing countable; the rest add up
		$this->assertSame(3, $this->cache($redis)->deleteByPrefix('p:'));
	}

	public function testScanFails(): void {
		$redis = $this->createStub(Redis::class);
		$redis->method('scan')->willThrowException(new RedisException('Connection lost'));
		$cache = $this->cache($redis);
		$scans = [
			'countByPrefix' => fn (): int => $cache->countByPrefix('p:'),
			'deleteByPrefix' => fn (): int => $cache->deleteByPrefix('p:'),
			'listByPrefix' => fn (): array => $cache->listByPrefix('p:'),
		];
		foreach ($scans as $method => $scan) {
			try {
				$scan();
				$this->fail("{$method}: no exception");
			} catch (RedisException $e) {
				$this->assertSame('Connection lost', $e->getMessage());
			}
		}
		$this->assertSame([
			'error RedisCache countByPrefix: Connection lost',
			'error RedisCache deleteByPrefix: Connection lost',
			'error RedisCache listByPrefix: Connection lost',
		], $this->log->lines);
	}

	// key names stay out of the message: the login throttle's are client IPs
	public function testListByPrefixKeyNotText(): void {
		$redis = $this->createStub(Redis::class);
		$redis->method('scan')->willReturnCallback(self::scanReplies([[[7], 0]]));
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("SCAN for 'p:' returned a key that is not text: int");
		$this->cache($redis)->listByPrefix('p:');
	}

	// lazy: built, not connected
	public function testConnection(): void {
		$this->setEnv('REDIS_DSN', 'redis://127.0.0.1:6399?lazy=1');
		$cache = new RedisCache($this->log);
		$client = $cache->getConnection();
		$this->assertSame($client, $cache->getConnection(), 'built once');
		$this->assertSame(['debug [RedisCache] Redis connection established'], $this->log->lines);
	}

	public function testConnectionNotRedis(): void {
		$this->setEnv('REDIS_DSN', 'redis://127.0.0.1:6399?lazy=1&redis_cluster=1');
		$cache = new RedisCache($this->log);
		try {
			$cache->getConnection();
			$this->fail('no exception');
		} catch (RuntimeException $e) {
			// Symfony's lazy cluster proxy, named by the phpredis major version
			$this->assertMatchesRegularExpression('/^REDIS_DSN must give a phpredis Redis connection, got Symfony\\\\Component\\\\Cache\\\\Traits\\\\RedisCluster\\d*Proxy$/', $e->getMessage());
		}
		$this->assertCount(1, $this->log->lines);
		$this->assertStringStartsWith('error [RedisCache]: REDIS_DSN must give a phpredis Redis connection, got ', $this->log->lines[0] ?? '');
	}

	public function testConnectionNoDsn(): void {
		$this->setEnv('REDIS_DSN', null);
		$this->expectExceptionMessage('REDIS_DSN is not set in .env');
		(new RedisCache($this->log))->getConnection();
	}

	// a key gone between SCAN and TTL (-2) is not listed; no expiry (-1) is
	public function testListByPrefix(): void {
		$redis = $this->createStub(Redis::class);
		$redis->method('scan')->willReturnCallback(self::scanReplies([[['p:a', 'p:gone', 'p:forever'], 0]]));
		$redis->method('ttl')->willReturnMap([['p:a', 300], ['p:gone', -2], ['p:forever', -1]]);
		$this->assertSame(['p:a' => 300, 'p:forever' => -1], $this->cache($redis)->listByPrefix('p:'));
	}

	// SCAN answers in batches: the cursor is followed until Redis returns 0
	public function testListByPrefixFollowsTheCursor(): void {
		$redis = $this->createMock(Redis::class);
		$redis->expects($this->exactly(2))->method('scan')->with($this->anything(), 'p:*', 100)
			->willReturnCallback(self::scanReplies([[['p:a'], 7], [['p:b'], 0]]));
		$redis->method('ttl')->willReturn(60);
		$this->assertSame(['p:a' => 60, 'p:b' => 60], $this->cache($redis)->listByPrefix('p:'));
	}

	// a failed TTL is an error, not "0 seconds left"
	public function testListByPrefixTtlFails(): void {
		$redis = $this->createStub(Redis::class);
		$redis->method('scan')->willReturnCallback(self::scanReplies([[['p:a'], 0]]));
		$redis->method('ttl')->willReturn(false);
		try {
			$this->cache($redis)->listByPrefix('p:');
			$this->fail('no exception');
		} catch (RuntimeException $e) {
			$this->assertSame("TTL of a key under 'p:' failed: bool", $e->getMessage());
		}
		$this->assertSame(["error RedisCache listByPrefix: TTL of a key under 'p:' failed: bool"], $this->log->lines);
	}

	/**
	 * SCAN's replies in turn, each with the cursor it leaves; a call after
	 * the last is an error, so a loop that does not stop at cursor 0 fails
	 * at once instead of running until the time limit
	 * @param list<array{list<mixed>|false, int}> $batches
	 */
	private static function scanReplies(array $batches): Closure {
		return function (mixed &$iterator) use (&$batches): array|bool {
			$batch = array_shift($batches);
			if ($batch === null) {
				throw new RuntimeException('SCAN called again after cursor 0');
			}
			[$keys, $iterator] = $batch;
			return $keys;
		};
	}

	private function cache(Redis $redis): RedisCache {
		$cache = new RedisCache($this->log);
		(new ReflectionProperty(RedisCache::class, 'client'))->setValue($cache, $redis);
		return $cache;
	}
}
