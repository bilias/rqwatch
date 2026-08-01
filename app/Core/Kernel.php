<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Core;

use App\Configuration\AppConfig;
use App\Configuration\Config;

use App\Core\Database\Database;
use App\Core\Database\MigrationStatus;

use App\Core\Logging\LoggerService;
use App\Utils\Helper;

use Dotenv\Dotenv;
use Exception;
use RuntimeException;
use Throwable;

use Illuminate\Database\Capsule\Manager as Capsule;

class Kernel
{
	public static function boot(): void {
		$startTime = microtime(true);
		$startMemory = memory_get_usage();

		require_once __DIR__ . '/../Configuration/AppConfig.php';

		if (!defined('RQWATCH_ROOT')) {
			throw new RuntimeException("RQWATCH_ROOT is not defined. Check AppConfig.");
		}

		require_once AppConfig::VENDOR_AUTOLOAD;

		// configure loggers
		$loggerService = new LoggerService();
		$fileLogger = $loggerService->getFileLogger();
		$syslogLogger = $loggerService->getSyslogLogger();

		// load config from .env
		if (!file_exists(AppConfig::ENV_PATH)) {
			echo "<h1 style='color:red'>Application configuration error</h1>";
			echo "<p>Missing required <code>.env</code> file</p>";
			throw new RuntimeException("Missing required environment file: " .
			   AppConfig::ENV_PATH);
		}

		$dotenv = Dotenv::createImmutable(RQWATCH_ROOT);
		try {
			$dotenv->load();
		} catch (Exception $e) {
			$fileLogger->error("Error loading .env: " . $e->getMessage());
			echo "Error loading .env";
			exit;
		}

		$extra_cached_data = [
			'startTime' => $startTime,
			'startMemory' => $startMemory,
		];

		// load configuration
		Config::loadConfig(
			$fileLogger,
			AppConfig::CONFIG_DEFAULT_PATH,
			AppConfig::CONFIG_LOCAL_PATH,
			$extra_cached_data,
			$_ENV['REDIS_CONFIG_KEY'],             // optional Redis key
			(int) $_ENV['REDIS_CONFIG_CACHE_TTL']  // optional Config TTL
		);

		// setup DB connection
		$capsule = Database::boot();

		// test DB connection
		try {
			$capsule->getConnection()->getPdo();
		} catch (Exception $e) {
			$fileLogger->error("DB error: " . $e->getMessage());
			echo "Database connection problem!";
			exit;
		}

		$migrationStatus = new MigrationStatus($capsule, $fileLogger);

		App::init(
			new AppContainer(
				$startTime,
				$startMemory,
				$fileLogger,
				$syslogLogger,
				$capsule,
				$migrationStatus
			)
		);
	}

}
