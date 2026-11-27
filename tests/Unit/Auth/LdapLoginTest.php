<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 LdapAuth's login on FakeLdapDirectory. The directory at LDAP_URI is asked
 for LDAP version 3, bound as LDAP_BIND_DN (anonymous without one), and
 searched under LDAP_BASE by LDAP_LOGIN_ATTR (mail by default), the
 username escaped, for the mail, surname and given name attributes (mail,
 sn, givenName by default), and the alias and fallback ones when set; then
 its one entry is bound as with the password. The user is the username
 trimmed in lower case, an admin when in LDAP_ADMINS. The mail is the first
 of the mail values in sort order, trimmed and in lower case; the others
 and the alias values are aliases, in lower case, once, without blanks or
 the mail. Several mail values are refused when LDAP_MAIL_ATTR_MULTIVALUE
 is false. A blank surname or given name falls back to its *_FALLBACK
 attribute. Each failure on the way is logged, with the directory's error
 and diagnostic where it has one, and refuses the login.
*/

namespace Tests\Unit\Auth;

use App\Core\Auth\LDAP\LdapDirectory;
use App\Core\Auth\LdapAuth;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use RuntimeException;
use Tests\Unit\FakeLdapDirectory;
use Tests\Unit\UnitTestCase;

final class LdapLoginTest extends UnitTestCase
{
	private FakeLdapDirectory $directory;

	/** @var list<string> the URIs the directory was opened for */
	private array $opened = [];

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->directory = new FakeLdapDirectory();
		$this->directory->attributes = self::attributes(['mail' => ['bob@example.org', 'alice@example.org'], 'sn' => ['Smith'], 'givenName' => ['Alice']]);
		$this->open(fn (): LdapDirectory => $this->directory);
		$this->setEnv('LDAP_URI', 'ldaps://ldap.example.org');
		$this->setEnv('LDAP_BASE', 'ou=people,dc=example,dc=org');
		$this->setEnv('LDAP_BIND_DN', 'cn=rqwatch,dc=example,dc=org');
		$this->setEnv('LDAP_BIND_PASS', 'service-secret');
		foreach (['LDAP_LOGIN_ATTR', 'LDAP_MAIL_ATTR', 'LDAP_MAIL_ALIAS_ATTR', 'LDAP_SN_ATTR', 'LDAP_GIVENNAME_ATTR',
			'LDAP_SN_ATTR_FALLBACK', 'LDAP_GIVENNAME_ATTR_FALLBACK', 'LDAP_MAIL_ATTR_MULTIVALUE', 'LDAP_ADMINS'] as $setting) {
			$this->setEnv($setting, null);
		}
	}

	#[\Override]
	protected function tearDown(): void {
		(new ReflectionProperty(LdapAuth::class, 'directory'))->setValue(null, null);
		parent::tearDown();
	}

	/** @param \Closure(): ?LdapDirectory $directory */
	private function open(\Closure $directory): void {
		(new ReflectionProperty(LdapAuth::class, 'directory'))->setValue(null, function (string $uri) use ($directory): ?LdapDirectory {
			$this->opened[] = $uri;
			return $directory();
		});
	}

	/**
	 * ldap_get_attributes()' shape: 'count', the names by number, and each name's values with their 'count'
	 * @param array<string, list<string>> $values
	 * @return array<array-key, mixed>
	 */
	private static function attributes(array $values): array {
		$attributes = ['count' => count($values)];
		foreach (array_keys($values) as $i => $name) {
			$attributes[$i] = $name;
			$attributes[$name] = ['count' => count($values[$name])] + $values[$name];
		}
		return $attributes;
	}

	/** @return list<mixed> user, admin, mail, aliases, first name, last name */
	private static function user(LdapAuth $auth): array {
		return [$auth->getAuthenticatedUser(), $auth->getIsAdmin(), $auth->getEmail(), $auth->getEmailAliases(), $auth->getFirstName(), $auth->getLastName()];
	}

	public function testLogin(): void {
		$auth = new LdapAuth(' Alice@Example.org', 'user-secret', $this->log);
		$this->assertTrue($auth->authenticate());
		$this->assertSame(['alice@example.org', false, 'alice@example.org', ['bob@example.org'], 'Alice', 'Smith'], self::user($auth));
		$this->assertSame(['ldaps://ldap.example.org'], $this->opened);
		$this->assertSame([
			'setOption ' . LDAP_OPT_PROTOCOL_VERSION . ' 3',
			'bind cn=rqwatch,dc=example,dc=org service-secret',
			'search ou=people,dc=example,dc=org (mail= Alice@Example.org) mail,sn,givenName',
			'countEntries',
			'firstEntry',
			'getDn',
			'getAttributes',
			'bind uid=alice,ou=people,dc=example,dc=org user-secret',
		], $this->directory->calls);
		$this->assertSame([], $this->log->lines);
	}

	public function testAdmin(): void {
		$this->setEnv('LDAP_ADMINS', 'root@example.org, ALICE@example.org ');
		$auth = new LdapAuth('alice@example.org', 'user-secret', $this->log);
		$this->assertTrue($auth->authenticate());
		$this->assertTrue($auth->getIsAdmin());
	}

	// every setting, the optional ones given
	public function testSettings(): void {
		$this->setEnv('LDAP_BIND_DN', null);
		$this->setEnv('LDAP_BIND_PASS', null);
		$this->setEnv('LDAP_LOGIN_ATTR', 'uid');
		$this->setEnv('LDAP_MAIL_ATTR', 'mailPrimaryAddress');
		$this->setEnv('LDAP_MAIL_ALIAS_ATTR', 'mailAlternateAddress');
		$this->setEnv('LDAP_SN_ATTR', 'sn;lang-el');
		$this->setEnv('LDAP_GIVENNAME_ATTR', 'givenName;lang-el');
		$this->setEnv('LDAP_SN_ATTR_FALLBACK', 'sn');
		$this->setEnv('LDAP_GIVENNAME_ATTR_FALLBACK', 'givenName');
		$this->directory->attributes = self::attributes([
			'mailPrimaryAddress' => [' Alice@Example.org '],
			'mailAlternateAddress' => ['alice@example.org', ' A.Smith@example.org ', 'a.smith@example.org', ' '],
			'sn' => ['Smith'],
			'givenName;lang-el' => [' '],
			'givenName' => ['Alice'],
		]);
		$auth = new LdapAuth('asmith', 'user-secret', $this->log);
		$this->assertTrue($auth->authenticate());
		$this->assertSame(['asmith', false, 'alice@example.org', ['a.smith@example.org'], 'Alice', 'Smith'], self::user($auth));
		$this->assertSame('bind  ', $this->directory->calls[1] ?? null);
		$this->assertSame('search ou=people,dc=example,dc=org (uid=asmith) mailPrimaryAddress,sn;lang-el,givenName;lang-el,mailAlternateAddress,sn,givenName', $this->directory->calls[2] ?? null);
	}

	// set but empty: not asked for
	public function testEmptySettings(): void {
		foreach (['LDAP_MAIL_ALIAS_ATTR', 'LDAP_SN_ATTR_FALLBACK', 'LDAP_GIVENNAME_ATTR_FALLBACK'] as $setting) {
			$this->setEnv($setting, '');
		}
		$this->directory->attributes = self::attributes(['mail' => ['alice@example.org']]);
		$auth = new LdapAuth('alice@example.org', 'user-secret', $this->log);
		$this->assertTrue($auth->authenticate());
		$this->assertSame(['alice@example.org', false, 'alice@example.org', [], null, null], self::user($auth));
		$this->assertSame('search ou=people,dc=example,dc=org (mail=alice@example.org) mail,sn,givenName', $this->directory->calls[2] ?? null);
	}

	// the names given, though blank: no fallback asked
	public function testNamesWithoutFallback(): void {
		$this->setEnv('LDAP_SN_ATTR_FALLBACK', 'surname');
		$this->setEnv('LDAP_GIVENNAME_ATTR_FALLBACK', 'cn');
		$this->directory->attributes = self::attributes(['mail' => ['alice@example.org'], 'sn' => ['Smith'], 'givenName' => ['Alice'], 'surname' => ['S'], 'cn' => ['A']]);
		$auth = new LdapAuth('alice@example.org', 'user-secret', $this->log);
		$this->assertTrue($auth->authenticate());
		$this->assertSame(['Alice', 'Smith'], [$auth->getFirstName(), $auth->getLastName()]);
		// blank names and blank fallbacks: none
		$this->directory = new FakeLdapDirectory();
		$this->directory->attributes = self::attributes(['mail' => ['alice@example.org'], 'sn' => [''], 'givenName' => [' '], 'surname' => [' '], 'cn' => ['']]);
		$blank = new LdapAuth('alice@example.org', 'user-secret', $this->log);
		$this->assertTrue($blank->authenticate());
		$this->assertSame([null, null], [$blank->getFirstName(), $blank->getLastName()]);
	}

	public function testFilterEscaped(): void {
		$this->directory->count = 0;
		(new LdapAuth('a*)(uid=*\\', 'user-secret', $this->log))->authenticate();
		$this->assertSame('search ou=people,dc=example,dc=org (mail=a\\2a\\29\\28uid=\\2a\\5c) mail,sn,givenName', $this->directory->calls[2] ?? null);
	}

	/** @return array<string, array{?bool, bool, list<string>}> LDAP_MAIL_ATTR_MULTIVALUE, accepted, the log */
	public static function multivalue(): array {
		$warning = "warning LDAP user 'alice' has multiple values in 'mail' attribute. Authentication denied";
		return [
			'allowed by default' => [null, true, []],
			'allowed' => [true, true, []],
			'refused' => [false, false, [$warning]],
		];
	}

	/** @param list<string> $log */
	#[DataProvider('multivalue')]
	public function testSeveralMails(?bool $allowed, bool $accepted, array $log): void {
		$this->setEnv('LDAP_MAIL_ATTR_MULTIVALUE', $allowed === null ? null : ($allowed ? 'true' : 'false'));
		$this->assertSame($accepted, (new LdapAuth('alice', 'user-secret', $this->log))->authenticate());
		$this->assertSame($log, $this->log->lines);
		if (!$accepted) {
			$this->assertSame([['values' => ['bob@example.org', 'alice@example.org'], 'hint' => 'Set LDAP_MAIL_ATTR_MULTIVALUE=true in .env to allow and treat additional values as aliases.']], $this->log->contexts);
			$this->assertCount(7, $this->directory->calls);
		}
	}

	// one mail is not several, even when they are refused
	public function testOneMailWithoutMultivalue(): void {
		$this->setEnv('LDAP_MAIL_ATTR_MULTIVALUE', 'false');
		$this->directory->attributes = self::attributes(['mail' => ['alice@example.org']]);
		$this->assertTrue((new LdapAuth('alice', 'user-secret', $this->log))->authenticate());
	}

	/** @return array<string, array{\Closure(FakeLdapDirectory): mixed, int, string}> the directory's answer, the calls made, what is logged */
	public static function failures(): array {
		$error = "error: 'Invalid credentials'. diag: '80090308: LdapErr: DSID-0C09044E'";
		return [
			'no protocol version 3' => [fn (FakeLdapDirectory $d) => $d->option = false, 1, 'error ldap_set_option(LDAP_OPT_PROTOCOL_VERSION) failed'],
			'the service refused' => [fn (FakeLdapDirectory $d) => $d->binds = [false], 2, "error ldap_bind failed: {$error}. Check LDAP_BIND_DN and LDAP_BIND_PASS"],
			'search failed' => [fn (FakeLdapDirectory $d) => $d->found = false, 3, "error ldap_search failed: {$error}"],
			'count failed' => [fn (FakeLdapDirectory $d) => $d->count = -1, 4, "error ldap_count_entries failed: {$error}"],
			'not found' => [fn (FakeLdapDirectory $d) => $d->count = 0, 4, "warning User 'alice' not found in LDAP"],
			'two found' => [fn (FakeLdapDirectory $d) => $d->count = 2, 4, "error LDAP search for mail 'alice' returned 2 users"],
			'no entry' => [fn (FakeLdapDirectory $d) => $d->entry = false, 5, "error ldap_first_entry failed: {$error}"],
			'no dn' => [fn (FakeLdapDirectory $d) => $d->dn = false, 6, "error ldap_get_dn failed: {$error}"],
			'no attributes' => [fn (FakeLdapDirectory $d) => $d->attributes = [], 7, 'error ldap_get_attributes returned empty attributes'],
			'no mail' => [fn (FakeLdapDirectory $d) => $d->attributes = self::attributes(['mail' => [' '], 'sn' => ['Smith']]), 7, "error Empty mail attribute for LDAP user 'alice'"],
			'a wrong password' => [fn (FakeLdapDirectory $d) => $d->binds = [true, false], 8, "warning LDAP Authentication for user 'alice' failed: {$error}"],
			'mail 0' => [fn (FakeLdapDirectory $d) => $d->attributes = self::attributes(['mail' => ['0']]), 8, "error LDAP user 'alice' has empty 'mail' attribute after authentication"],
		];
	}

	/** @param \Closure(FakeLdapDirectory): mixed $answer the change to its answers (what the assignment gives is unused) */
	#[DataProvider('failures')]
	public function testFailure(\Closure $answer, int $calls, string $logged): void {
		$answer($this->directory);
		$this->directory->diagnostic = '80090308: LdapErr: DSID-0C09044E';
		$auth = new LdapAuth('alice', 'user-secret', $this->log);
		$this->assertFalse($auth->authenticate());
		$this->assertSame([$logged], $this->log->lines);
		$this->assertCount($calls, $this->directory->calls);
		$this->expectException(RuntimeException::class);
		$auth->getAuthenticatedUser();
	}

	public function testUriRefused(): void {
		$this->open(fn (): ?LdapDirectory => null);
		$this->assertFalse((new LdapAuth('alice', 'user-secret', $this->log))->authenticate());
		$this->assertSame(['error ldap_connect failed. Check LDAP_URI'], $this->log->lines);
		$this->assertSame(['ldaps://ldap.example.org'], $this->opened);
	}

	public function testBaseRequired(): void {
		$this->setEnv('LDAP_BASE', null);
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('LDAP_BASE is not set in .env');
		(new LdapAuth('alice', 'user-secret', $this->log))->authenticate();
	}

	// without a diagnostic: the error alone, as ldap_error() gave it
	public function testErrorWithoutDiagnostic(): void {
		$this->directory->binds = [false];
		$this->directory->error = "Can't contact LDAP server";
		(new LdapAuth('alice', 'user-secret', $this->log))->authenticate();
		$this->assertSame(["error ldap_bind failed: error: 'Can't contact LDAP server'. diag: ''. Check LDAP_BIND_DN and LDAP_BIND_PASS"], $this->log->lines);
	}
}
