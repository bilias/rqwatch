<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Core\Cache;

interface CacheInterface
{
	/** @return string|false the value, false when the key does not exist */
	public function get(string $key): mixed;

	// Redis stores text: encode anything else first
	public function set(
		string $key,
		string $value,
		?int $ttl = null
	): bool;

	public function has(string $key): bool;

	public function delete(string $key): bool;

	public function countByPrefix(string $prefix): int;

	public function deleteByPrefix(string $prefix): int;

	public function ttl(string $key): ?int;

	public function incr(string $key): ?int;

	public function expire(string $key, int $ttl): bool;
}
