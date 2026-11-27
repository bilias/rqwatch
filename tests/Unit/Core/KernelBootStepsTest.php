<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The boot steps that do not end the run: the configuration loaded from
 config/config.php with the boot's start time and memory; a database that
 cannot be reached logged, and the metadata importer (with Redis and the
 spool) going on without it; the required migrations all completed
 passing; the migration status warmed from the database; and the App
 registry made of what the boot set up. What ends the run (a refusal,
 a connection) is the integration suite's.
*/

namespace Tests\Unit\Core;

use App\Configuration\Config;
use App\Core\App;
use App\Core\AppContainer;
use App\Core\Cache\RedisCache;
use App\Core\Database\MigrationStatus;
use App\Core\Kernel;
use App\Inventory\Migrations;
use Illuminate\Database\Capsule\Manager as Capsule;
use PDO;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Tests\Unit\RecordingDatabase;
use Tests\Unit\RecordingLogger;
use Tests\Unit\UnitTestCase;

final class KernelBootStepsTest extends UnitTestCase
{
	/** @param array<string, mixed> $properties */
	private function kernel(array $properties = []): Kernel {
		$kernel = (new ReflectionClass(Kernel::class))->newInstanceWithoutConstructor();
		/** @var mixed $value */
		foreach (['fileLogger' => $this->log] + $properties as $name => $value) {
			(new ReflectionProperty(Kernel::class, $name))->setValue($kernel, $value);
		}
		return $kernel;
	}

	private static function invoke(Kernel $kernel, string $method, mixed ...$arguments): mixed {
		return (new ReflectionMethod(Kernel::class, $method))->invoke($kernel, ...$arguments);
	}

	private static function property(Kernel $kernel, string $name): mixed {
		return (new ReflectionProperty(Kernel::class, $name))->getValue($kernel);
	}

	public function testConfig(): void {
		self::invoke($this->kernel(), 'bootConfig', ['startTime' => 12.5, 'startMemory' => 2048]);
		$this->assertSame([12.5, 2048], [Config::get('startTime'), Config::get('startMemory')]);
		// from config/config.php
		$this->assertSame('rqwatch_migration_status', Config::getString('migration_status_redis_key'));
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testDatabaseDownDegraded(): void {
		define('ALLOW_DEGRADED_DB', true);
		Config::set('import_spool', true);
		foreach (['DB_HOST' => '127.0.0.1', 'DB_PORT' => '1', 'DB_USER' => 'rqwatch', 'DB_PASS' => 'x', 'DB_NAME' => 'rqwatch'] as $key => $value) {
			$this->setEnv($key, $value);
		}
		$kernel = $this->kernel(['cache' => new RedisCache($this->log)]);
		self::invoke($kernel, 'bootDatabase');
		$this->assertSame([null, false], [self::property($kernel, 'capsule'), self::property($kernel, 'dbAvailable')]);
		$this->assertCount(1, $this->log->lines);
		$this->assertStringStartsWith('critical Database connection problem: SQLSTATE[HY000] [2002] ', $this->log->lines[0]);
	}

	public function testRequiredMigrationsCompleted(): void {
		$status = new MigrationStatus(null, $this->log);
		foreach (Migrations::REQUIRED as $migration) {
			$status->setMigrationState($migration, Migrations::STATUS_COMPLETED);
		}
		self::invoke($this->kernel(['migrationStatus' => $status]), 'verifyRequiredMigrations');
		$this->assertSame([], $this->log->lines);
	}

	public function testMigrationStatusWarmed(): void {
		$db = new RecordingDatabase($this->createStub(PDO::class));
		$db->answer = fn (): array => [['migration' => Migrations::MAP_HITS, 'status' => Migrations::STATUS_COMPLETED]];
		try {
			$status = new MigrationStatus($db->capsule, $this->log);
			$status->setMigrationTableExists(true);
			self::invoke($this->kernel(['migrationStatus' => $status]), 'warmMigrationStatusCache');
			$this->assertCount(1, $db->selects);
			$this->assertTrue($status->mapHitsCompleted());
		} finally {
			RecordingDatabase::forget();
		}
	}

	public function testApp(): void {
		$syslog = new RecordingLogger();
		$capsule = new Capsule();
		$status = new MigrationStatus($capsule, $this->log);
		$cache = new RedisCache($this->log);
		$kernel = $this->kernel([
			'startTime' => 12.5,
			'startMemory' => 2048,
			'syslogLogger' => $syslog,
			'capsule' => $capsule,
			'migrationStatus' => $status,
			'cache' => $cache,
			'dbAvailable' => true,
		]);
		App::reset();
		self::invoke($kernel, 'initApp');
		$app = (new ReflectionProperty(App::class, 'instance'))->getValue();
		$this->assertInstanceOf(AppContainer::class, $app);
		$this->assertSame(
			[12.5, 2048, $this->log, $syslog, $capsule, $status, $cache, true],
			[$app->startTime, $app->startMemory, $app->fileLogger, $app->syslogLogger, $app->capsule, $app->migrationStatus, $app->cache, $app->dbAvailable]
		);
	}
}
