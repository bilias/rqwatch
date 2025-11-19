<?php
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App;

use App\Config\AppConfig;
use App\Core\Config;
use App\Core\Logging\LoggerService;
use App\Utils\Helper;

use App\Router;

use Dotenv\Dotenv;
use Exception;
use RuntimeException;

class Kernel
{
	public static function boot(): array {
		$startTime = microtime(true);
		$startMemory = memory_get_usage();

		require_once __DIR__ . '/Config/AppConfig.php';

		if (!defined('APP_ROOT')) {
			throw new RuntimeException("APP_ROOT is not defined. Check AppConfig.");
		}

		require_once AppConfig::VENDOR_PATH;

		// load config from .env
		if (!file_exists(AppConfig::ENV_PATH)) {
			echo "<h1 style='color:red'>Application configuration error</h1>";
			echo "<p>Missing required <code>.env</code> file</p>";
			throw new RuntimeException("Missing required environment file: " .
			   AppConfig::ENV_PATH);
		}

		$dotenv = Dotenv::createImmutable(APP_ROOT);
		try {
			$dotenv->load();
		} catch (Exception $e) {
			$fileLogger->error("Error loading .env: " . $e->getMessage());
			echo "Error loading .env";
			exit;
		}

		// configure loggers
		$loggerService = new LoggerService();
		$fileLogger = $loggerService->getFileLogger();
		$syslogLogger = $loggerService->getSyslogLogger();

		// load configuration
		Config::loadConfig(
			$fileLogger,
			AppConfig::CONFIG_DEFAULT_PATH,
			AppConfig::CONFIG_LOCAL_PATH,
			[ 'startTime' => $startTime, 'startMemory' => $startMemory ],
			$_ENV['REDIS_CONFIG_KEY'],             // optional Redis key
			(int) $_ENV['REDIS_CONFIG_CACHE_TTL']  // optional Config TTL
		);

		// setup DB connection
		require_once AppConfig::DB_CONFIG_PATH;

		// test DB connection
		try {
			/** @var \Illuminate\Database\Capsule\Manager $capsule */
			$capsule->getConnection()->getPdo();
		} catch (Exception $e) {
			$fileLogger->error("DB error: " . $e->getMessage());
			echo "Database connection problem!";
			exit;
		}

		// pass fileLogger to Helper methods
		Helper::setLogger($fileLogger);

		return [
			'fileLogger' => $fileLogger,
			'syslogLogger' => $syslogLogger,
			'capsule' => $capsule,
		];
	}

	public static function runRouter(array $services): void {

		$fileLogger = $services['fileLogger'];
		$syslogLogger = $services['syslogLogger'];

		// we do not need Router in our API or CLI
		if (!defined('API_MODE') && !defined('CLI_MODE') && defined('WEB_MODE')) {
			if (Helper::env_bool('WEB_ENABLE')) {
				// Load routes and default middleware classes
				if (!file_exists(AppConfig::ROUTES_PATH)) {
					$fileLogger->error("Routes file missing: " . AppConfig::ROUTES_PATH);
					exit;
				}
				/** @var RouteCollection $routes */
				/** @var array $defaultMiddlewareClasses */
				include AppConfig::ROUTES_PATH;

				// Instantiate Router and handle the request
				$router = new Router();
				$response = $router($routes, $defaultMiddlewareClasses, $fileLogger, $syslogLogger);
				$response->send();
				exit;
			} else {
				$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
				$fileLogger->warning("Client '{$ip}' requested '" . $_SERVER['REQUEST_URI'] . "' but Web is disabled.");
				exit("Web is disabled");
			}
		}
	}

}
