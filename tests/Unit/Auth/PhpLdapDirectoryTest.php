<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 PhpLdapDirectory without a server. connect() only parses the URI, so a
 good one gives a directory and the options LdapAuth sets (protocol
 version, timeouts) can be set; a malformed one gives none (PHP warns).
 Nothing listens on port 1: a bind and a search fail there, with
 ldap_error()'s message and no diagnostic. A result or entry it did not
 give is refused before any ldap_* call.
*/

namespace Tests\Unit\Auth;

use App\Core\Auth\LDAP\PhpLdapDirectory;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Tests\Unit\UnitTestCase;

final class PhpLdapDirectoryTest extends UnitTestCase
{
	private static function directory(): PhpLdapDirectory {
		$directory = PhpLdapDirectory::connect('ldap://127.0.0.1:1');
		self::assertInstanceOf(PhpLdapDirectory::class, $directory);
		return $directory;
	}

	// the options LdapAuth sets, as the library takes them
	public function testConnect(): void {
		$directory = self::directory();
		$this->assertSame([true, true, true], [
			$directory->setOption(LDAP_OPT_PROTOCOL_VERSION, 3),
			$directory->setOption(LDAP_OPT_NETWORK_TIMEOUT, 5),
			$directory->setOption(LDAP_OPT_TIMEOUT, 5),
		]);
	}

	public function testMalformedUri(): void {
		$this->assertNull(@PhpLdapDirectory::connect('foo://x y'));
	}

	public function testNoServer(): void {
		$directory = self::directory();
		$this->assertSame(
			[false, "Can't contact LDAP server", '', false, "Can't contact LDAP server"],
			[@$directory->bind('cn=rqwatch,dc=example,dc=org', 'secret'), $directory->error(), $directory->diagnosticMessage(),
				@$directory->search('dc=example,dc=org', '(mail=alice@example.org)', ['mail']), $directory->error()]
		);
	}

	/** @return array<string, array{\Closure(PhpLdapDirectory, object): mixed, string}> the call, the refusal */
	public static function foreign(): array {
		return [
			'count' => [fn (PhpLdapDirectory $d, object $handle): int => $d->countEntries($handle), 'Not an LDAP search result: stdClass'],
			'first entry' => [fn (PhpLdapDirectory $d, object $handle): object|false => $d->firstEntry($handle), 'Not an LDAP search result: stdClass'],
			'dn' => [fn (PhpLdapDirectory $d, object $handle): string|false => $d->getDn($handle), 'Not an LDAP entry: stdClass'],
			'attributes' => [fn (PhpLdapDirectory $d, object $handle): array => $d->getAttributes($handle), 'Not an LDAP entry: stdClass'],
		];
	}

	/** @param \Closure(PhpLdapDirectory, object): mixed $call */
	#[DataProvider('foreign')]
	public function testForeignHandle(\Closure $call, string $refusal): void {
		$this->expectException(LogicException::class);
		$this->expectExceptionMessage($refusal);
		$call(self::directory(), new stdClass());
	}
}
