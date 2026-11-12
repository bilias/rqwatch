<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The $import_spool switch: true and 1 are on, false, 0 and unset are off.
 Any other value is on, as config.php ships it, and logged naming the
 setting, so a typo never drops a mail the spool would have kept.
*/

namespace Tests\Unit\Import;

use App\Configuration\Config;
use App\Services\Import\MailLogSpool;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Unit\UnitTestCase;

final class SpoolSwitchTest extends UnitTestCase
{
	/** @return array<string, array{mixed, bool}> setting => on */
	public static function settings(): array {
		return [
			'true' => [true, true],
			'one' => [1, true],
			'false' => [false, false],
			'zero' => [0, false],
			'unset' => [null, false],
		];
	}

	#[DataProvider('settings')]
	public function testValid(mixed $value, bool $on): void {
		Config::set('import_spool', $value);
		$this->assertSame($on, MailLogSpool::enabled($this->log, 'QIDSPOOL1'));
		$this->assertSame([], $this->log->lines);
	}

	/** @return array<string, array{mixed, string}> setting => its type */
	public static function mistyped(): array {
		return [
			'text zero' => ['0', 'string'],
			'text no' => ['no', 'string'],
			'two' => [2, 'int'],
			'empty array' => [[], 'array'],
		];
	}

	#[DataProvider('mistyped')]
	public function testMistypedSpoolsAndLogs(mixed $value, string $type): void {
		Config::set('import_spool', $value);
		$this->assertTrue(MailLogSpool::enabled($this->log, 'QIDSPOOL1'));
		$this->assertLogged("[MailLogSpool] QIDSPOOL1: import_spool must be true or false, not {$type} in config; spooling, as config.php's default");
	}
}
