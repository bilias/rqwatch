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

// an in-memory cache whose incr() counts, keeping the TTL each key was last given and every expire() call
final class CountingCache implements CacheInterface
{
	/** @var array<string, string> */
	public array $values = [];

	/** @var array<string, int> */
	public array $ttls = [];

	/** @var list<array{string, int}> */
	public array $expires = [];

	#[\Override]
	public function get(string $key): mixed {
		return $this->values[$key] ?? false;
	}

	#[\Override]
	public function set(string $key, string $value, ?int $ttl = null): bool {
		$this->values[$key] = $value;
		unset($this->ttls[$key]);
		if ($ttl !== null) {
			$this->ttls[$key] = $ttl;
		}
		return true;
	}

	#[\Override]
	public function has(string $key): bool {
		return isset($this->values[$key]);
	}

	#[\Override]
	public function delete(string $key): bool {
		unset($this->values[$key], $this->ttls[$key]);
		return true;
	}

	#[\Override]
	public function countByPrefix(string $prefix): int {
		return count(array_filter(array_keys($this->values), static fn(string $k): bool => str_starts_with($k, $prefix)));
	}

	#[\Override]
	public function deleteByPrefix(string $prefix): int {
		$n = 0;
		foreach (array_keys($this->values) as $key) {
			if (str_starts_with($key, $prefix)) {
				$this->delete($key);
				$n++;
			}
		}
		return $n;
	}

	#[\Override]
	public function ttl(string $key): ?int {
		return $this->ttls[$key] ?? null;
	}

	#[\Override]
	public function incr(string $key): int {
		$count = (int) ($this->values[$key] ?? '0') + 1;
		$this->values[$key] = (string) $count;
		return $count;
	}

	#[\Override]
	public function expire(string $key, int $ttl): bool {
		$this->expires[] = [$key, $ttl];
		$this->ttls[$key] = $ttl;
		return true;
	}
}
