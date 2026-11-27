<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 A directory for the unit tests, with no server: each call is recorded,
 with its arguments, and answered as set here. By default one user is
 found and every bind succeeds. A result or entry handed back that is not
 the one this directory gave is recorded as such.
*/

namespace Tests\Unit;

use App\Core\Auth\LDAP\LdapDirectory;
use SensitiveParameter;
use stdClass;

final class FakeLdapDirectory implements LdapDirectory
{
	/** @var list<string> each call: its name and arguments */
	public array $calls = [];

	public bool $option = true;

	/** @var list<bool> each bind's answer in turn: the service's, then the user's */
	public array $binds = [true, true];

	public bool $found = true;

	public int $count = 1;

	public bool $entry = true;

	public string|false $dn = 'uid=alice,ou=people,dc=example,dc=org';

	/** @var array<array-key, mixed> as ldap_get_attributes() gives them */
	public array $attributes = [];

	public string $error = 'Invalid credentials';

	public string $diagnostic = '';

	private readonly object $result;

	private readonly object $theEntry;

	public function __construct() {
		$this->result = new stdClass();
		$this->theEntry = new stdClass();
	}

	#[\Override]
	public function setOption(int $option, int $value): bool {
		$this->calls[] = "setOption {$option} {$value}";
		return $this->option;
	}

	#[\Override]
	public function bind(string $dn, #[SensitiveParameter] string $password): bool {
		$this->calls[] = "bind {$dn} {$password}";
		return array_shift($this->binds) ?? false;
	}

	#[\Override]
	public function search(string $base, string $filter, array $attributes): object|false {
		$this->calls[] = "search {$base} {$filter} " . implode(',', $attributes);
		return $this->found ? $this->result : false;
	}

	#[\Override]
	public function countEntries(object $result): int {
		$this->calls[] = 'countEntries' . ($result === $this->result ? '' : ' of another result');
		return $this->count;
	}

	#[\Override]
	public function firstEntry(object $result): object|false {
		$this->calls[] = 'firstEntry' . ($result === $this->result ? '' : ' of another result');
		return $this->entry ? $this->theEntry : false;
	}

	#[\Override]
	public function getDn(object $entry): string|false {
		$this->calls[] = 'getDn' . ($entry === $this->theEntry ? '' : ' of another entry');
		return $this->dn;
	}

	#[\Override]
	public function getAttributes(object $entry): array {
		$this->calls[] = 'getAttributes' . ($entry === $this->theEntry ? '' : ' of another entry');
		return $this->attributes;
	}

	#[\Override]
	public function error(): string {
		return $this->error;
	}

	#[\Override]
	public function diagnosticMessage(): string {
		return $this->diagnostic;
	}
}
