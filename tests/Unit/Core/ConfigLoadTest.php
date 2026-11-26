<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Config::loadConfig() goes through the Redis copy only with REDIS_ENABLE
 on and a cache to use; otherwise it reads the files. A missing file is
 no settings. The Redis key and TTL come from .env, AppConfig's defaults
 when unset (or blank, or not a number). A stub client stands in for Redis.
*/

namespace Tests\Unit\Core;

use App\Configuration\AppConfig;
use App\Configuration\Config;
use App\Core\Cache\RedisCache;
use PHPUnit\Framework\Attributes\DataProvider;
use Redis;
use ReflectionProperty;
use Tests\Unit\UnitTestCase;

final class ConfigLoadTest extends UnitTestCase
{
	private string $default;
	private string $local;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->default = (string) tempnam(sys_get_temp_dir(), 'rqwatch-config-');
		$this->local = (string) tempnam(sys_get_temp_dir(), 'rqwatch-config-local-');
		file_put_contents($this->default, "<?php\n\$plain = 'file';\n\$overridden = 'default';\n");
		file_put_contents($this->local, "<?php\n\$overridden = 'local';\n");
	}

	#[\Override]
	protected function tearDown(): void {
		@unlink($this->default);
		@unlink($this->local);
		parent::tearDown();
	}

	private function cache(Redis $redis): RedisCache {
		$cache = new RedisCache($this->log);
		(new ReflectionProperty(RedisCache::class, 'client'))->setValue($cache, $redis);
		return $cache;
	}

	private function load(?RedisCache $cache): void {
		Config::loadConfig($this->log, $cache, $this->default, $this->local, ['extra' => 1], 'rq_config', 300);
	}

	public function testFilesWithRedisOff(): void {
		$this->setEnv('REDIS_ENABLE', 'false');
		$redis = $this->createMock(Redis::class);
		$redis->expects($this->never())->method('get');
		$this->load($this->cache($redis));
		$this->assertSame(['file', 'local', 1], [Config::get('plain'), Config::get('overridden'), Config::get('extra')]);
	}

	public function testFilesWithoutCache(): void {
		$this->setEnv('REDIS_ENABLE', 'true');
		$this->load(null);
		$this->assertSame(['file', 'local', 1], [Config::get('plain'), Config::get('overridden'), Config::get('extra')]);
		$this->assertSame([], $this->log->lines);
	}

	public function testRedisCopy(): void {
		$this->setEnv('REDIS_ENABLE', 'true');
		$redis = $this->createMock(Redis::class);
		$redis->expects($this->once())->method('get')->with('rq_config')
			->willReturn(json_encode(['APP_VERSION' => AppConfig::VERSION, 'plain' => 'cached']));
		$this->load($this->cache($redis));
		$this->assertSame(['cached', null, 1], [Config::get('plain'), Config::get('overridden'), Config::get('extra')]);
		$this->assertSame(['debug Config [loadAndInitWithRedisCache]: Config is cached in Redis'], $this->log->lines);
	}

	public function testMissingFileIsNoSettings(): void {
		$this->assertSame([], Config::loadConfigFile($this->default . '-none'));
		$this->assertSame(['plain' => 'file', 'overridden' => 'default'], Config::loadConfigFile($this->default));
	}

	/** @return array<string, array{?string, string}> */
	public static function keys(): array {
		return [
			'unset' => [null, AppConfig::REDIS_CONFIG_KEY],
			'blank' => [' ', AppConfig::REDIS_CONFIG_KEY],
			'set' => [' rq_config ', 'rq_config'],
		];
	}

	#[DataProvider('keys')]
	public function testRedisConfigKey(?string $env, string $key): void {
		$this->setEnv('REDIS_CONFIG_KEY', $env);
		$this->assertSame($key, Config::redisConfigKey());
	}

	/** @return array<string, array{?string, int}> */
	public static function ttls(): array {
		return [
			'unset' => [null, AppConfig::REDIS_CONFIG_CACHE_TTL],
			'not a number' => ['1h', AppConfig::REDIS_CONFIG_CACHE_TTL],
			'a number' => ['600', 600],
			'zero' => ['0', 0],
		];
	}

	#[DataProvider('ttls')]
	public function testRedisConfigTtl(?string $env, int $ttl): void {
		$this->setEnv('REDIS_CONFIG_CACHE_TTL', $env);
		$this->assertSame($ttl, Config::redisConfigTtl());
	}

	public function testTtlLeft(): void {
		$redis = $this->createMock(Redis::class);
		$redis->expects($this->once())->method('ttl')->with('rq_config')->willReturn(120);
		$this->assertSame(120, Config::getRedisConfigTTL($this->cache($redis), 'rq_config'));
	}
}
