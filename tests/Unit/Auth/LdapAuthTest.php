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
 ldap_get_attributes() result, no directory needed.
*/

namespace Tests\Unit\Auth;

use App\Core\Auth\LdapAuth;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Unit\UnitTestCase;

final class LdapAuthTest extends UnitTestCase
{
	// ldap_get_attributes() shape: 'count', the names under numeric keys,
	// and each attribute's values with their own 'count'
	private const array ATTRS = [
		'count' => 4,
		0 => 'cn',
		'cn' => ['count' => 1, 0 => ' Alice '],
		1 => 'mail',
		'mail' => ['count' => 2, 0 => 'a@example.org', 1 => 'b@example.org'],
		2 => 'description',
		'description' => ['count' => 3, 0 => '  ', 1 => 'kept', 2 => ''],
		3 => 'seeAlso',
		'seeAlso' => ['count' => 0],
	];

	/** @return array<string, array{string|null, string|null}> */
	public static function valueCases(): array {
		return [
			'first value, trimmed' => ['cn', 'Alice'],
			'attribute option ignored' => ['cn;lang-en', 'Alice'],
			'multi-valued: the first' => ['mail', 'a@example.org'],
			'no values' => ['seeAlso', null],
			'missing attribute' => ['givenName', null],
			'empty field' => ['', null],
			'null field' => [null, null],
			// strtok() gives false: was array_key_exists(false) -> key 0 -> 'cn', a TypeError
			'only a separator' => [';', null],
			'only separators' => [';;', null],
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
			'attribute option ignored' => ['mail;binary', ['a@example.org', 'b@example.org']],
			'blank values dropped, the rest untrimmed' => ['description', ['kept']],
			'no values' => ['seeAlso', []],
			'missing attribute' => ['givenName', []],
			'empty field' => ['', []],
			'null field' => [null, []],
			'only a separator' => [';', []],
			'only separators' => [';;', []],
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
}
