<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU Affero General Public License version 3
as published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU Affero General Public License for more details.

You should have received a copy of the GNU Affero General Public License
along with this program.  If not, see <https://www.gnu.org/licenses/>.
*/

namespace App\Core;

use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Exception\InvalidArgumentException;

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
				self::$logger->error('[RedisFactory] Redis connection failed: ' . $e->getMessage());
				throw $e;
			} catch (\Throwable $e) {
				self::$logger->error('[RedisFactory] Redis connection failed: ' . $e->getMessage());
				throw $e;
			}
		}
		return self::$client;
	}
}
