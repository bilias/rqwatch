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
 exception, and RedisCache::get() for a value, a missing key and MULTI mode.
 A stub client replaces the lazy connection.
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
	 * @param list<array{list<string>, int}> $batches
	 */
	private static function scanReplies(array $batches): Closure {
		return function (mixed &$iterator) use (&$batches): array {
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
