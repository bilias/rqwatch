<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Controller::getRspamdStat() on the paths that need no rspamd: stats off for
 non-admins and by config, even when the cache holds some; an empty
 stat_url, and RSPAMD_CONTROLLER_PASS missing from .env, which used to be a
 TypeError (a 500 on every admin page showing stats). A malformed stat_url
 fails at the request, before any connection; a refused connection when the
 reply is read. The Redis cache: stats and a cached failure are used, an
 invalid copy or a Redis error falls back to asking, and a total failure is
 cached for the shorter failure TTL. Replies from a real controller are left
 to the integration suite (Smoke/RspamdStatTest).
*/

namespace Tests\Unit\Controllers;

use App\Configuration\Config;
use App\Controllers\Controller;
use App\Controllers\MapController;
use App\Core\App;
use App\Core\AppContainer;
use App\Core\Cache\CacheInterface;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Tests\Unit\CountingCache;
use Tests\Unit\UnitTestCase;

final class RspamdStatTest extends UnitTestCase
{
	private ?string $password = null;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		// required at boot, so a booted app always has it
		Config::set('rspamd_stat_redis_key', 'rqwatch_rspamd_stats_test');
		/** @var mixed $saved */
		$saved = $_ENV['RSPAMD_CONTROLLER_PASS'] ?? null;
		$this->password = is_string($saved) ? $saved : null;
	}

	#[\Override]
	protected function tearDown(): void {
		if ($this->password === null) {
			unset($_ENV['RSPAMD_CONTROLLER_PASS']);
		} else {
			$_ENV['RSPAMD_CONTROLLER_PASS'] = $this->password;
		}
		parent::tearDown();
	}

	private const string KEY = 'rqwatch_rspamd_stats_test';
	private const string STATS = '{"mx1":{"scanned":5}}';

	public function testNonAdminGetsNone(): void {
		$this->cache()->values[self::KEY] = self::STATS;
		$this->assertSame([], $this->controller(false)->getRspamdStat());
		$this->assertSame([], $this->log->lines);
	}

	public function testDisabled(): void {
		$this->cache()->values[self::KEY] = self::STATS;
		Config::set('rspamd_stat_disable', true);
		$this->assertSame([], $this->controller(true)->getRspamdStat());
		$this->assertSame([], $this->log->lines);
	}

	public function testCachedStats(): void {
		$this->cache()->values[self::KEY] = self::STATS;
		$this->assertSame(['mx1' => ['scanned' => 5]], $this->controller(true)->getRspamdStat());
		$this->assertSame(['debug Rspamd stats loaded from Redis cache'], $this->log->lines);
	}

	// every server failed recently: not asked again yet
	public function testCachedFailure(): void {
		$this->cache()->values[self::KEY] = '[]';
		$this->assertSame([], $this->controller(true)->getRspamdStat());
		$this->assertSame(['debug Rspamd stats unavailable (cached failure)'], $this->log->lines);
	}

	public function testInvalidCopyAsksAgain(): void {
		$cache = $this->cache();
		$cache->values[self::KEY] = '"stats"';
		$this->assertSame([], $this->controller(true)->getRspamdStat());
		$this->assertSame([
			'warning Invalid Rspamd stats returned from Redis cache',
			'debug Rspamd stats cached in Redis for 60 seconds',
		], $this->log->lines);
		$this->assertSame('[]', $cache->values[self::KEY]);
	}

	public function testRedisErrorAsksAgain(): void {
		$cache = $this->createStub(CacheInterface::class);
		$cache->method('get')->willThrowException(new RuntimeException('Connection lost'));
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, null, null, $cache, false));
		$this->assertSame([], $this->controller(true)->getRspamdStat());
		$this->assertSame([
			'error Redis error when reading Rspamd stats: Connection lost',
			'debug Rspamd stats cached in Redis for 60 seconds',
		], $this->log->lines);
	}

	// nothing listens on port 1: the request starts, reading its reply fails
	public function testTotalFailureCachedForTheFailureTtl(): void {
		$cache = $this->cache();
		Config::set('API_SERVERS', [
			'mx1' => ['stat_url' => 'http://127.0.0.1:1/stat'],
			'mx2' => ['stat_url' => 'http://127.0.0.1:1/stat'],
		]);
		$_ENV['RSPAMD_CONTROLLER_PASS'] = 'secret';
		$this->assertSame([], $this->controller(true)->getRspamdStat());
		$this->assertCount(3, $this->log->lines);
		$this->assertStringStartsWith("error Stat request to 'mx1' failed: ", $this->log->lines[0]);
		$this->assertStringStartsWith("error Stat request to 'mx2' failed: ", $this->log->lines[1]);
		$this->assertSame('debug Rspamd stats cached in Redis for 60 seconds', $this->log->lines[2]);
		$this->assertSame(['[]', 60], [$cache->values[self::KEY], $cache->ttls[self::KEY]]);

		// never longer than the success TTL
		Config::set('rspamd_stat_redis_failure_ttl', 1000);
		unset($cache->values[self::KEY]);
		$this->controller(true)->getRspamdStat();
		$this->assertSame(300, $cache->ttls[self::KEY]);
	}

	// an empty stat_url is skipped, not the end: the next server is still asked
	public function testEveryServerAsked(): void {
		Config::set('API_SERVERS', ['mx1' => ['stat_url' => ''], 'mx2' => ['stat_url' => 'http://']]);
		$_ENV['RSPAMD_CONTROLLER_PASS'] = 'secret';
		$this->assertSame([], $this->controller(true)->getRspamdStat());
		$this->assertLogged("API server 'mx1' has an empty stat_url");
		$this->assertLogged("Stat request to 'mx2' failed: Malformed URL");
	}

	public function testEmptyStatUrl(): void {
		Config::set('API_SERVERS', ['mx1' => ['stat_url' => '']]);
		$_ENV['RSPAMD_CONTROLLER_PASS'] = 'secret';
		$this->assertSame([], $this->controller(true)->getRspamdStat());
		$this->assertLogged("API server 'mx1' has an empty stat_url");
	}

	public function testPasswordUnset(): void {
		Config::set('API_SERVERS', ['mx1' => ['stat_url' => 'http://']]);
		unset($_ENV['RSPAMD_CONTROLLER_PASS']);
		$this->assertSame([], $this->controller(true)->getRspamdStat());
		$this->assertLogged("Stat request to 'mx1' failed: Malformed URL");
	}

	// a cache in the app container, as REDIS_ENABLE gives one
	private function cache(): CountingCache {
		$cache = new CountingCache();
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, null, null, $cache, false));
		return $cache;
	}

	private function controller(bool $isAdmin): Controller {
		$controller = (new ReflectionClass(MapController::class))->newInstanceWithoutConstructor();
		(new ReflectionProperty(Controller::class, 'is_admin'))->setValue($controller, $isAdmin);
		(new ReflectionProperty(Controller::class, 'fileLogger'))->setValue($controller, $this->log);
		return $controller;
	}
}
