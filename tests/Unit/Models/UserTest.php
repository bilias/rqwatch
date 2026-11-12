<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 User: session_version reads as an int whether the driver returns the
 column as an int or as its digits, and as null before the migration adds
 the column.
*/

namespace Tests\Unit\Models;

use App\Models\User;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Unit\UnitTestCase;

final class UserTest extends UnitTestCase
{
	#[TestDox('session_version is an int, also when the driver returns digits')]
	public function testSessionVersionInt(): void {
		$this->assertSame(3, (new User())->setRawAttributes(['id' => 1, 'session_version' => 3])->session_version);
		$this->assertSame(3, (new User())->setRawAttributes(['id' => 1, 'session_version' => '3'])->session_version);
	}

	#[TestDox('session_version is null when NULL or not selected')]
	public function testSessionVersionNull(): void {
		$this->assertNull((new User())->setRawAttributes(['id' => 1, 'session_version' => null])->session_version);
		$this->assertNull((new User())->setRawAttributes(['id' => 1])->session_version);
	}
}
