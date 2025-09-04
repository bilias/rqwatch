<?php
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

$startTime = microtime(true);
$startMemory = memory_get_usage();

require_once 'vendor/autoload.php';

use App\Core\Config;
use App\Core\Logging\LoggerService;
use App\Core\RedisFactory;
use App\Utils\Helper;

define('APP_VERSION', '1.6.3-dev');

define('APP_ROOT', __DIR__);

// load config from .env
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// configure loggers
$loggerService = new LoggerService();
$fileLogger = $loggerService->getFileLogger();
$syslogLogger = $loggerService->getSyslogLogger();

// set logger in Config
Config::setLogger($fileLogger);

// load config.php and config.local.php if it exists
$defaultConfigPath = __DIR__ . '/config/config.php';
$localConfigPath   = __DIR__ . '/config/config.local.php';
$extras = [
	'startTime'   => $startTime,
	'startMemory' => $startMemory,
];

// cache config in redis
if (Helper::env_bool('REDIS_ENABLE')) {
	try {
		RedisFactory::setLogger($fileLogger);
		Config::loadAndInitWithRedisCache(
			$defaultConfigPath,
			$localConfigPath,
			$extras,
			$_ENV['REDIS_CONFIG_KEY'],      // optional Redis key
			$_ENV['REDIS_CONFIG_CACHE_TTL']  // optional Config TTL
		);
	} catch (\Throwable $e) {
		$fileLogger->error('[Bootstrap] Redis connection failed: ' . $e->getMessage());
		Config::loadAndInit($defaultConfigPath, $localConfigPath, $extras);
	}
} else {
	Config::loadAndInit($defaultConfigPath, $localConfigPath, $extras);
}

// setup database connection
require_once 'config/db.php';

// pass fileLogger to Helper methods
Helper::setLogger($fileLogger);

// we do not need Router in our API or CLI
if (!defined('API_MODE') and !defined('CLI_MODE')) {
	if (Helper::env_bool('WEB_ENABLE')) {
		// Call our Router
		require_once 'app/Router.php';
	} else {
		$fileLogger->warning("Client '" . $_SERVER['REMOTE_ADDR'] . "' requested '" . $_SERVER['REQUEST_URI'] . "' but Web is disabled.");
		exit("Web is disabled");
	}
}
