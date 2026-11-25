<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Helper::failRequest() ends the process with status 1: on the command line
 (CLI_MODE) with the message on stderr, otherwise with the message as a
 plain-text response (PlainTextResponseTest has its headers). It exits, so
 it runs in a child PHP process here, which coverage and Infection do not
 see.
*/

namespace Tests\Unit\Helpers;

use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Unit\UnitTestCase;

final class FailRequestTest extends UnitTestCase
{
	/** @return array<string, array{string, string, string}> code before the call, stdout, stderr */
	public static function modes(): array {
		return [
			'command line' => ["define('CLI_MODE', true);", '', "Database unavailable\n"],
			'web, CLI_MODE false' => ["define('CLI_MODE', false);", 'Database unavailable', ''],
			'web, CLI_MODE undefined' => ['', 'Database unavailable', ''],
		];
	}

	#[DataProvider('modes')]
	public function testFailRequest(string $setup, string $stdout, string $stderr): void {
		$this->assertSame([1, $stdout, $stderr], self::runPhp("{$setup} App\\Utils\\Helper::failRequest('Database unavailable', 503);"));
	}

	/** @return array{int, string, string} exit status, stdout, stderr */
	private static function runPhp(string $code): array {
		$autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
		$process = proc_open(
			[PHP_BINARY, '-d', 'display_errors=stderr', '-r', 'require ' . var_export($autoload, true) . "; {$code}"],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes
		);
		if (!is_resource($process)) {
			throw new RuntimeException('Cannot start ' . PHP_BINARY);
		}
		$out = (string) stream_get_contents($pipes[1]);
		$err = (string) stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		return [proc_close($process), $out, $err];
	}
}
