<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Config's typed getters: the value as it is, null when unset, and an
 exception naming the setting when its type is wrong; nothing is converted.
*/

namespace Tests\Unit\Core;

use App\Configuration\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Unit\UnitTestCase;

final class ConfigAccessorTest extends UnitTestCase
{
	public function testValues(): void {
		Config::set('i', 50);
		Config::set('s', 'x');
		Config::set('b', true);
		Config::set('flag', 0);
		$this->assertSame(50, Config::getInt('i'));
		$this->assertSame('x', Config::getString('s'));
		$this->assertTrue(Config::getBool('b'));
		$this->assertFalse(Config::getBool('flag'));
	}

	public function testUnsetAndNullAreNull(): void {
		Config::set('n', null);
		foreach (['n', 'unset'] as $key) {
			$this->assertNull(Config::getInt($key));
			$this->assertNull(Config::getString($key));
			$this->assertNull(Config::getBool($key));
		}
	}

	public function testIntOr(): void {
		$this->assertSame(50, Config::getIntOr('unset', 50));
		Config::set('i', 0);
		$this->assertSame(50, Config::getIntOr('i', 50), '0 gives the default, as (int) X ?: 50 did');
		Config::set('i', -3);
		$this->assertSame(-3, Config::getIntOr('i', 50));
	}

	/** @return array<string, array{string, mixed, string}> getter, value, message */
	public static function wrongTypes(): array {
		return [
			'int as string' => ['getInt', '0644', 'x must be an integer, not string in config'],
			'int as float' => ['getInt', 50.0, 'x must be an integer, not float in config'],
			'string as int' => ['getString', 5, 'x must be a string, not int in config'],
			'flag as string' => ['getBool', 'false', 'x must be true or false, not string in config'],
			'flag as 2' => ['getBool', 2, 'x must be true or false, not int in config'],
		];
	}

	#[DataProvider('wrongTypes')]
	public function testWrongTypeThrows(string $getter, mixed $value, string $message): void {
		Config::set('x', $value);
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage($message);
		match ($getter) {
			'getInt' => Config::getInt('x'),
			'getString' => Config::getString('x'),
			'getBool' => Config::getBool('x'),
			default => throw new \LogicException("unknown getter {$getter}"),
		};
	}
}
