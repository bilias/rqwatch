<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 $_SERVER reader: a string is returned as is (an empty one too), an
 unset or non-string value is null. remote_addr() is REMOTE_ADDR, or
 'UNKNOWN' when that is unset or not a string.
*/

namespace Tests\Unit\Helpers;

use App\Utils\Helper;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Unit\UnitTestCase;

final class ServerVarTest extends UnitTestCase
{
	private const string KEY = 'RQWATCH_UNIT_SERVER_TEST';

	private ?string $savedAddr;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->savedAddr = Helper::server_string('REMOTE_ADDR');
	}

	#[\Override]
	protected function tearDown(): void {
		unset($_SERVER[self::KEY]);
		if ($this->savedAddr === null) {
			unset($_SERVER['REMOTE_ADDR']);
		} else {
			$_SERVER['REMOTE_ADDR'] = $this->savedAddr;
		}
		parent::tearDown();
	}

	public function testStringReturnedAsIs(): void {
		$_SERVER[self::KEY] = '192.0.2.7';
		$this->assertSame('192.0.2.7', Helper::server_string(self::KEY));
		$_SERVER[self::KEY] = '';
		$this->assertSame('', Helper::server_string(self::KEY));
	}

	public function testUnsetIsNull(): void {
		$this->assertNull(Helper::server_string(self::KEY));
	}

	/** @return array<string, array{list<string>|int|bool|null}> */
	public static function nonStrings(): array {
		return ['array' => [['192.0.2.7']], 'int' => [3232235527], 'null' => [null], 'bool' => [true]];
	}

	/** @param list<string>|int|bool|null $value */
	#[DataProvider('nonStrings')]
	public function testNonStringIsNull(array|int|bool|null $value): void {
		$_SERVER[self::KEY] = $value;
		$this->assertNull(Helper::server_string(self::KEY));
	}

	/** @return array<string, array{list<string>|string|null, string}> */
	public static function remoteAddrs(): array {
		return [
			'set' => ['2001:db8::7', '2001:db8::7'],
			'unset' => [null, 'UNKNOWN'],
			'not a string' => [['192.0.2.7'], 'UNKNOWN'],
		];
	}

	/** @param list<string>|string|null $value null: unset */
	#[DataProvider('remoteAddrs')]
	public function testRemoteAddr(array|string|null $value, string $expected): void {
		if ($value === null) {
			unset($_SERVER['REMOTE_ADDR']);
		} else {
			$_SERVER['REMOTE_ADDR'] = $value;
		}
		$this->assertSame($expected, Helper::remote_addr());
	}
}
