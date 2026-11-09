<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Configuration;

use App\Core\Cache\RedisCache;

use App\Utils\Helper;
use Psr\Log\LoggerInterface;

use Throwable;

// Store selected config vars in a static array
final class Config {
	/** @var array<string, mixed> */
	private static array $vars = [];
	private static LoggerInterface $logger;

	public static function setLogger(LoggerInterface $logger): void {
		self::$logger = $logger;
	}

	/** @param array<string, mixed> $vars */
	public static function init(array $vars): void {
		self::$vars = $vars;
	}

	public static function get(string $key): mixed {
		return self::$vars[$key] ?? null;
	}

	public static function set(string $key, mixed $value): void {
		self::$vars[$key] = $value;
	}

	/** @return array<string, mixed> */
	public static function getAll(): array {
		return self::$vars;
	}

	// Loads variables from a config file and returns only new vars defined inside it
	/** @return array<string, mixed> variable name => value */
	public static function loadConfigFile(string $path): array {
		if (!file_exists($path)) {
			return [];
		}

		ob_start();

		$before = (function () {
			return get_defined_vars();
		})();

		/** @var array<string, mixed> $after variable names => values */
		$after = (function () use ($path) {
			/** @psalm-suppress UnresolvableInclude the config file is chosen at runtime */
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
	/** @param array<string, mixed> $extras */
	public static function loadAndInit(
		string $defaultConfigPath,
		string $localConfigPath,
		array $extras = []
	): void {
		$defaultConfig = self::loadConfigFile($defaultConfigPath);

		$localConfig = self::loadConfigFile($localConfigPath);

		$merged = array_merge($defaultConfig, $localConfig, $extras);

		self::init($merged);
	}

	/*
	 Decode the config cached in Redis. Only this class writes that key,
	 from the config files. Psalm 7 treats Redis as one keyed store and
	 the import spool's keys start with a config value, so it cannot tell
	 them apart: without the escape, the spooled mail data would taint
	 every Config::get() value read back from the cache.
	*/
	/** @psalm-taint-escape input */
	private static function decodeCachedConfig(string $cached): mixed {
		return json_decode($cached, true);
	}

	/** @param array<string, mixed> $extras */
	public static function loadAndInitWithRedisCache(
		RedisCache $redis,
		string $defaultConfigPath,
		string $localConfigPath,
		array $extras = [],
		string $redisKey = AppConfig::REDIS_CONFIG_KEY,
		int $ttlSeconds = AppConfig::REDIS_CONFIG_CACHE_TTL,
		bool $forceReload = false
	): void {

		$redisConnection = null;

		try {
			$redisConnection = $redis->getConnection();
			if (!$forceReload) {
				$cached = $redisConnection->get($redisKey);
				if (is_string($cached)) {
					self::$logger->debug("Config [loadAndInitWithRedisCache]: Config is cached in Redis");
					$data = self::decodeCachedConfig($cached);
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
				$redisConnection->set($redisKey, json_encode($toCache, JSON_THROW_ON_ERROR), ['ex' => $ttlSeconds]);
				self::$logger->debug("Config [loadAndInitWithRedisCache]: " . "Cached data in Redis");
			} catch (Throwable $e) {
				self::$logger->error("Config [loadAndInitWithRedisCache2]: " . $e->getMessage());
			}
		}
	}

	/** @param array<string, mixed> $extras */
	public static function loadConfig(
		LoggerInterface $fileLogger,
		?RedisCache $cache,
		string $defaultConfigPath,
		string $localConfigPath,
		array $extras = [],
		string $redisKey = AppConfig::REDIS_CONFIG_KEY,
		int $ttlSeconds = AppConfig::REDIS_CONFIG_CACHE_TTL
	): void {

		// set logger
		self::setLogger($fileLogger);

		if (Helper::env_bool('REDIS_ENABLE') && $cache !== null) {
			try {
				self::loadAndInitWithRedisCache(
					redis: $cache,
					defaultConfigPath: $defaultConfigPath,
					localConfigPath: $localConfigPath,
					extras: $extras,
					redisKey: $redisKey,
					ttlSeconds: $ttlSeconds,
				);
			} catch (Throwable $e) {
				$fileLogger->error('Config [loadConfig] Redis connection failed: ' . $e->getMessage());
				self::loadAndInit($defaultConfigPath, $localConfigPath, $extras);
			}
		} else {
			self::loadAndInit($defaultConfigPath, $localConfigPath, $extras);
		}
	}

	// REDIS_CONFIG_KEY from .env; AppConfig's default when unset or empty
	public static function redisConfigKey(): string {
		$key = trim(Helper::env_string('REDIS_CONFIG_KEY') ?? '');
		return $key !== '' ? $key : AppConfig::REDIS_CONFIG_KEY;
	}

	// REDIS_CONFIG_CACHE_TTL from .env; AppConfig's default when unset or not a number
	public static function redisConfigTtl(): int {
		$ttl = Helper::env_string('REDIS_CONFIG_CACHE_TTL');
		return is_numeric($ttl) ? (int) $ttl : AppConfig::REDIS_CONFIG_CACHE_TTL;
	}

	public static function getRedisConfigTTL(
		RedisCache $redis,
		string $redisKey = AppConfig::REDIS_CONFIG_KEY
	): ?int {
		return $redis->ttl($redisKey);
	}

}
