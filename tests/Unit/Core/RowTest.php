<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Typed column reads of raw database rows: text is read as is, an integer
 as an int or its digits (native or emulated prepares); null only through
 the nullable readers; anything else, or a missing column, throws naming
 the column.
*/

namespace Tests\Unit\Core;

use App\Core\Database\Row;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

final class RowTest extends TestCase
{
	private static function row(): stdClass {
		$row = new stdClass();
		$row->name = 'mail_logs';
		$row->empty = '';
		$row->none = null;
		$row->id = 42;
		$row->digits = '42';
		$row->negative = '-3';
		$row->zero = '0';
		$row->suffix = '12abc';
		$row->decimal = '1.5';
		$row->float = 1.5;
		$row->bool = true;
		$row->spaced = ' 7';
		$row->overflow = '99999999999999999999';
		return $row;
	}

	public function testText(): void {
		$this->assertSame('mail_logs', Row::string(self::row(), 'name'));
		$this->assertSame('', Row::string(self::row(), 'empty'));
		$this->assertNull(Row::nullableString(self::row(), 'none'));
		$this->assertSame('42', Row::string(self::row(), 'digits'), 'text is not converted');
	}

	public function testIntegers(): void {
		$this->assertSame(42, Row::int(self::row(), 'id'));
		$this->assertSame(42, Row::int(self::row(), 'digits'));
		$this->assertSame(-3, Row::int(self::row(), 'negative'));
		$this->assertSame(0, Row::int(self::row(), 'zero'));
		$this->assertNull(Row::nullableInt(self::row(), 'none'));
		$this->assertSame([3 => 7, 'n' => 8], Row::intValues([3 => '7', 'n' => 8], 'n'));
	}

	/** @return array<string, array{string, string}> */
	public static function notIntegers(): array {
		return [
			'trailing text' => ['suffix', 'string'],
			'decimal text' => ['decimal', 'string'],
			'float' => ['float', 'float'],
			'bool' => ['bool', 'bool'],
			'leading space' => ['spaced', 'string'],
			'out of range' => ['overflow', 'string'],
			'null' => ['none', 'null'],
		];
	}

	#[DataProvider('notIntegers')]
	public function testNotAnIntegerRefused(string $column, string $type): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("Database column '{$column}' must be an integer, not {$type}");
		Row::int(self::row(), $column);
	}

	public function testNotTextRefused(): void {
		$this->expectExceptionMessage("Database column 'id' must be text, not int");
		Row::string(self::row(), 'id');
	}

	public function testNullTextRefused(): void {
		$this->expectExceptionMessage("Database column 'none' must be text, not null");
		Row::string(self::row(), 'none');
	}

	public function testMissingColumnRefused(): void {
		$this->expectExceptionMessage("Database row has no column 'TABLE_NAME'");
		Row::nullableString(self::row(), 'TABLE_NAME');
	}

	public function testPluckedValueRefused(): void {
		$this->expectExceptionMessage("Database column 'ml.id' must be an integer, not string");
		Row::intValues([1, '2', 'x'], 'ml.id');
	}
}
