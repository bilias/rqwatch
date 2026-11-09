<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 DNS cache count and flush work on dns_resolv_redis_key's prefix only: with
 the key unset or blank they never reach Redis, where an empty prefix would
 match every key.
*/

namespace Tests\Unit\Helpers;

use App\Configuration\Config;
use App\Core\App;
use App\Core\AppContainer;
use App\Utils\Helper;
use RuntimeException;
use Tests\Unit\RecordingCache;
use Tests\Unit\UnitTestCase;

final class DnsCacheTest extends UnitTestCase
{
	private RecordingCache $cache;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$_ENV['REDIS_ENABLE'] = 'true';
		$this->cache = new RecordingCache();
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, null, null, $this->cache, false));
	}

	#[\Override]
	protected function tearDown(): void {
		unset($_ENV['REDIS_ENABLE']);
		parent::tearDown();
	}

	public function testPrefixFromConfig(): void {
		Config::set('dns_resolv_redis_key', 'rqwatch_dns_cache');
		$this->assertSame(7, Helper::count_dns_cache());
		$this->assertSame(7, Helper::flush_dns_cache());
		$this->assertSame(['rqwatch_dns_cache', 'rqwatch_dns_cache'], $this->cache->prefixes);
	}

	public function testUnsetKeyNeverReachesRedis(): void {
		$this->assertNull(Helper::count_dns_cache());
		try {
			Helper::flush_dns_cache();
			$this->fail('flush without a prefix');
		} catch (RuntimeException $e) {
			$this->assertSame('dns_resolv_redis_key is not set in config', $e->getMessage());
		}
		$this->assertSame([], $this->cache->prefixes);
	}

	public function testBlankKeyNeverReachesRedis(): void {
		Config::set('dns_resolv_redis_key', ' ');
		$this->assertNull(Helper::count_dns_cache());
		try {
			Helper::flush_dns_cache();
			$this->fail('flush with a blank prefix');
		} catch (RuntimeException) {
		}
		$this->assertSame([], $this->cache->prefixes);
	}
}
