<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The login providers without their servers. A database login needs a
 username (blanks and '0' are none) and a password ('' and '0' are none,
 blanks are characters; a NUL byte is refused) before the database is
 asked; past those checks the lookup runs, here without a database. No
 provider answers for a user before a login, and none shows the password
 in a dump. AuthManager sends 'admin' to the database, an address to LDAP
 when LDAP_AUTH_ENABLED is on, anyone else to the database; a failed login
 sets no provider.
*/

namespace Tests\Unit\Auth;

use App\Core\Auth\AuthManager;
use App\Core\Auth\DbAuth;
use App\Core\Auth\LdapAuth;
use App\Core\Auth\OpenIDConnectAuth;
use Closure;
use Error;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use ReflectionProperty;
use RuntimeException;
use Tests\Unit\UnitTestCase;

final class AuthProvidersTest extends UnitTestCase
{
	/** @return array<string, array{string, string}> username, password */
	public static function refused(): array {
		return [
			'no username' => ['', 'secret'],
			'blank username' => [" \t", 'secret'],
			'line breaks for a username' => ["\r\n", 'secret'],
			'username 0' => ['0', 'secret'],
			'padded 0' => [' 0 ', 'secret'],
			'no password' => ['alice', ''],
			'password 0' => ['alice', '0'],
			'NUL in the password' => ['alice', "\0secret"],
		];
	}

	#[DataProvider('refused')]
	public function testDbRefused(string $username, string $password): void {
		$this->assertFalse((new DbAuth($username, $password))->authenticate());
	}

	/** @return array<string, array{string, string}> username, password */
	public static function passed(): array {
		return [
			'plain' => ['alice', 'secret'],
			'a blank password: its characters' => ['alice', '   '],
			'a line break for a password' => ['alice', "\n"],
			'00' => ['00', '00'],
		];
	}

	// on to the lookup: no database here
	#[DataProvider('passed')]
	public function testDbOnToTheLookup(string $username, string $password): void {
		$this->expectException(Error::class);
		$this->expectExceptionMessage('Call to a member function connection() on null');
		(new DbAuth($username, $password))->authenticate();
	}

	/** @return array<string, array{Closure(): mixed, string}> a getter called before a login, the method named */
	public static function getters(): array {
		$db = static fn (): DbAuth => new DbAuth('alice', 'secret');
		$ldap = static fn (): LdapAuth => new LdapAuth('alice', 'secret', new NullLogger());
		$oidc = static fn (): OpenIDConnectAuth => new OpenIDConnectAuth(new NullLogger());
		return [
			'DbAuth::getAuthenticatedUser' => [static fn (): mixed => $db()->getAuthenticatedUser(), 'DbAuth::getAuthenticatedUser'],
			'DbAuth::getIsAdmin' => [static fn (): mixed => $db()->getIsAdmin(), 'DbAuth::getIsAdmin'],
			'DbAuth::getEmail' => [static fn (): mixed => $db()->getEmail(), 'DbAuth::getEmail'],
			'DbAuth::getId' => [static fn (): mixed => $db()->getId(), 'DbAuth::getId'],
			'LdapAuth::getAuthenticatedUser' => [static fn (): mixed => $ldap()->getAuthenticatedUser(), 'LdapAuth::getAuthenticatedUser'],
			'LdapAuth::getIsAdmin' => [static fn (): mixed => $ldap()->getIsAdmin(), 'LdapAuth::getIsAdmin'],
			'LdapAuth::getEmail' => [static fn (): mixed => $ldap()->getEmail(), 'LdapAuth::getEmail'],
			'LdapAuth::getEmailAliases' => [static fn (): mixed => $ldap()->getEmailAliases(), 'LdapAuth::getEmailAliases'],
			'LdapAuth::getFirstName' => [static fn (): mixed => $ldap()->getFirstName(), 'LdapAuth::getFirstName'],
			'LdapAuth::getLastName' => [static fn (): mixed => $ldap()->getLastName(), 'LdapAuth::getLastName'],
			'OpenIDConnectAuth::getAuthenticatedUser' => [static fn (): mixed => $oidc()->getAuthenticatedUser(), 'OpenIDConnectAuth::getAuthenticatedUser'],
			'OpenIDConnectAuth::getIsAdmin' => [static fn (): mixed => $oidc()->getIsAdmin(), 'OpenIDConnectAuth::getIsAdmin'],
			'OpenIDConnectAuth::getEmail' => [static fn (): mixed => $oidc()->getEmail(), 'OpenIDConnectAuth::getEmail'],
			'OpenIDConnectAuth::getEmailAliases' => [static fn (): mixed => $oidc()->getEmailAliases(), 'OpenIDConnectAuth::getEmailAliases'],
			'OpenIDConnectAuth::getFirstName' => [static fn (): mixed => $oidc()->getFirstName(), 'OpenIDConnectAuth::getFirstName'],
			'OpenIDConnectAuth::getLastName' => [static fn (): mixed => $oidc()->getLastName(), 'OpenIDConnectAuth::getLastName'],
		];
	}

	/** @param Closure(): mixed $getter */
	#[DataProvider('getters')]
	public function testNoUserBeforeLogin(Closure $getter, string $method): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("No user authenticated. We should not call this! (App\\Core\\Auth\\{$method})");
		$getter();
	}

	public function testDbAfterLogin(): void {
		$db = new DbAuth('alice', 'secret');
		self::set($db, ['authenticatedUser' => 'alice', 'is_admin' => true, 'email' => 'alice@example.org', 'user_id' => 7]);
		$this->assertSame(['alice', true, 'alice@example.org', 7], [$db->getAuthenticatedUser(), $db->getIsAdmin(), $db->getEmail(), $db->getId()]);
		$auth = new AuthManager($this->log);
		(new ReflectionProperty($auth, 'provider'))->setValue($auth, $db);
		$this->assertSame('alice', $auth->getAuthenticatedUser());
	}

	public function testDumpsHideThePassword(): void {
		$this->assertSame(
			['username' => 'alice', 'password' => '***REDACTED***', 'is_admin' => false, 'email' => null, 'authenticatedUser' => null, 'user_id' => null],
			(new DbAuth('alice', 'secret'))->__debugInfo()
		);
		$this->assertSame(
			['username' => 'alice', 'password' => '***REDACTED***', 'is_admin' => false, 'email' => null, 'mail_aliases' => [],
				'firstname' => null, 'lastname' => null, 'authenticatedUser' => null],
			(new LdapAuth('alice', 'secret', $this->log))->__debugInfo()
		);
	}

	/** @return array<string, array{string, ?string, string, int}> username, LDAP_AUTH_ENABLED, provider, provider id */
	public static function selections(): array {
		return [
			'admin: the database' => ['admin', 'true', 'DB', 0],
			'an address with LDAP on' => ['alice@example.org', 'true', 'LDAP', 1],
			'an address with LDAP off' => ['alice@example.org', 'false', 'DB', 0],
			'an address, LDAP unset' => ['alice@example.org', null, 'DB', 0],
			'no address with LDAP on' => ['alice', 'true', 'DB', 0],
		];
	}

	// a password that ends the login before any server: the provider chosen shows
	#[DataProvider('selections')]
	public function testProviderSelected(string $username, ?string $ldap, string $provider, int $id): void {
		$this->setEnv('LDAP_AUTH_ENABLED', $ldap);
		$auth = new AuthManager($this->log);
		$this->assertFalse($auth->authenticate($username, ''));
		$this->assertSame([$provider, $id], [$auth->getAuthProvider(), $auth->getAuthProviderId()]);
		$this->assertNull($auth->getAuthenticatedUser(), 'no provider set');
	}

	public function testProviders(): void {
		$this->assertSame([0 => 'DB', 1 => 'LDAP', 2 => 'OPENIDC'], AuthManager::getAuthProviders());
		$auth = new AuthManager($this->log);
		$this->assertSame([null, 0], [$auth->getAuthProvider(), $auth->getAuthProviderId()], 'none chosen yet');
	}

	/** @param array<string, mixed> $values */
	private static function set(object $provider, array $values): void {
		/** @var mixed $value */
		foreach ($values as $name => $value) {
			(new ReflectionProperty($provider, $name))->setValue($provider, $value);
		}
	}
}
