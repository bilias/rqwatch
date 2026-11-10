<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace Tests\Unit;

use App\Core\Cache\CacheInterface;

// an in-memory cache that keeps every key it is asked to read and every prefix it is asked to count or delete
final class RecordingCache implements CacheInterface
{
	/** @var array<string, string> */
	public array $values = [];

	/** @var list<string> */
	public array $reads = [];

	/** @var list<string> */
	public array $prefixes = [];

	#[\Override]
	public function get(string $key): mixed {
		$this->reads[] = $key;
		return $this->values[$key] ?? false;
	}

	#[\Override]
	public function set(string $key, string $value, ?int $ttl = null): bool {
		$this->values[$key] = $value;
		return true;
	}

	#[\Override]
	public function has(string $key): bool {
		return isset($this->values[$key]);
	}

	#[\Override]
	public function delete(string $key): bool {
		unset($this->values[$key]);
		return true;
	}

	#[\Override]
	public function countByPrefix(string $prefix): int {
		$this->prefixes[] = $prefix;
		return 7;
	}

	#[\Override]
	public function deleteByPrefix(string $prefix): int {
		$this->prefixes[] = $prefix;
		return 7;
	}

	#[\Override]
	public function ttl(string $key): ?int {
		return null;
	}

	#[\Override]
	public function incr(string $key): ?int {
		return null;
	}

	#[\Override]
	public function expire(string $key, int $ttl): bool {
		return true;
	}
}
