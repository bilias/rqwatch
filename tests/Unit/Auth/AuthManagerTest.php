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
 properties set directly.
*/

namespace Tests\Unit\Auth;

use App\Core\Auth\AuthInterface;
use App\Core\Auth\AuthManager;
use App\Core\Auth\DbAuth;
use App\Core\Auth\LdapAuth;
use App\Core\Auth\OpenIDConnectAuth;
use ReflectionProperty;
use Tests\Unit\UnitTestCase;

final class AuthManagerTest extends UnitTestCase
{
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
		foreach ($values as $name => $value) {
			(new ReflectionProperty($provider, $name))->setValue($provider, $value);
		}
	}
}
