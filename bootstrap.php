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

require_once __DIR__ . '/config/app_config.php';

require_once APP_VENDOR_PATH;

use App\Core\Config;
use App\Core\Logging\LoggerService;
use App\Core\RedisFactory;
use App\Utils\Helper;

// load config from .env
if (!file_exists(APP_ENV_PATH)) {
	echo "<h1 style='color:red'>Application configuration error</h1>";
	echo "<p>Missing required <code>.env</code> file</p>";
	throw new RuntimeException("Missing required environment file: {$envPath}");
}

$dotenv = Dotenv\Dotenv::createImmutable(APP_ROOT);
$dotenv->load();

// configure loggers
$loggerService = new LoggerService();
$fileLogger = $loggerService->getFileLogger();
$syslogLogger = $loggerService->getSyslogLogger();

// load configuration
Config::loadConfig(
	$fileLogger,
	CONFIG_DEFAULT_PATH,
	CONFIG_LOCAL_PATH,
	[ 'startTime' => $startTime, 'startMemory' => $startMemory ],
	$_ENV['REDIS_CONFIG_KEY'],             // optional Redis key
	(int) $_ENV['REDIS_CONFIG_CACHE_TTL']  // optional Config TTL
);

// setup DB connection
require_once 'config/db.php';

// test DB connection
try {
	$capsule->getConnection()->getPdo();
} catch (Exception $e) {
	$fileLogger->error("DB error: " . $e->getMessage());
	echo "Database connection problem!";
	exit;
}

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
