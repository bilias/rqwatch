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
use App\Core\RedisFactory;

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

	public static function set(string $key, mixed $value) {
		self::$vars[$key] = $value;
	}

	public static function getAll() {
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
		string $redisKey = 'rqwatch_config',
		int $ttlSeconds = 300
	): void {

		$redisConnection = null;

		try {
			RedisFactory::setLogger(self::$logger);
			$redisConnection = RedisFactory::get();
			$cached = $redisConnection->get($redisKey);
			if ($cached !== false) {
				self::$logger->debug("Config [loadAndInitWithRedisCache]: Config is cached in Redis");
				$data = json_decode($cached, true);
				if (is_array($data)) {
					// Merge extras on top of cached data — overrides if keys overlap
					self::init(array_merge($data, $extras));
					return;
				}
			}
		} catch (\Throwable $e) {
			self::$logger->error("Config [loadAndInitWithRedisCache1]: " . $e->getMessage());
		}

		// Redis miss or error — fallback to config file
		self::loadAndInit($defaultConfigPath, $localConfigPath, $extras);

		if ($redisConnection !== null) {
			try {
				// Remove all keys that are present in $extras from the cache data
				$toCache = array_diff_key(self::getAll(), $extras);
				$redisConnection->set($redisKey, json_encode($toCache), ['ex' => $ttlSeconds]);
			} catch (\Throwable $e) {
				self::$logger->error("Config [loadAndInitWithRedisCache2]: " . $e->getMessage());
			}
		}
	}

}
