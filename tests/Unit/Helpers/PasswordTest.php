<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Local passwords: hashed with the configured $password_hash algorithm, and
 no hash for none. A password verifies only against its own hash; an empty
 password or hash never does ('' and '0' are none, as empty() has it).
*/

namespace Tests\Unit\Helpers;

use App\Configuration\Config;
use App\Utils\Helper;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Unit\UnitTestCase;

final class PasswordTest extends UnitTestCase
{
	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		Config::init(['password_hash' => PASSWORD_BCRYPT]);
	}

	public function testHashWithTheConfiguredAlgorithm(): void {
		$hash = Helper::passwordHash('correct horse');
		$this->assertIsString($hash);
		$this->assertSame(PASSWORD_BCRYPT, password_get_info($hash)['algo']);
		$this->assertTrue(password_verify('correct horse', $hash));
	}

	public function testNoPasswordNoHash(): void {
		$this->assertNull(Helper::passwordHash(''));
		$this->assertNull(Helper::passwordHash('0'));
	}

	/** @return array<string, array{string, string, bool}> */
	public static function verifyCases(): array {
		// cost 4, the lowest: these only need to verify
		$hash = password_hash('correct horse', PASSWORD_BCRYPT, ['cost' => 4]);
		return [
			'its password' => ['correct horse', $hash, true],
			'another password' => ['wrong horse', $hash, false],
			'empty password, its hash' => ['', password_hash('', PASSWORD_BCRYPT, ['cost' => 4]), false],
			'0, its hash' => ['0', password_hash('0', PASSWORD_BCRYPT, ['cost' => 4]), false],
			'no hash' => ['correct horse', '', false],
		];
	}

	#[DataProvider('verifyCases')]
	public function testVerify(string $password, string $hash, bool $valid): void {
		$this->assertSame($valid, Helper::passwordVerify($password, $hash));
	}
}
