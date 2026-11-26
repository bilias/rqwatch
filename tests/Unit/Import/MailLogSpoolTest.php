<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The spool holds the app's cache only with REDIS_ENABLE on and only when
 it is a RedisCache (it lists keys by prefix). push() spools nothing with
 the switch off, before it looks at Redis or its limits; a switch it
 cannot read names the mail by its qid, or 'unknown'. It needs a
 limit and a TTL of 1 or more. A spooled mail is a key under its node's
 trimmed alias, URL-encoded, expiring with the TTL, and counted under a
 key of its own; a full spool (a count that is not a number is 0) takes
 none. The mail goes as UTF-8, a bad byte replaced; what JSON cannot hold is
 logged. A stub client stands in for Redis; where there is none, RedisCache
 would connect on first use, and REDIS_DSN is unset.
*/

namespace Tests\Unit\Import;

use App\Configuration\Config;
use App\Core\App;
use App\Core\AppContainer;
use App\Core\Cache\CacheInterface;
use App\Core\Cache\RedisCache;
use App\Services\Import\MailLogSpool;
use PHPUnit\Framework\Attributes\DataProvider;
use Redis;
use ReflectionProperty;
use Tests\Unit\RecordingCache;
use Tests\Unit\UnitTestCase;

final class MailLogSpoolTest extends UnitTestCase
{
	private const string COUNT = 'rq_spool:count:mx%201';

	/** @var list<array{string, string, ?int}> what the stub client was asked: command, key, TTL */
	private array $calls = [];

	/** @var list<array{?string, mixed}> what set() was given besides the key: the value if text, the options */
	private array $sets = [];

	// spooling on, 2 mails at most for 60 s, the client answering $count for the count key
	private function stubbedSpool(string|false $count): MailLogSpool {
		Config::set('import_spool', true);
		Config::set('import_spool_max', 2);
		Config::set('import_spool_ttl', 60);
		Config::set('import_spool_redis_key', 'rq_spool');
		$this->setEnv('MY_API_SERVER_ALIAS', ' mx 1 ');
		$redis = $this->createStub(Redis::class);
		$redis->method('get')->willReturnCallback(function (string $key) use ($count): string|false {
			$this->calls[] = ['get', $key, null];
			return $count;
		});
		$redis->method('set')->willReturnCallback(function (string $key, mixed $value, mixed $options = null): bool {
			$this->calls[] = ['set', $key, null];
			$this->sets[] = [is_string($value) ? $value : null, $options];
			return true;
		});
		$redis->method('incr')->willReturnCallback(function (string $key): int {
			$this->calls[] = ['incr', $key, null];
			return 1;
		});
		$redis->method('expire')->willReturnCallback(function (string $key, int $ttl): bool {
			$this->calls[] = ['expire', $key, $ttl];
			return true;
		});
		$cache = new RedisCache($this->log);
		(new ReflectionProperty(RedisCache::class, 'client'))->setValue($cache, $redis);
		return $this->spool($cache, 'true');
	}

	/** @return array<string, array{string|false}> */
	public static function spooledCounts(): array {
		return ['none yet' => [false], 'one' => ['1'], 'not a number' => ['x']];
	}

	#[DataProvider('spooledCounts')]
	public function testSpooled(string|false $count): void {
		$this->assertTrue($this->stubbedSpool($count)->push(['qid' => 'Q1'], ['a@example.org']));
		$key = $this->calls[1][1] ?? '';
		$this->assertSame([
			['get', self::COUNT, null],
			['set', $key, null],
			['incr', self::COUNT, null],
			['expire', self::COUNT, 60],
		], $this->calls);
		$this->assertMatchesRegularExpression('/^rq_spool:mail:mx%201:[0-9a-f]{14}\\.\\d{8}$/', $key);
		$this->assertCount(1, $this->sets);
		$this->assertSame(['ex' => 60], $this->sets[0][1]);
		$this->assertMatchesRegularExpression('/^\\{"data":\\{"qid":"Q1","created_at":"\\d{4}-\\d\\d-\\d\\d \\d\\d:\\d\\d:\\d\\d"\\},"recipients":\\["a@example.org"\\]\\}$/', $this->sets[0][0] ?? '');
		$this->assertSame([
			"warning [MailLogSpool] Q1 spooled as {$key} for later import",
			"warning Q1 spooled as {$key} for later import",
		], $this->log->lines);
	}

	// as UTF-8, a byte that is not replaced: the mail is spooled, not lost
	public function testSpooledAsText(): void {
		$this->assertTrue($this->stubbedSpool(false)->push(['qid' => 'Q1', 'subject' => "Grüße \xFF"], []));
		$this->assertStringContainsString("\"subject\":\"Grüße \u{FFFD}\"", $this->sets[0][0] ?? '');
	}

	// what JSON cannot hold is logged, and not spooled
	public function testNotEncodable(): void {
		$this->assertFalse($this->stubbedSpool(false)->push(['qid' => 'Q1', 'size' => INF], []));
		$this->assertSame([['get', self::COUNT, null]], $this->calls);
		$this->assertSame(['error [MailLogSpool] cannot encode Q1: Inf and NaN cannot be JSON encoded'], $this->log->lines);
	}

	public function testFull(): void {
		$this->assertFalse($this->stubbedSpool('2')->push(['qid' => 'Q1'], []));
		$this->assertSame([['get', self::COUNT, null]], $this->calls);
		$this->assertSame(['critical [MailLogSpool] spool full (2/2) on mx 1, not spooling Q1'], $this->log->lines);
	}

	private function spool(?CacheInterface $cache, string $redis): MailLogSpool {
		$this->setEnv('REDIS_ENABLE', $redis);
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, null, null, $cache, false));
		return new MailLogSpool();
	}

	private static function cache(MailLogSpool $spool): mixed {
		return (new ReflectionProperty(MailLogSpool::class, 'cache'))->getValue($spool);
	}

	public function testRedisCacheHeld(): void {
		$cache = new RedisCache($this->log);
		$this->assertSame($cache, self::cache($this->spool($cache, 'true')));
	}

	public function testRedisOffNotHeld(): void {
		$this->assertNull(self::cache($this->spool(new RedisCache($this->log), 'false')));
	}

	public function testOtherCacheNotHeld(): void {
		$this->assertNull(self::cache($this->spool(new RecordingCache(), 'true')));
	}

	// off: no limits read, nothing logged
	public function testOffSpoolsNothing(): void {
		Config::set('import_spool', false);
		$this->assertFalse($this->spool(new RedisCache($this->log), 'true')->push(['qid' => 'Q1'], []));
		$this->assertSame([], $this->log->lines);
	}

	// on: past the switch to the limits, here unset
	public function testOnReadsTheLimits(): void {
		Config::set('import_spool', true);
		$this->assertFalse($this->spool(new RedisCache($this->log), 'true')->push(['qid' => 'Q1'], []));
		$this->assertSame(['error [MailLogSpool] import_spool_max/ttl not usable'], $this->log->lines);
	}

	/** @return array<string, array{?int, ?int, bool}> max, ttl, usable */
	public static function limits(): array {
		return [
			'1 and 1' => [1, 1, true],
			'no max' => [null, 60, false],
			'no ttl' => [100, null, false],
			'max 0' => [0, 60, false],
			'ttl 0' => [100, 0, false],
		];
	}

	#[DataProvider('limits')]
	public function testLimits(?int $max, ?int $ttl, bool $usable): void {
		Config::set('import_spool', true);
		Config::set('import_spool_max', $max);
		Config::set('import_spool_ttl', $ttl);
		$this->setEnv('MY_API_SERVER_ALIAS', 'mx1');
		$this->setEnv('REDIS_DSN', null);
		$this->assertFalse($this->spool(new RedisCache($this->log), 'true')->push(['qid' => 'Q1'], []));
		$this->assertSame($usable ? [
			'error [RedisCache]: REDIS_DSN is not set in .env',
			'error [MailLogSpool] push: REDIS_DSN is not set in .env',
		] : ['error [MailLogSpool] import_spool_max/ttl not usable'], $this->log->lines);
	}

	/** @return array<string, array{array<string, mixed>, string}> */
	public static function qids(): array {
		return [
			'qid' => [['qid' => 'Q1'], 'Q1'],
			'no qid' => [[], 'unknown'],
			'qid not text' => [['qid' => 7], 'unknown'],
		];
	}

	/** @param array<string, mixed> $mailData */
	#[DataProvider('qids')]
	public function testSwitchErrorNamesTheMail(array $mailData, string $named): void {
		Config::set('import_spool', 'no');
		$this->assertFalse($this->spool(null, 'false')->push($mailData, []));
		$this->assertSame(["error [MailLogSpool] {$named}: import_spool must be true or false, not string in config; spooling, as config.php's default"], $this->log->lines);
	}
}
