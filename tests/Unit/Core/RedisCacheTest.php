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
 exception. A stub client replaces the lazy connection.
*/

namespace Tests\Unit\Core;

use App\Core\Cache\RedisCache;
use PHPUnit\Framework\Attributes\DataProvider;
use Redis;
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

	private function cache(Redis $redis): RedisCache {
		$cache = new RedisCache($this->log);
		(new ReflectionProperty(RedisCache::class, 'client'))->setValue($cache, $redis);
		return $cache;
	}
}
