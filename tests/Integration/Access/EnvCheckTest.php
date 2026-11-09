<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 A failing .env key stops the web pages, the mail APIs and the CLI at boot:
 clients get a generic 503, the key is named in rqwatch.log and on the
 console. The metadata importer logs and keeps importing (EndToEnd).
*/

namespace Tests\Integration\Access;

use PHPUnit\Framework\Attributes\TestDox;
use RuntimeException;
use Tests\Integration\IntegrationTestCase;

final class EnvCheckTest extends IntegrationTestCase
{
	private const string FAILURE = 'DEBUG_SEARCH_SQL is not a boolean';

	#[TestDox('A mistyped boolean in .env: web and mail API answer 503, the CLI exits non-zero, the key is logged')]
	public function testFailingKeyStopsBoot(): void {
		$log = self::root() . '/logs/rqwatch.log';
		clearstatcache();
		$before = is_file($log) ? (int) filesize($log) : 0;

		self::withEnv(['DEBUG_SEARCH_SQL' => 'maybe'], function () use ($log, $before): void {
			$page = self::anonymous()->get('/login');
			$this->assertSame(503, $page['status'], 'web');
			$this->assertStringNotContainsString('DEBUG_SEARCH_SQL', $page['body'], 'key named to a web client');

			[$status, $body] = self::mailApi('get_mail.php', ['id' => 101]);
			$this->assertSame(503, $status, 'mail API');
			$this->assertStringNotContainsString('DEBUG_SEARCH_SQL', $body, 'key named to an API client');

			try {
				self::cli('list');
				$this->fail('the CLI booted');
			} catch (RuntimeException $e) {
				$this->assertStringContainsString(self::FAILURE, $e->getMessage(), 'CLI');
			}

			clearstatcache();
			$this->assertStringContainsString(self::FAILURE, (string) file_get_contents($log, false, null, $before), 'rqwatch.log');
		});

		$this->assertSame(200, self::anonymous()->get('/login')['status'], '.env restored');
	}
}
