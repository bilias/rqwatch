<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Core;

use App\Config\AppConfig;

use App\Utils\Helper;
use Psr\Log\LoggerInterface;
//use App\Core\RedisFactory;

use Throwable;

// Store selected config vars in a static array
class Config {
	private static array $vars = [];
	private static ?LoggerInterface $logger = null;

	public static function setLogger(LoggerInterface $logger): void {
		self::$logger = $logger;
	}

	public static function init(array $vars): void {
		self::$vars = $vars;
	}

	public static function get(string $key) {
		return self::$vars[$key] ?? null;
	}

	public static function set(string $key, mixed $value): void {
		self::$vars[$key] = $value;
	}

	public static function getAll(): array {
		return self::$vars;
	}

	// Loads variables from a config file and returns only new vars defined inside it
	public static function loadConfigFile(string $path): array {
		if (!file_exists($path)) {
			return [];
		}

		ob_start();

		$before = (function () {
			return get_defined_vars();
		})();

		$after = (function () use ($path) {
			require $path;
			$vars = get_defined_vars();
			unset($vars['path']); // remove $path from variables
			return $vars;
		})();

		ob_end_clean();

		// remove $_GLOBAL vars
		return array_diff_key($after, $before);
	}

	// Load and merge default + local config files, then init Config
	public static function loadAndInit(
		string $defaultConfigPath,
		?string $localConfigPath = null,
		array $extras = []
	): void {
		$defaultConfig = self::loadConfigFile($defaultConfigPath);

		$localConfig = $localConfigPath ? self::loadConfigFile($localConfigPath) : [];

		$merged = array_merge($defaultConfig, $localConfig, $extras);

		self::init($merged);
	}

	public static function loadAndInitWithRedisCache(
		string $defaultConfigPath,
		?string $localConfigPath = null,
		array $extras = [],
		string $redisKey = AppConfig::REDIS_CONFIG_KEY,
		int $ttlSeconds = AppConfig::REDIS_CONFIG_CACHE_TTL,
		bool $forceReload = false
	): void {

		$redisConnection = null;

		try {
			$redisConnection = RedisFactory::get();
			if (!$forceReload) {
				$cached = $redisConnection->get($redisKey);
				if ($cached !== false) {
					self::$logger->debug("Config [loadAndInitWithRedisCache]: Config is cached in Redis");
					$data = json_decode($cached, true);
					// catch app version update
					if (is_array($data) && (AppConfig::VERSION === $data['APP_VERSION'])) {
						// Merge extras on top of cached data — overrides if keys overlap
						self::init(array_merge($data, $extras));
						return;
					}
				}
			} else {
				self::$logger->debug("Config [loadAndInitWithRedisCache]: Forced reload requested, skipping cache");
			}
		} catch (Throwable $e) {
			self::$logger->error("Config [loadAndInitWithRedisCache1]: " . $e->getMessage());
		}

		// Redis miss or error or app version change — fallback to config file
		self::loadAndInit($defaultConfigPath, $localConfigPath, $extras);

		if ($redisConnection !== null) {
			try {
				// Remove all keys that are present in $extras from the cache data
				$toCache = array_diff_key(self::getAll(), $extras);
				// cache/save config in Redis
				$redisConnection->set($redisKey, json_encode($toCache), ['ex' => $ttlSeconds]);
				self::$logger->debug("Config [loadAndInitWithRedisCache]: " . "Cached data in Redis");
			} catch (Throwable $e) {
				self::$logger->error("Config [loadAndInitWithRedisCache2]: " . $e->getMessage());
			}
		}
	}

	public static function loadConfig(
		LoggerInterface $fileLogger,
		string $defaultConfigPath,
		?string $localConfigPath = null,
		array $extras = [],
		string $redisKey = AppConfig::REDIS_CONFIG_KEY,
		int $ttlSeconds = AppConfig::REDIS_CONFIG_CACHE_TTL
	): void {

		// set logger
		self::setLogger($fileLogger);

		if (Helper::env_bool('REDIS_ENABLE')) {
			try {
				RedisFactory::setLogger($fileLogger);
				self::loadAndInitWithRedisCache(
					$defaultConfigPath,
					$localConfigPath,
					$extras,
					$redisKey,
					$ttlSeconds
				);
			} catch (Throwable $e) {
				$fileLogger->error('Config [loadConfig] Redis connection failed: ' . $e->getMessage());
				self::loadAndInit($defaultConfigPath, $localConfigPath, $extras);
			}
		} else {
			self::loadAndInit($defaultConfigPath, $localConfigPath, $extras);
		}
	}

	public static function getRedisConfigTTL(
		string $redisKey = AppConfig::REDIS_CONFIG_KEY
	): ?int {

		try {
			$redis = RedisFactory::get();
			$ttl = $redis->ttl($redisKey);
			if ($ttl >= 0) {
				return $ttl; // seconds left
			}
			return null; // either -1 (no expiry) or -2 (not found)
		} catch (Throwable $e) {
			self::$logger?->error("Config [getRedisConfigTTL]: " . $e->getMessage());
			return null;
		}
	}

}
