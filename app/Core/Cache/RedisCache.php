<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Core\Cache;

use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\RedisAdapter;

use Redis;

use InvalidArgumentException;
use Throwable;

final class RedisCache implements CacheInterface
{
	private ?Redis $client = null;

	public function __construct(
		private readonly LoggerInterface $logger,
	) { }


	public function get(string $key): mixed {
		return $this->getConnection()->get($key);
	}

	public function set(string $key, mixed $value, ?int $ttl = null): bool {
		if ($ttl !== null) {
			return $this->getConnection()->set(
				$key,
				$value,
				['ex' => $ttl]
			);
		}

		return $this->getConnection()->set($key, $value);
	}

	public function has(string $key): bool {
		return $this->getConnection()->exists($key) > 0;
	}

	public function delete(string $key): bool {
		return $this->getConnection()->del($key) > 0;
	}

	public function ttl(string $key): ?int {
		try {
			$ttl = $this->getConnection()->ttl($key);

			if ($ttl >= 0) {
				return $ttl;
			}

			$this->logger->warning("RedisCache ttl got {$ttl} for key '{$key}'");

			return null; // either -1 (no expiry) or -2 (not found)
		} catch (Throwable $e) {
			$this->logger->error("RedisCache ttl: " . $e->getMessage());

			return null;
		}
	}

	public function incr(string $key): ?int {
		try {
			return (int) $this->getConnection()->incr($key);
		} catch (Throwable $e) {
			$this->logger->error("RedisCache incr: " . $e->getMessage());
			return null;
		}
	}

	public function expire(string $key, int $ttl): bool {
		try {
			return (bool) $this->getConnection()->expire($key, $ttl);
		} catch (Throwable $e) {
			$this->logger->error("RedisCache expire: " . $e->getMessage());
			return false;
		}
	}

	public function deleteByPrefix(string $prefix): int {
		$deleted = 0;
		try {
			$connection = $this->getConnection();
			$cursor = null;
			do {
				$keys = $connection->scan($cursor, $prefix . '*', 100);
				if ($keys !== false && !empty($keys)) {
					$deleted += $connection->del($keys);
				}
			} while ($cursor != 0);
		} catch (Throwable $e) {
			$this->logger->error("RedisCache deleteByPrefix: " . $e->getMessage());
			throw $e;
		}
		return $deleted;
	}

	/*
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
						$found[$key] = (int) $connection->ttl($key);
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
				$this->client = RedisAdapter::createConnection(
					$_ENV['REDIS_DSN']
				);
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
