<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Core\Auth\LDAP;

use SensitiveParameter;

/*
 The LDAP calls LdapAuth makes on one connection, each as PHP's ldap_*
 function of that name. A search result and its entries are opaque: what
 search() and firstEntry() return is only given back. PhpLdapDirectory is
 the real directory; a unit test gives LdapAuth another, so a login runs
 without a server.
*/
interface LdapDirectory
{
	public function setOption(int $option, int $value): bool;

	public function bind(string $dn, #[SensitiveParameter] string $password): bool;

	/**
	 * @param list<string> $attributes
	 * @return object|false the result, false when the search failed
	 */
	public function search(string $base, string $filter, array $attributes): object|false;

	// -1 on failure
	public function countEntries(object $result): int;

	public function firstEntry(object $result): object|false;

	public function getDn(object $entry): string|false;

	/** @return array<array-key, mixed> as ldap_get_attributes() gives them */
	public function getAttributes(object $entry): array;

	// ldap_error()
	public function error(): string;

	// the server's diagnostic message of the last call, '' without one
	public function diagnosticMessage(): string;
}
