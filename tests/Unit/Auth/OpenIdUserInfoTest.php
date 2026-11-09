<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 OpenIDConnectAuth::applyUserInfo(): what the identity provider's userinfo
 allows. The username and e-mail must be usable (a username may be a
 number, never true; an e-mail is text), else the login is denied; a
 display name of another type is left out and logged by claim, so it can
 no longer fail the login with a TypeError.
*/

namespace Tests\Unit\Auth;

use App\Core\Auth\OpenIDConnectAuth;
use ReflectionMethod;
use ReflectionProperty;
use stdClass;
use Tests\Unit\UnitTestCase;

final class OpenIdUserInfoTest extends UnitTestCase
{
	/** @var array<non-empty-string, string|null> */
	private array $env = [];

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		foreach (['OPENIDC_REQUIRE_VERIFIED_EMAIL', 'OPENIDC_DEBUG_CLAIMS', 'OPENIDC_ADMINS'] as $key) {
			$this->env[$key] = isset($_ENV[$key]) && is_string($_ENV[$key]) ? $_ENV[$key] : null;
			unset($_ENV[$key]);
		}
	}

	#[\Override]
	protected function tearDown(): void {
		foreach ($this->env as $key => $value) {
			if ($value === null) {
				unset($_ENV[$key]);
			} else {
				$_ENV[$key] = $value;
			}
		}
		parent::tearDown();
	}

	public function testLogin(): void {
		$auth = new OpenIDConnectAuth($this->log);
		$this->assertTrue(self::apply($auth, self::userInfo()));
		$this->assertSame('alice', self::authenticatedUser($auth));
		$this->assertSame('alice@example.org', $auth->getEmail());
		$this->assertSame('Alice', $auth->getFirstName());
		$this->assertSame('Liddell', $auth->getLastName());
		$this->assertSame([], $this->log->lines);
	}

	public function testNumericUsername(): void {
		$auth = new OpenIDConnectAuth($this->log);
		$this->assertTrue(self::apply($auth, self::userInfo(['preferred_username' => 12345])));
		$this->assertSame('12345', self::authenticatedUser($auth));
	}

	public function testNotAnObject(): void {
		$this->assertFalse(self::apply(new OpenIDConnectAuth($this->log), 'eyJ...'));
		$this->assertLogged('OIDC userinfo is string, not an object');
	}

	// true would have logged in as "1"
	public function testUsernameTrueDenied(): void {
		$this->assertFalse(self::apply(new OpenIDConnectAuth($this->log), self::userInfo(['preferred_username' => true])));
		$this->assertLogged("OIDC claim 'preferred_username' is bool, not text");
	}

	// true would have logged in with the e-mail "1"
	public function testEmailTrueDenied(): void {
		$this->assertFalse(self::apply(new OpenIDConnectAuth($this->log), self::userInfo(['email' => true])));
		$this->assertLogged("OIDC claim 'email' for user 'alice' is bool, not text; login denied");
	}

	public function testEmailMissingDenied(): void {
		$info = self::userInfo();
		unset($info->email);
		$this->assertFalse(self::apply(new OpenIDConnectAuth($this->log), $info));
		$this->assertLogged("OIDC user 'alice' has no email claim; login denied");
	}

	public function testEmailUnverifiedDenied(): void {
		$this->assertFalse(self::apply(new OpenIDConnectAuth($this->log), self::userInfo(['email_verified' => false])));
	}

	// a structured name used to be a TypeError on the ?string property
	public function testNameNotTextLeftOut(): void {
		$auth = new OpenIDConnectAuth($this->log);
		$this->assertTrue(self::apply($auth, self::userInfo(['family_name' => ['en' => 'Liddell']])));
		$this->assertNull($auth->getLastName());
		$this->assertSame('Alice', $auth->getFirstName());
		$this->assertLogged("OIDC claim 'family_name' for user 'alice' is array, not text; ignored");
	}

	/** @param array<string, mixed> $override */
	private static function userInfo(array $override = []): stdClass {
		$info = new stdClass();
		$claims = $override + [
			'sub' => 'a1b2', 'preferred_username' => 'Alice', 'email' => 'Alice@Example.org',
			'email_verified' => true, 'given_name' => 'Alice', 'family_name' => 'Liddell',
		];
		/** @var mixed $value */
		foreach ($claims as $claim => $value) {
			$info->{$claim} = $value;
		}
		return $info;
	}

	private static function apply(OpenIDConnectAuth $auth, mixed $userInfo): mixed {
		return (new ReflectionMethod($auth, 'applyUserInfo'))->invoke($auth, $userInfo, 'preferred_username');
	}

	private static function authenticatedUser(OpenIDConnectAuth $auth): mixed {
		return (new ReflectionProperty($auth, 'authenticatedUser'))->getValue($auth);
	}
}
