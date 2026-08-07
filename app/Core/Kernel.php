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

use Dotenv\Dotenv;

use Illuminate\Database\Capsule\Manager as Capsule;
use App\Core\Database\Database;
use App\Core\Database\MigrationStatus;
use App\Core\Cache\RedisCache;

use App\Core\Logging\LoggerService;
use Psr\Log\LoggerInterface;

use App\Utils\Helper;

use RuntimeException;
use Throwable;

final class Kernel
{
	private float $startTime;
	private int $startMemory;
	private LoggerInterface $fileLogger;
	private LoggerInterface $syslogLogger;
	private Capsule $capsule;
	private MigrationStatus $migrationStatus;
	private ?RedisCache $cache = null;

	public function boot(): void {
		$this->startTime = microtime(true);
		$this->startMemory = memory_get_usage();

		require_once __DIR__ . '/../Configuration/AppConfig.php';

		if (!defined('RQWATCH_ROOT')) {
			throw new RuntimeException("RQWATCH_ROOT is not defined. Check AppConfig.");
		}

		require_once AppConfig::VENDOR_AUTOLOAD;

		// create fileLogger and syslogLogger
		$this->createLoggers();

		// load .env
		$this->loadDotenv();

		$extra_cached_data = [
			'startTime' => $this->startTime,
			'startMemory' => $this->startMemory,
		];
		// load config
		$this->loadConfig($extra_cached_data);

		// connect to db
		$this->bootDatabase();

		// get migration status
		$this->createMigrationStatus();

		// check db schema validity
		$this->verifyDatabaseSchema();

		// Future when migrations are required
		// $this->verifyRequiredMigrations()

		// Redis caching
		$this->createRedisCache();

		// find out about migrations and cache results
		$this->warmMigrationStatusCache();

		// last: create App registry
		$this->initApp();
	}

	private function createLoggers(): void {
		// configure loggers
		$loggerService = new LoggerService();
		$this->fileLogger = $loggerService->getFileLogger();
		$this->syslogLogger = $loggerService->getSyslogLogger();
	}

	private function loadDotenv(): void {
		// load config from .env
		if (!file_exists(AppConfig::ENV_PATH)) {
			echo "<h1 style='color:red'>Application configuration error</h1>";
			echo "<p>Missing required <code>.env</code> file</p>";
			throw new RuntimeException("Missing required environment file: " .
			   AppConfig::ENV_PATH);
		}

		try {
			$dotenv = Dotenv::createImmutable(RQWATCH_ROOT);
			$dotenv->load();
		} catch (Throwable $e) {
			$this->fileLogger->error("Error loading .env: " . $e->getMessage());
			throw new RuntimeException("Failed to load .env file", previous: $e);
		}
	}

	private function loadConfig(array $extra_cached_data): void {
		// load (and cache) configuration
		Config::loadConfig(
			$this->fileLogger,
			AppConfig::CONFIG_DEFAULT_PATH,
			AppConfig::CONFIG_LOCAL_PATH,
			$extra_cached_data,
			$_ENV['REDIS_CONFIG_KEY'],             // optional Redis key
			(int) $_ENV['REDIS_CONFIG_CACHE_TTL']  // optional Config TTL
		);
	}

	private function bootDatabase(): void {
		try {
			// setup DB connection
			$this->capsule = Database::boot();
			// test DB connection
			$this->capsule->getConnection()->getPdo();
		} catch (Throwable $e) {
			$this->fileLogger->error("DB error: " . $e->getMessage());
			echo "Database connection problem!";
			exit;
		}
	}

	private function verifyDatabaseSchema(): void {
		try {
			Database::verifySchema($this->capsule, $this->migrationStatus);
		} catch (Throwable $e) {
			$this->fileLogger->critical(
				"Database schema verification failed: " .
				$e->getMessage()
			);

			echo "Database schema problem!";
			exit;
		}
	}

	private function verifyRequiredMigrations(): void {
		$this->migrationStatus->verifyRequiredMigrations();
	}

	private function createMigrationStatus(): void {
		$this->migrationStatus = new MigrationStatus(
			$this->capsule,
			$this->fileLogger
		);
	}

	private function warmMigrationStatusCache(): void {
		$this->migrationStatus->warmCache();
	}

	private function createRedisCache(): void {
		if (!Helper::env_bool('REDIS_ENABLE')) {
			$this->fileLogger->debug('Redis cache disabled');
			return;
		}

		try {
			$this->cache = new RedisCache($this->fileLogger);
		} catch (Throwable $e) {
			$this->fileLogger->error(
				"Cache initialization error: " . $e->getMessage()
			);

			$this->cache = null;
		}
	}

	private function initApp(): void {
		App::init(
			new AppContainer(
				startTime: $this->startTime,
				startMemory: $this->startMemory,
				fileLogger: $this->fileLogger,
				syslogLogger: $this->syslogLogger,
				capsule: $this->capsule,
				migrationStatus: $this->migrationStatus,
				cache: $this->cache
			)
		);
	}

}
