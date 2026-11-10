<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Core\Cache;

use App\Utils\Helper;

use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\RedisAdapter;

use Redis;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class RedisCache implements CacheInterface
{
	private ?Redis $client = null;

	public function __construct(
		private readonly LoggerInterface $logger,
	) { }


	/** @return string|false */
	#[\Override]
	public function get(string $key): mixed {
		$value = $this->getConnection()->get($key);
		if (is_string($value) || $value === false) {
			return $value;
		}
		// the client itself in MULTI or pipeline mode: never for our keys
		$this->logger->warning('RedisCache get got ' . get_debug_type($value) . " for key '{$key}'");
		return false;
	}

	#[\Override]
	public function set(string $key, string $value, ?int $ttl = null): bool {
		if ($ttl !== null) {
			return (bool) $this->getConnection()->set(
				$key,
				$value,
				['ex' => $ttl]
			);
		}

		return (bool) $this->getConnection()->set($key, $value);
	}

	#[\Override]
	public function has(string $key): bool {
		return $this->getConnection()->exists($key) > 0;
	}

	#[\Override]
	public function delete(string $key): bool {
		return $this->getConnection()->del($key) > 0;
	}

	#[\Override]
	public function ttl(string $key): ?int {
		try {
			$ttl = $this->getConnection()->ttl($key);

			if (is_int($ttl) && $ttl >= 0) {
				return $ttl;
			}

			// -2: key gone (expired, possibly mid-request) - normal
			// -1: key exists without expiry - unexpected for our keys
			if ($ttl === -2) {
				$this->logger->debug("RedisCache ttl got -2 (expired/not found) for key '{$key}'");
			} else {
				$this->logger->warning("RedisCache ttl got " . (is_int($ttl) ? $ttl : get_debug_type($ttl)) . " for key '{$key}'");
			}

			return null;
		} catch (Throwable $e) {
			$this->logger->error("RedisCache ttl: " . $e->getMessage());

			return null;
		}
	}

	#[\Override]
	public function incr(string $key): ?int {
		try {
			$value = $this->getConnection()->incr($key);
			return is_int($value) ? $value : 0;
		} catch (Throwable $e) {
			$this->logger->error("RedisCache incr: " . $e->getMessage());
			return null;
		}
	}

	#[\Override]
	public function expire(string $key, int $ttl): bool {
		try {
			return (bool) $this->getConnection()->expire($key, $ttl);
		} catch (Throwable $e) {
			$this->logger->error("RedisCache expire: " . $e->getMessage());
			return false;
		}
	}

	#[\Override]
	public function countByPrefix(string $prefix): int {
		$count = 0;
		try {
			$connection = $this->getConnection();
			$cursor = null;
			do {
				$keys = $connection->scan($cursor, $prefix . '*', 100);
				if ($keys !== false) {
					$count += count($keys);
				}
			} while ($cursor != 0);
		} catch (Throwable $e) {
			$this->logger->error("RedisCache countByPrefix: " . $e->getMessage());
			throw $e;
		}
		return $count;
	}

	#[\Override]
	public function deleteByPrefix(string $prefix): int {
		$deleted = 0;
		try {
			$connection = $this->getConnection();
			$cursor = null;
			do {
				$keys = $connection->scan($cursor, $prefix . '*', 100);
				if ($keys !== false && !empty($keys)) {
					$n = $connection->del($keys);
					if (is_int($n)) {
						$deleted += $n;
					}
				}
			} while ($cursor != 0);
		} catch (Throwable $e) {
			$this->logger->error("RedisCache deleteByPrefix: " . $e->getMessage());
			throw $e;
		}
		return $deleted;
	}

	/**
	 * Return all keys matching a prefix with their remaining TTL.
	 * Uses SCAN, not KEYS, so it does not block the Redis instance.
	 *
	 * @return array<string,int> key => seconds remaining (-1 = no expiry)
	 */
	public function listByPrefix(string $prefix): array {
		$found = [];
		try {
			$connection = $this->getConnection();
			$cursor = null;
			do {
				$keys = $connection->scan($cursor, $prefix . '*', 100);
				if ($keys !== false && !empty($keys)) {
					foreach ($keys as $key) {
						$ttl = $connection->ttl($key);
						$found[$key] = is_int($ttl) ? $ttl : 0;
					}
				}
			} while ($cursor != 0);
		} catch (Throwable $e) {
			$this->logger->error("RedisCache listByPrefix: " . $e->getMessage());
			throw $e;
		}
		return $found;
	}

	public function getConnection(): Redis {
		if ($this->client === null) {
		// Redis Sentinel connection via phpredis or predis
			try {
				$client = RedisAdapter::createConnection(
					Helper::env_required('REDIS_DSN')
				);
				if (!$client instanceof Redis) {
					throw new RuntimeException('REDIS_DSN must give a phpredis Redis connection, got ' . get_debug_type($client));
				}
				$this->client = $client;
				$this->logger->debug('[RedisCache] Redis connection established');
			} catch (InvalidArgumentException $e) {
				$this->logger->error('[RedisCache]: ' . $e->getMessage());
				throw $e;
			} catch (Throwable $e) {
				$this->logger->error('[RedisCache]: ' . $e->getMessage());
				throw $e;
			}
		}

		return $this->client;
	}

}
