<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 LdapAuth attribute reading: getValue() and getValues() on an
 ldap_get_attributes() result, no directory needed. Before it asks the
 directory, authenticate() refuses a login without a username (blanks
 and '0' included) or without a password ('' and '0'; an empty one would
 be an unauthenticated bind), or with a NUL byte in the password. The
 password is taken as typed, blanks and all. Past those checks the
 directory's settings are read: here LDAP_URI is unset, which stops it.
*/

namespace Tests\Unit\Auth;

use App\Core\Auth\LdapAuth;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Unit\UnitTestCase;

final class LdapAuthTest extends UnitTestCase
{
	// ldap_get_attributes() shape: 'count', the names under numeric keys,
	// and each attribute's values with their own 'count'; a description with
	// options (cn;lang-el) is its own entry, named as the server returned it
	private const array ATTRS = [
		'count' => 5,
		0 => 'cn',
		'cn' => ['count' => 1, 0 => ' Alice '],
		1 => 'cn;lang-el',
		'cn;lang-el' => ['count' => 1, 0 => 'Αλίκη'],
		2 => 'mail',
		'mail' => ['count' => 2, 0 => 'a@example.org', 1 => 'b@example.org'],
		3 => 'description',
		'description' => ['count' => 3, 0 => '  ', 1 => 'kept', 2 => ''],
		4 => 'seeAlso',
		'seeAlso' => ['count' => 0],
	];

	/** @return array<string, array{string|null, string|null}> */
	public static function valueCases(): array {
		return [
			'first value, trimmed' => ['cn', 'Alice'],
			'attribute option: its own values' => ['cn;lang-el', 'Αλίκη'],
			'option not in the entry: no base-type fallback' => ['cn;lang-en', null],
			'names match case-insensitively' => ['CN;Lang-EL', 'Αλίκη'],
			'multi-valued: the first' => ['mail', 'a@example.org'],
			'no values' => ['seeAlso', null],
			'missing attribute' => ['givenName', null],
			'empty field' => ['', null],
			'null field' => [null, null],
			'only a separator' => [';', null],
			'only separators' => [';;', null],
			// not attribute entries: the numbered names (once a TypeError) and the count
			'numbered name key' => ['0', null],
			'count key' => ['count', null],
		];
	}

	#[DataProvider('valueCases')]
	public function testGetValue(?string $field, ?string $expected): void {
		$this->assertSame($expected, $this->ldap()->getValue(self::ATTRS, $field));
	}

	/** @return array<string, array{string|null, list<string>}> */
	public static function valuesCases(): array {
		return [
			'all values, in order' => ['mail', ['a@example.org', 'b@example.org']],
			'option not in the entry' => ['mail;binary', []],
			'names match case-insensitively' => ['Mail', ['a@example.org', 'b@example.org']],
			'blank values dropped, the rest untrimmed' => ['description', ['kept']],
			'no values' => ['seeAlso', []],
			'missing attribute' => ['givenName', []],
			'empty field' => ['', []],
			'null field' => [null, []],
			'only a separator' => [';', []],
			'only separators' => [';;', []],
			// not attribute entries: the numbered names (once a fatal error) and the count
			'numbered name key' => ['0', []],
			'count key' => ['count', []],
		];
	}

	/** @param list<string> $expected */
	#[DataProvider('valuesCases')]
	public function testGetValues(?string $field, array $expected): void {
		$this->assertSame($expected, $this->ldap()->getValues(self::ATTRS, $field));
	}

	private function ldap(): LdapAuth {
		return new LdapAuth('alice', 'unused', $this->log);
	}

	/** @return array<string, array{string, string}> username, password */
	public static function refused(): array {
		return [
			'no username' => ['', 'secret'],
			'blank username' => [" \t", 'secret'],
			'username 0' => ['0', 'secret'],
			'padded 0' => [' 0 ', 'secret'],
			'no password' => ['alice', ''],
			'password 0' => ['alice', '0'],
			'NUL in the password' => ['alice', "\0secret"],
		];
	}

	#[DataProvider('refused')]
	public function testRefusedBeforeTheDirectory(string $username, string $password): void {
		$this->setEnv('LDAP_URI', null);
		$this->assertFalse((new LdapAuth($username, $password, $this->log))->authenticate());
		$this->assertSame([], $this->log->lines);
	}

	/** @return array<string, array{string, string}> username, password */
	public static function passed(): array {
		return [
			'plain' => ['alice', 'secret'],
			'a blank password: its characters' => ['alice', '   '],
			'a padded password' => ['alice', ' secret '],
			'00' => ['00', '00'],
		];
	}

	// on to the directory: its settings, here missing
	#[DataProvider('passed')]
	public function testOnToTheDirectory(string $username, string $password): void {
		$this->setEnv('LDAP_URI', null);
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('LDAP_URI is not set in .env');
		(new LdapAuth($username, $password, $this->log))->authenticate();
	}
}
