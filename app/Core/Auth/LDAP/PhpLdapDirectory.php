<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Core\Auth\LDAP;

use LDAP\Connection as LdapConnection;
use LDAP\Result as LdapResult;
use LDAP\ResultEntry as LdapResultEntry;

use LogicException;
use SensitiveParameter;

/*
 LdapDirectory on PHP's ldap functions. connect() only parses the URI: the
 server is first contacted by bind(). A result or entry must be one this
 directory gave.
*/
final class PhpLdapDirectory implements LdapDirectory
{
	private function __construct(private LdapConnection $ldap) {
	}

	// null when ldap_connect() refuses the URI
	public static function connect(string $uri): ?self {
		$ldap = ldap_connect($uri);
		return $ldap === false ? null : new self($ldap);
	}

	#[\Override]
	public function setOption(int $option, int $value): bool {
		return ldap_set_option($this->ldap, $option, $value);
	}

	#[\Override]
	public function bind(string $dn, #[SensitiveParameter] string $password): bool {
		return ldap_bind($this->ldap, $dn, $password);
	}

	#[\Override]
	public function search(string $base, string $filter, array $attributes): object|false {
		/** @var LdapResult|false $result one connection, so never the parallel-search array */
		$result = ldap_search($this->ldap, $base, $filter, $attributes);
		return $result;
	}

	#[\Override]
	public function countEntries(object $result): int {
		return ldap_count_entries($this->ldap, self::result($result));
	}

	#[\Override]
	public function firstEntry(object $result): object|false {
		return ldap_first_entry($this->ldap, self::result($result));
	}

	#[\Override]
	public function getDn(object $entry): string|false {
		return ldap_get_dn($this->ldap, self::entry($entry));
	}

	#[\Override]
	public function getAttributes(object $entry): array {
		return ldap_get_attributes($this->ldap, self::entry($entry));
	}

	#[\Override]
	public function error(): string {
		return ldap_error($this->ldap);
	}

	#[\Override]
	public function diagnosticMessage(): string {
		$diag = '';
		ldap_get_option($this->ldap, LDAP_OPT_DIAGNOSTIC_MESSAGE, $diag);
		return is_string($diag) ? $diag : '';
	}

	private static function result(object $result): LdapResult {
		if (!$result instanceof LdapResult) {
			throw new LogicException('Not an LDAP search result: ' . get_debug_type($result));
		}
		return $result;
	}

	private static function entry(object $entry): LdapResultEntry {
		if (!$entry instanceof LdapResultEntry) {
			throw new LogicException('Not an LDAP entry: ' . get_debug_type($entry));
		}
		return $entry;
	}
}
