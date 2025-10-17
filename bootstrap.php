<?php
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
$startTime = microtime(true);
$startMemory = memory_get_usage();

require_once 'vendor/autoload.php';

use App\Core\Config;
use App\Core\Logging\LoggerService;
use App\Utils\Helper;

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
if (!empty($_ENV['REDIS_ENABLE'])) {
	Config::loadAndInitWithRedisCache(
		$defaultConfigPath,
		$localConfigPath,
		$extras,
		$_ENV['REDIS_CONFIG_NAME'],      // optional Redis key
		$_ENV['REDIS_CONFIG_CACHE_TTL']  // optional Config TTL
	);
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
