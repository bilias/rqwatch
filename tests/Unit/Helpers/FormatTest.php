<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Display formats: sizes in B, K, M and G with a decimal comma (and a dot
 between thousands), "Null" for nothing; a TTL in minutes and seconds.
*/

namespace Tests\Unit\Helpers;

use App\Utils\Helper;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Unit\UnitTestCase;

final class FormatTest extends UnitTestCase
{
	/** @return array<string, array{int|float|numeric-string|null, string}> */
	public static function sizeCases(): array {
		return [
			'nothing' => [0, 'Null'],
			'null' => [null, 'Null'],
			'one byte' => [1, '1B'],
			'bytes' => [512, '512B'],
			'one K' => [1024, '1,0K'],
			'K' => [1536, '1,5K'],
			'M' => [5 * 1048576, '5,0M'],
			'a float' => [1536.0, '1,5K'],
			'G' => [2684354560, '2,5G'],
			'thousands of G' => [1234567890123, '1.149,8G'],
			'a SUM() result' => ['2048', '2,0K'],
		];
	}

	/** @param int|float|numeric-string|null $bytes */
	#[DataProvider('sizeCases')]
	public function testFormatSizeUnits(int|float|string|null $bytes, string $expected): void {
		$this->assertSame($expected, Helper::formatSizeUnits($bytes));
	}

	/** @return array<string, array{int, string}> */
	public static function ttlCases(): array {
		return [
			'zero' => [0, '0s'],
			'seconds' => [59, '59s'],
			'one minute' => [60, '1m 0s'],
			'minutes and seconds' => [125, '2m 5s'],
			'an hour, in minutes' => [3600, '60m 0s'],
		];
	}

	#[DataProvider('ttlCases')]
	public function testFormatTtlHuman(int $ttl, string $expected): void {
		$this->assertSame($expected, Helper::formatTtlHuman($ttl));
	}
}
