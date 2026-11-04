<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The run uses the backend it claims: qa/integration-tests.sh runs with
 REDIS_ENABLE=false, or with Redis (INTEGRATION_REDIS=1, or CI's Redis leg),
 and every other test runs unchanged either way. With Redis a login's session
 and the cached config must be in Redis, so that leg cannot pass on the file
 backend.
*/

namespace Tests\Integration\Smoke;

use PHPUnit\Framework\Attributes\TestDox;
use Redis;
use RuntimeException;
use Tests\Integration\IntegrationTestCase;

final class RedisModeTest extends IntegrationTestCase
{
	#[TestDox('Backend: without Redis, REDIS_ENABLE=false; with Redis, a login\'s session and the cached config are in Redis')]
	public function testBackend(): void {
		$env = (string) file_get_contents(self::root() . '/.env');
		if (getenv('INTEGRATION_REDIS_ENABLED') !== 'true') {
			$this->assertMatchesRegularExpression('/^REDIS_ENABLE=false$/m', $env);
			return;
		}
		$this->assertMatchesRegularExpression('/^REDIS_ENABLE=true$/m', $env);

		$redis = new Redis();
		$redis->connect('127.0.0.1', (int) getenv('INTEGRATION_REDIS_PORT'));
		$sessions = count(self::keys($redis, 'rqwatch_sess:*'));
		self::client('alice');
		$this->assertGreaterThan($sessions, count(self::keys($redis, 'rqwatch_sess:*')), 'sessions in Redis after a login');
		$this->assertNotSame([], self::keys($redis, 'rqwatch_config_*'), 'cached config in Redis');
	}

	/** @return array<int, string> */
	private static function keys(Redis $redis, string $pattern): array {
		$keys = $redis->keys($pattern);
		if (!is_array($keys)) {
			throw new RuntimeException("Redis KEYS {$pattern} failed");
		}
		return $keys;
	}
}
