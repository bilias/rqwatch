<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Core;

use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Exception\InvalidArgumentException;

use Throwable;

class RedisFactory
{
	private static \Redis|\Predis\Client|null $client = null;
	private static ?LoggerInterface $logger = null;

	public static function setLogger(LoggerInterface $logger): void {
		self::$logger = $logger;
	}

	public static function get(): \Redis|\Predis\Client {
		if (self::$client === null) {
			// Redis Sentinel connection via phpredis or predis
			try {
				self::$client = RedisAdapter::createConnection($_ENV['REDIS_DSN']);
				self::$logger->debug('[RedisFactory] Redis connection established');
			} catch (InvalidArgumentException $e) {
				self::$logger->error('[RedisFactory]: ' . $e->getMessage());
				throw $e;
			} catch (Throwable $e) {
				self::$logger->error('[RedisFactory]: ' . $e->getMessage());
				throw $e;
			}
		}
		return self::$client;
	}
}
