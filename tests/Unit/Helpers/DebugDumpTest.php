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
 "Array" with a warning), credentials redacted.
*/

namespace Tests\Unit\Helpers;

use App\Utils\Helper;
use Tests\Unit\UnitTestCase;

final class DebugDumpTest extends UnitTestCase
{
	private const array SERVER = ['argv', 'TEST_DUMP_PLAIN', 'TEST_DUMP_DB_PASS', 'TEST_DUMP_FLAG', 'TEST_DUMP_COUNT', 'TEST_DUMP_NULL'];

	private string $dir = '';

	/** @var array<string, mixed> */
	private array $saved = [];

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/rqwatch-dump-' . bin2hex(random_bytes(4));
		foreach (self::SERVER as $key) {
			if (array_key_exists($key, $_SERVER)) {
				/** @var mixed $value */
				$value = $_SERVER[$key];
				$this->saved[$key] = $value;
			}
		}
	}

	#[\Override]
	protected function tearDown(): void {
		foreach (self::SERVER as $key) {
			if (array_key_exists($key, $this->saved)) {
				$_SERVER[$key] = $this->saved[$key];
			} else {
				unset($_SERVER[$key]);
			}
		}
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
}
