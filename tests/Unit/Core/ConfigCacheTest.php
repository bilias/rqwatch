<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Config::loadAndInitWithRedisCache() with a stub Redis client: a cached
 config of this version is used, anything else (a miss, an old version, a
 reply that is not a JSON object, a Redis error) loads the files and caches
 them again, and a config json_encode() refuses is loaded but not cached.
*/

namespace Tests\Unit\Core;

use App\Configuration\AppConfig;
use App\Configuration\Config;
use App\Core\Cache\RedisCache;
use PHPUnit\Framework\Attributes\DataProvider;
use Redis;
use RedisException;
use ReflectionProperty;
use Tests\Unit\UnitTestCase;

final class ConfigCacheTest extends UnitTestCase
{
	private const KEY = 'rqwatch_config_test';
	private const TTL = 300;

	private string $default;
	private string $local;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		Config::setLogger($this->log);
		$this->default = (string) tempnam(sys_get_temp_dir(), 'rqwatch-config-');
		$this->local = (string) tempnam(sys_get_temp_dir(), 'rqwatch-config-local-');
		file_put_contents($this->default, "<?php\n\$APP_VERSION = " . var_export(AppConfig::VERSION, true)
			. ";\n\$plain = 'file';\n\$overridden = 'default';\n");
		file_put_contents($this->local, "<?php\n\$overridden = 'local';\n");
	}

	#[\Override]
	protected function tearDown(): void {
		@unlink($this->default);
		@unlink($this->local);
		parent::tearDown();
	}

	public function testCachedConfigOfThisVersionIsUsed(): void {
		$redis = $this->createMock(Redis::class);
		$redis->method('get')->willReturn(json_encode(['APP_VERSION' => AppConfig::VERSION, 'plain' => 'cached']));
		$redis->expects($this->never())->method('set');
		$this->load($redis, ['extra' => 1]);
		$this->assertSame('cached', Config::get('plain'));
		$this->assertNull(Config::get('overridden'), 'files not read');
		$this->assertSame(1, Config::get('extra'), 'extras on top of the cache');
	}

	/** @return array<string, array{mixed}> the reply to GET; 'client' is the client itself */
	public static function reloadCases(): array {
		return [
			'a miss' => [false],
			'MULTI mode: the client itself' => ['client'],
			'an old version' => [json_encode(['APP_VERSION' => '0.0.1', 'plain' => 'cached'])],
			'not JSON' => ['{not json'],
			'JSON, not an object' => ['"plain"'],
			// used to read $data['APP_VERSION'] with a warning
			'a list, not keyed by setting' => [json_encode(['plain', 'cached'])],
			'no version' => [json_encode(['plain' => 'cached'])],
		];
	}

	#[DataProvider('reloadCases')]
	public function testReloadedFromFilesAndCached(mixed $reply): void {
		$redis = $this->createMock(Redis::class);
		if ($reply === 'client') {
			$redis->method('get')->willReturnSelf();
		} else {
			$redis->method('get')->willReturn($reply);
		}
		$cached = null;
		$redis->expects($this->once())->method('set')
			->with(self::KEY, $this->callback(function (mixed $json) use (&$cached): bool {
				/** @var mixed $cached */
				$cached = $json;
				return true;
			}), ['ex' => self::TTL])
			->willReturn(true);

		$this->load($redis, ['extra' => 1]);

		$this->assertSame(['file', 'local', 1], [Config::get('plain'), Config::get('overridden'), Config::get('extra')]);
		$this->assertSame(
			['APP_VERSION' => AppConfig::VERSION, 'plain' => 'file', 'overridden' => 'local'],
			json_decode(is_string($cached) ? $cached : self::fail('the cached config is not text'), true),
			'the files are cached, the extras are not'
		);
		$this->assertContains('debug Config [loadAndInitWithRedisCache]: Cached data in Redis', $this->log->lines);
		$this->assertSame([], array_values(array_filter($this->log->lines, fn(string $line): bool => !str_starts_with($line, 'debug '))),
			'nothing above debug logged');
	}

	public function testForcedReloadSkipsTheCache(): void {
		$redis = $this->createMock(Redis::class);
		$redis->expects($this->never())->method('get');
		$redis->expects($this->once())->method('set')->willReturn(true);
		$this->load($redis, [], forceReload: true);
		$this->assertSame('file', Config::get('plain'));
	}

	public function testRedisErrorFallsBackToTheFiles(): void {
		$redis = $this->createMock(Redis::class);
		$redis->method('get')->willThrowException(new RedisException('Connection lost'));
		$redis->expects($this->once())->method('set')->willReturn(true);
		$this->load($redis);
		$this->assertSame('file', Config::get('plain'));
		$this->assertLogged('error Config [loadAndInitWithRedisCache1]: Connection lost');
	}

	// invalid UTF-8 in a config value: loaded, not cached, the cause logged
	public function testUnencodableConfigIsNotCached(): void {
		file_put_contents($this->local, "<?php\n\$overridden = \"\\xff\";\n");
		$redis = $this->createMock(Redis::class);
		$redis->method('get')->willReturn(false);
		$redis->expects($this->never())->method('set');
		$this->load($redis);
		$this->assertSame(["file", "\xff"], [Config::get('plain'), Config::get('overridden')]);
		$this->assertLogged('error Config [loadAndInitWithRedisCache2]: Malformed UTF-8');
	}

	/** @param array<string, mixed> $extras */
	private function load(Redis $redis, array $extras = [], bool $forceReload = false): void {
		$cache = new RedisCache($this->log);
		(new ReflectionProperty(RedisCache::class, 'client'))->setValue($cache, $redis);
		Config::loadAndInitWithRedisCache(
			redis: $cache,
			defaultConfigPath: $this->default,
			localConfigPath: $this->local,
			extras: $extras,
			redisKey: self::KEY,
			ttlSeconds: self::TTL,
			forceReload: $forceReload,
		);
	}
}
