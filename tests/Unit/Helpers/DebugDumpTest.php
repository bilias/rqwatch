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
 "Array" with a warning), credentials redacted whatever the name's case,
 text unescaped. A numeric header or variable name, which PHP stores as an
 int key, is written like any other. The directory is created 0750 and
 logged; the symbols as pretty JSON, the headers as given and per name.
 A directory it cannot write is refused, logged (skipped as root, which
 writes anywhere); one it cannot create, or a dump file it cannot open,
 stops the dump, logged with PHP's reason.
*/

namespace Tests\Unit\Helpers;

use App\Utils\Helper;
use PHPUnit\Framework\Attributes\DataProvider;
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

	public function testUnwritableDirectoryRefused(): void {
		if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
			$this->markTestSkipped('root writes anywhere');
		}
		mkdir($this->dir, 0550);
		try {
			$this->assertFalse(Helper::log_to_files($this->dir, '[]', [], '', []));
		} finally {
			chmod($this->dir, 0750);
		}
		$this->assertSame(["error {$this->dir} is not writable"], $this->log->lines);
	}

	// a file where the directory has to go
	public function testDirectoryNotCreated(): void {
		file_put_contents($this->dir, 'x');
		try {
			$this->assertFalse(Helper::log_to_files($this->dir . '/sub', '[]', [], '', []));
		} finally {
			unlink($this->dir);
		}
		$this->assertSame([
			"warning {$this->dir}/sub does not exist",
			"error Error creating directory {$this->dir}/sub: mkdir(): Not a directory",
		], $this->log->lines);
	}

	/** @return array<string, array{string}> */
	public static function dumpFiles(): array {
		$files = ['web_headers', 'server', 'symbols', 'headers', 'headers_ar'];
		return array_combine($files, array_map(fn (string $file): array => [$file], $files));
	}

	// a directory where a dump file goes: the dump stops there
	#[DataProvider('dumpFiles')]
	public function testFileNotOpened(string $file): void {
		$path = "{$this->dir}/{$file}";
		mkdir($path, 0750, true);
		try {
			$this->assertFalse(Helper::log_to_files($this->dir, '[]', [], '', []));
		} finally {
			rmdir($path);
		}
		$this->assertSame(["error Cannot open file ({$path}): fopen({$path}): Failed to open stream: Is a directory"], $this->log->lines);
	}

	public function testServerValues(): void {
		$_SERVER['argv'] = ['bin/cli.php', 'cron:import_spool'];
		$_SERVER['TEST_DUMP_PLAIN'] = 'plain text';
		$_SERVER['TEST_DUMP_DB_PASS'] = 'secret';
		$_SERVER['TEST_DUMP_FLAG'] = true;
		$_SERVER['TEST_DUMP_COUNT'] = 0;
		$_SERVER['TEST_DUMP_NULL'] = null;
		$_SERVER['test_dump_lower_pass'] = 'secret too';
		$_SERVER['TEST_DUMP_TEXT'] = ['café/x'];

		$this->assertTrue(Helper::log_to_files($this->dir, '[]', ['Host' => 'mx1.example'], 'Subject: x', ['subject' => 'x']));

		$server = (string) file_get_contents($this->dir . '/server');
		$this->assertStringContainsString("argv => [\"bin/cli.php\",\"cron:import_spool\"]\r\n", $server);
		$this->assertStringContainsString("TEST_DUMP_PLAIN => plain text\r\n", $server, 'a string as is');
		$this->assertStringContainsString("TEST_DUMP_DB_PASS => [redacted]\r\n", $server);
		$this->assertStringContainsString("TEST_DUMP_FLAG => true\r\n", $server);
		$this->assertStringContainsString("TEST_DUMP_COUNT => 0\r\n", $server);
		$this->assertStringContainsString("TEST_DUMP_NULL => null\r\n", $server);
		$this->assertStringContainsString("test_dump_lower_pass => [redacted]\r\n", $server);
		$this->assertStringContainsString("TEST_DUMP_TEXT => [\"café/x\"]\r\n", $server);
		$this->assertStringNotContainsString('secret', $server);
		$this->assertSame("Host => mx1.example\r\n", (string) file_get_contents($this->dir . '/web_headers'));
	}

	public function testFilesAndLog(): void {
		$umask = umask(022);
		try {
			$this->assertTrue(Helper::log_to_files($this->dir, '{"A":{"name":"x/ü"}}', ['Host' => 'mx1.example'],
				"Subject: x\r\n", ['subject' => 'x', 'received' => ['a', 'b']]));
		} finally {
			umask($umask);
		}
		$perms = fileperms($this->dir);
		$this->assertIsInt($perms);
		$this->assertSame(0750, $perms & 0777);
		$this->assertSame("{\n    \"A\": {\n        \"name\": \"x/ü\"\n    }\n}", (string) file_get_contents($this->dir . '/symbols'));
		$this->assertSame("Subject: x\r\n", (string) file_get_contents($this->dir . '/headers'));
		$this->assertSame("subject => x\r\nreceived [0] => a\r\nreceived [1] => b\r\n", (string) file_get_contents($this->dir . '/headers_ar'));
		$this->assertSame([
			"warning {$this->dir} does not exist",
			"info Created directory {$this->dir}",
			"info Saved debug files in {$this->dir}",
		], $this->log->lines);
	}

	public function testNumericNames(): void {
		$_SERVER['123'] = 'numeric variable';
		$this->assertArrayHasKey(123, $_SERVER, 'stored as an int key');

		$this->assertTrue(Helper::log_to_files($this->dir, '[]', ['123' => 'numeric header'], 'Subject: x', ['subject' => 'x']));

		$this->assertStringContainsString("123 => numeric variable\r\n", (string) file_get_contents($this->dir . '/server'));
		$this->assertSame("123 => numeric header\r\n", (string) file_get_contents($this->dir . '/web_headers'));
	}
}
