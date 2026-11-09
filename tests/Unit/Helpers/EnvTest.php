<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 .env accessors: a set value is returned as is (an empty one too),
 an unset or non-string one is null, or an exception naming the key.
*/

namespace Tests\Unit\Helpers;

use App\Utils\Helper;
use RuntimeException;
use Tests\Unit\UnitTestCase;

final class EnvTest extends UnitTestCase
{
	private const string KEY = 'RQWATCH_UNIT_ENV_TEST';

	#[\Override]
	protected function tearDown(): void {
		unset($_ENV[self::KEY]);
		parent::tearDown();
	}

	public function testSetValue(): void {
		$_ENV[self::KEY] = 'mx1';
		$this->assertSame('mx1', Helper::env_string(self::KEY));
		$this->assertSame('mx1', Helper::env_required(self::KEY));
	}

	public function testEmptyValueIsSet(): void {
		$_ENV[self::KEY] = '';
		$this->assertSame('', Helper::env_string(self::KEY));
		$this->assertSame('', Helper::env_required(self::KEY));
	}

	public function testUnsetIsNull(): void {
		$this->assertNull(Helper::env_string(self::KEY));
	}

	public function testNonStringIsNull(): void {
		$_ENV[self::KEY] = ['x'];
		$this->assertNull(Helper::env_string(self::KEY));
	}

	public function testRequiredUnsetThrows(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage(self::KEY . ' is not set in .env');
		Helper::env_required(self::KEY);
	}
}
