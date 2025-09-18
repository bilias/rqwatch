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

define('APP_VERSION', '1.6.3');

define('APP_ROOT', __DIR__);

// load config from .env
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// configure loggers
$loggerService = new LoggerService();
$fileLogger = $loggerService->getFileLogger();
$syslogLogger = $loggerService->getSyslogLogger();

// load configuration
Config::loadConfig(
	$fileLogger,
	__DIR__ . '/config/config.php',
	__DIR__ . '/config/config.local.php',
	[ 'startTime' => $startTime, 'startMemory' => $startMemory ],
	$_ENV['REDIS_CONFIG_KEY'],             // optional Redis key
	(int) $_ENV['REDIS_CONFIG_CACHE_TTL']  // optional Config TTL
);

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
