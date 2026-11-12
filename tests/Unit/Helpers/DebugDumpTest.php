<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Helper::log_to_files(), the importer's $log_to_files debug dump: $_SERVER
 and the request headers as "key => value", a string as is and anything
 else as JSON ($_SERVER['argv'] is a list, which used to be written as
 "Array" with a warning), credentials redacted. A numeric header or
 variable name, which PHP stores as an int key, is written like any other.
*/

namespace Tests\Unit\Helpers;

use App\Utils\Helper;
use Tests\Unit\UnitTestCase;

final class DebugDumpTest extends UnitTestCase
{
	private string $dir = '';

	// the server variables before the test, put back after it
	/** @var array<array-key, mixed> */
	private array $server = [];

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/rqwatch-dump-' . bin2hex(random_bytes(4));
		$this->server = $_SERVER;
	}

	#[\Override]
	protected function tearDown(): void {
		$_SERVER = $this->server;
		$files = glob($this->dir . '/*');
		foreach ($files === false ? [] : $files as $file) {
			unlink($file);
		}
		if (is_dir($this->dir)) {
			rmdir($this->dir);
		}
		parent::tearDown();
	}

	public function testServerValues(): void {
		$_SERVER['argv'] = ['bin/cli.php', 'cron:import_spool'];
		$_SERVER['TEST_DUMP_PLAIN'] = 'plain text';
		$_SERVER['TEST_DUMP_DB_PASS'] = 'secret';
		$_SERVER['TEST_DUMP_FLAG'] = true;
		$_SERVER['TEST_DUMP_COUNT'] = 0;
		$_SERVER['TEST_DUMP_NULL'] = null;

		$this->assertTrue(Helper::log_to_files($this->dir, '[]', ['Host' => 'mx1.example'], 'Subject: x', ['subject' => 'x']));

		$server = (string) file_get_contents($this->dir . '/server');
		$this->assertStringContainsString("argv => [\"bin/cli.php\",\"cron:import_spool\"]\r\n", $server);
		$this->assertStringContainsString("TEST_DUMP_PLAIN => plain text\r\n", $server, 'a string as is');
		$this->assertStringContainsString("TEST_DUMP_DB_PASS => [redacted]\r\n", $server);
		$this->assertStringContainsString("TEST_DUMP_FLAG => true\r\n", $server);
		$this->assertStringContainsString("TEST_DUMP_COUNT => 0\r\n", $server);
		$this->assertStringContainsString("TEST_DUMP_NULL => null\r\n", $server);
		$this->assertStringNotContainsString('secret', $server);
		$this->assertSame("Host => mx1.example\r\n", (string) file_get_contents($this->dir . '/web_headers'));
	}

	public function testNumericNames(): void {
		$_SERVER['123'] = 'numeric variable';
		$this->assertArrayHasKey(123, $_SERVER, 'stored as an int key');

		$this->assertTrue(Helper::log_to_files($this->dir, '[]', ['123' => 'numeric header'], 'Subject: x', ['subject' => 'x']));

		$this->assertStringContainsString("123 => numeric variable\r\n", (string) file_get_contents($this->dir . '/server'));
		$this->assertSame("123 => numeric header\r\n", (string) file_get_contents($this->dir . '/web_headers'));
	}
}
