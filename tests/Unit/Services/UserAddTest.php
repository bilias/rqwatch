<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 UserService::userAdd() refuses, before touching the database, a
 username that is empty once trimmed or '0' (quietly, as the form and
 user:add report it themselves) and a username or password that is not
 text, or an empty password (logged): none of them reaches the users table.
*/

namespace Tests\Unit\Services;

use App\Services\UserService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Unit\UnitTestCase;

final class UserAddTest extends UnitTestCase
{
	/** @return array<string, array{array<string, mixed>}> */
	public static function quietRefusals(): array {
		return [
			'no data' => [[]],
			'no username' => [['password' => 'hash']],
			'empty username' => [['username' => '', 'password' => 'hash']],
			'spaces' => [['username' => '   ', 'password' => 'hash']],
			'zero' => [['username' => ' 0 ', 'password' => 'hash']],
		];
	}

	/** @param array<string, mixed> $data */
	#[DataProvider('quietRefusals')]
	public function testRefusedQuietly(array $data): void {
		$this->assertFalse((new UserService())->userAdd($data));
		$this->assertSame([], $this->log->lines);
	}

	public function testUsernameNotTextRefused(): void {
		$this->assertFalse((new UserService())->userAdd(['username' => ['alice'], 'password' => 'hash']));
		$this->assertLogged('userAdd: username is array, not text');
	}

	/** @return array<string, array{mixed}> */
	public static function unusablePasswords(): array {
		return ['absent' => [null], 'empty' => [''], 'not text' => [12345]];
	}

	#[DataProvider('unusablePasswords')]
	public function testPasswordRefused(mixed $password): void {
		$data = ['username' => ' Alice '] + ($password === null ? [] : ['password' => $password]);
		$this->assertFalse((new UserService())->userAdd($data));
		$this->assertLogged("userAdd: no usable password for 'alice'");
	}
}
