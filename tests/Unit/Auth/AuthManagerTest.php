<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 AuthManager's user getters read the logged-in provider's own getters: DB,
 LDAP or OpenID Connect each answers what it has, and a getter the provider
 lacks gives the default. Each provider is built without its server, its
 properties set directly. isExternalAdmin() reads LDAP_ADMINS or
 OPENIDC_ADMINS from .env, case- and whitespace-insensitive.
*/

namespace Tests\Unit\Auth;

use App\Core\Auth\AuthInterface;
use App\Core\Auth\AuthManager;
use App\Core\Auth\DbAuth;
use App\Core\Auth\LdapAuth;
use App\Core\Auth\OpenIDConnectAuth;
use App\Utils\Helper;
use ReflectionProperty;
use Tests\Unit\UnitTestCase;

final class AuthManagerTest extends UnitTestCase
{
	private const array ADMIN_VARS = ['LDAP_ADMINS', 'OPENIDC_ADMINS'];

	/** @var array<non-empty-string, string|null> */
	private array $savedEnv = [];

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		foreach (self::ADMIN_VARS as $var) {
			$this->savedEnv[$var] = Helper::env_string($var);
			unset($_ENV[$var]);
		}
	}

	#[\Override]
	protected function tearDown(): void {
		foreach ($this->savedEnv as $var => $value) {
			if ($value === null) {
				unset($_ENV[$var]);
			} else {
				$_ENV[$var] = $value;
			}
		}
		parent::tearDown();
	}

	public function testIsExternalAdmin(): void {
		$_ENV['LDAP_ADMINS'] = ' Alice , bob,';
		$this->assertTrue(AuthManager::isExternalAdmin('LDAP', 'alice'));
		$this->assertTrue(AuthManager::isExternalAdmin('LDAP', ' BOB '));
		$this->assertFalse(AuthManager::isExternalAdmin('LDAP', 'carol'));
		// each provider reads its own list
		$this->assertFalse(AuthManager::isExternalAdmin('OPENIDC', 'alice'));
		$_ENV['OPENIDC_ADMINS'] = 'carol';
		$this->assertTrue(AuthManager::isExternalAdmin('OPENIDC', 'Carol'));
		$this->assertFalse(AuthManager::isExternalAdmin('LDAP', 'carol'));
	}

	public function testIsExternalAdminUnsetOrEmpty(): void {
		$this->assertFalse(AuthManager::isExternalAdmin('LDAP', 'alice'));
		$_ENV['OPENIDC_ADMINS'] = '';
		$this->assertFalse(AuthManager::isExternalAdmin('OPENIDC', 'alice'));
	}

	// DB users keep their stored is_admin
	public function testIsExternalAdminOtherProvider(): void {
		$_ENV['LDAP_ADMINS'] = 'alice';
		$this->assertNull(AuthManager::isExternalAdmin('DB', 'alice'));
		$this->assertNull(AuthManager::isExternalAdmin('ldap', 'alice'));
	}

	public function testDb(): void {
		$db = new DbAuth('alice', 'unused');
		self::set($db, ['authenticatedUser' => 'alice', 'is_admin' => true, 'email' => 'alice@example.org', 'user_id' => 7]);
		$auth = $this->manager($db);
		$this->assertSame(7, $auth->getUserId());
		$this->assertSame('alice@example.org', $auth->getUserEmail());
		$this->assertTrue($auth->getIsAdmin());
		// DB has none of these
		$this->assertNull($auth->getIdToken());
		$this->assertSame([], $auth->getUserEmailAliases());
		$this->assertNull($auth->getUserFirstName());
		$this->assertNull($auth->getUserLastName());
	}

	public function testLdap(): void {
		$ldap = new LdapAuth('alice', 'unused', $this->log);
		self::set($ldap, ['authenticatedUser' => 'alice', 'is_admin' => true, 'email' => 'alice@example.org', 'mail_aliases' => ['a@example.org'],
			'firstname' => 'Alice', 'lastname' => 'Liddell']);
		$auth = $this->manager($ldap);
		$this->assertSame('alice@example.org', $auth->getUserEmail());
		$this->assertSame(['a@example.org'], $auth->getUserEmailAliases());
		$this->assertSame('Alice', $auth->getUserFirstName());
		$this->assertSame('Liddell', $auth->getUserLastName());
		$this->assertTrue($auth->getIsAdmin());
		$this->assertNull($auth->getUserId());
		$this->assertNull($auth->getIdToken());
	}

	public function testOpenIdConnect(): void {
		$oidc = new OpenIDConnectAuth($this->log);
		self::set($oidc, ['authenticatedUser' => 'alice', 'is_admin' => true, 'email' => 'alice@example.org', 'mail_aliases' => ['a@example.org'],
			'firstname' => 'Alice', 'lastname' => 'Liddell', 'idToken' => 'token']);
		$auth = $this->manager($oidc);
		$this->assertSame('token', $auth->getIdToken());
		$this->assertSame('alice@example.org', $auth->getUserEmail());
		$this->assertSame(['a@example.org'], $auth->getUserEmailAliases());
		$this->assertSame('Alice', $auth->getUserFirstName());
		$this->assertSame('Liddell', $auth->getUserLastName());
		$this->assertTrue($auth->getIsAdmin());
		$this->assertNull($auth->getUserId());
	}

	public function testNoProvider(): void {
		$auth = new AuthManager($this->log);
		$this->assertNull($auth->getUserId());
		$this->assertNull($auth->getUserEmail());
		$this->assertNull($auth->getIdToken());
		$this->assertSame([], $auth->getUserEmailAliases());
		$this->assertFalse($auth->getIsAdmin());
	}

	private function manager(AuthInterface $provider): AuthManager {
		$auth = new AuthManager($this->log);
		(new ReflectionProperty(AuthManager::class, 'provider'))->setValue($auth, $provider);
		return $auth;
	}

	/** @param array<string, mixed> $values */
	private static function set(object $provider, array $values): void {
		/** @var mixed $value */
		foreach ($values as $name => $value) {
			(new ReflectionProperty($provider, $name))->setValue($provider, $value);
		}
	}
}
