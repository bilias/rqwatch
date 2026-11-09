<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The admin pages' Rspamd stats block when a stat_url answers 200 with
 something other than a JSON object (here the login page, as a proxy's error
 page would): that server is logged and left out, and with no server left
 the block is not shown. It used to be kept as an empty column, cached for
 the full TTL. Statistics are off in this harness: the test turns them on,
 with its own config and stats cache keys for the Redis leg.
*/

namespace Tests\Integration\Smoke;

use Tests\Integration\IntegrationTestCase;

final class RspamdStatTest extends IntegrationTestCase
{
	public function testNonJsonReplyIsLeftOut(): void {
		$statUrl = rtrim((string) getenv('INTEGRATION_BASE_URL'), '/') . '/login';
		$log = self::root() . '/logs/rqwatch.log';
		clearstatcache();
		$from = is_file($log) ? (int) filesize($log) : 0;

		self::withEnv(['REDIS_CONFIG_KEY' => '"rqwatch_config_rspamd_stat_test"'], function () use ($statUrl): void {
			self::withLocalConfig(
				"\$rspamd_stat_disable = false;\n"
				. "\$rspamd_stat_redis_key = 'rqwatch_rspamd_stat_test';\n"
				. "\$API_SERVERS['mx2']['stat_url'] = '{$statUrl}';",
				function (): void {
					$response = self::client('admin')->get('/admin/search');
					$this->assertSame(200, $response['status']);
					$this->assertStringNotContainsString('Rspamd stats', $response['body']);
				}
			);
		});

		clearstatcache();
		$new = (string) file_get_contents($log, false, null, $from);
		$this->assertStringContainsString("Stat request to 'mx2' returned no JSON object", $new);
	}
}
