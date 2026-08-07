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
