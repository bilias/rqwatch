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

use Symfony\Component\HttpFoundation\Response;

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
	private ?Capsule $capsule = null;
	private ?MigrationStatus $migrationStatus = null;
	private bool $dbAvailable = false;
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

		// Redis caching
		$this->createRedisCache();

		$extra_cached_data = [
			'startTime' => $this->startTime,
			'startMemory' => $this->startMemory,
		];
		// load config
		$this->loadConfig($extra_cached_data);

		// connect to db
		$this->bootDatabase();

		// create migrationStatus object
		$this->createMigrationStatus();

		if ($this->dbAvailable) {
			// check db schema validity
			$this->verifyDatabaseSchema();

			// find out about migrations and cache results
			$this->warmMigrationStatusCache();
		} elseif (!$this->degradedDbAllowed()) {
			/*
			 Unreachable today: bootDatabase() only leaves dbAvailable
			 false through the branch that already checked this. Kept so
			 that a future path which skips the database cannot inherit
			 degraded mode without opting in.
			*/
			$this->fileLogger->critical(
				"Degraded boot reached without ALLOW_DEGRADED_DB"
			);

			$this->bootFailure("Database connection problem!");
		} elseif (!$this->migrationStatus->loadPersistedState()) {
			/*
			 Degraded boot with no usable migration status in Redis. Refuse
			 rather than guess: a fresh install has no copy, and letting it
			 spool payloads it can never import would be worse than the
			 503. verifyRequiredMigrations() below still runs, so an
			 incomplete copy is refused too.
			*/
			$this->fileLogger->critical(
				"No usable migration status in Redis; refusing degraded boot"
			);

			$this->bootFailure("Database connection problem!");
		}

		// migrations are mandatory, except for the CLI commands that run them
		if (!$this->migrationCommandRequested()) {
			$this->verifyRequiredMigrations();
		}

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
			$this->fileLogger->critical("Missing required environment file: " .
				AppConfig::ENV_PATH);

			$this->bootFailure("Application configuration error");
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
			$this->cache,
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

			$this->dbAvailable = true;
		} catch (Throwable $e) {
			$this->fileLogger->critical("Database connection problem: " . $e->getMessage());

			// the metadata importer opts in to running without a database
			// so it can still spool to Redis; everything else must fail
			if ($this->degradedDbAllowed()) {
				$this->capsule = null;

				return;
			}

			$this->bootFailure("Database connection problem!");
		}
	}

	/*
	 Only the entry point that declares ALLOW_DEGRADED_DB may boot without
	 a database, and only with Redis available - spooling to Redis is the
	 entire purpose of the mode, so no cache means no degraded boot.
	*/
	private function degradedDbAllowed(): bool {
		return defined('ALLOW_DEGRADED_DB')
			&& ALLOW_DEGRADED_DB
			&& $this->cache !== null
			&& Config::get('import_spool');
	}

	private function verifyDatabaseSchema(): void {
		try {
			// Unreachable: this is only called inside the dbAvailable
			// branch, which implies both are set.
			// Only present because verifySchema() does not take nulls
			// and fails static analysis
			if ($this->capsule === null || $this->migrationStatus === null) {
				throw new RuntimeException(
					"Schema verification reached without a database connection"
				);
			}

			Database::verifySchema($this->capsule, $this->migrationStatus);
		} catch (Throwable $e) {
			$this->fileLogger->critical(
				"Database schema verification failed: " .
				$e->getMessage()
			);

			$this->bootFailure("Database schema problem!");
		}
	}

	private function verifyRequiredMigrations(): void {
		try {
			$this->migrationStatus->verifyRequiredMigrations();
		} catch (Throwable $e) {
			$this->fileLogger->critical(
				"Required database migration missing: " . $e->getMessage()
			);

			$this->bootFailure("Database migration pending. See logs.");
		}
	}

	/*
	 The migration commands boot the same Kernel, so an unconditional
	 verifyRequiredMigrations() would make db:migrate impossible to run on an
	 unmigrated install. Every migration command is named 'db:migrate*'.
	 The exempt list keeps the command list and help reachable: '' is the
	 no-argument case, where Symfony prints the list.
	*/
	private function migrationCommandRequested(): bool {
		if (!defined('CLI_MODE') || !CLI_MODE) {
			return false;
		}

		// whitelisted cli command that can run under ALLOW_DEGRADED_DB mode
		$migrationPrefix = 'db:migrate';
		$exemptCommands = ['', 'help', 'list', 'db'];

		$command = $this->cliCommandName();

		if (in_array($command, $exemptCommands, true)) {
			return true;
		}

		return str_starts_with($command, $migrationPrefix);
	}

	// first non-option argv token, which is what Symfony resolves as the
	// command name
	private function cliCommandName(): string {
		foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
			if ($arg === '' || $arg[0] === '-') {
				continue;
			}

			return (string) $arg;
		}

		return '';
	}

	private function createMigrationStatus(): void {
		$this->migrationStatus = new MigrationStatus(
			$this->capsule,
			$this->fileLogger,
			$this->cache
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

	/*
	 Abort the boot with a real status code. Every caller runs after the
	 vendor autoload, so HttpFoundation is available. The client message
	 stays generic on purpose: the API is reached by rspamd and by anything
	 that can hit the endpoint, not by an operator, so the remedy belongs in
	 the log and nowhere else.
	*/
	private function bootFailure(
		string $message,
		int $status = Response::HTTP_SERVICE_UNAVAILABLE
	): never {
		if (defined('CLI_MODE') && CLI_MODE) {
			fwrite(STDERR, $message . PHP_EOL);
			exit(1);
		}

		$response = new Response($message, $status);
		$response->headers->set('Content-Type', 'text/plain; charset=utf-8');
		$response->send();

		exit(1);
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
				cache: $this->cache,
				dbAvailable: $this->dbAvailable
			)
		);
	}

}
