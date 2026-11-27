<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Which CLI commands boot on an unmigrated database: the command name is
 the first non-option argv token, as Symfony resolves it, and only the
 'db:migrate*' commands, help and the command list are let through. The
 boot's smaller steps: Redis is used only with REDIS_ENABLE (connected on
 first use), the migration status gets the database and cache, a setting
 error is logged and lets only the metadata importer go on, and that one
 entry point may boot without a database only with Redis and the spool.
*/

namespace Tests\Unit\Core;

use App\Configuration\Config;
use App\Core\Cache\RedisCache;
use App\Core\Database\MigrationStatus;
use App\Core\Kernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Tests\Unit\UnitTestCase;

final class KernelTest extends UnitTestCase
{
	/** @var array<array-key, mixed>|null the argv before the test */
	private ?array $argv;

	/** @return array<string, array{list<string>, string, bool}> argv, command, exempt */
	public static function commandCases(): array {
		return [
			'no command: the command list' => [['cli.php'], '', true],
			'list' => [['cli.php', 'list'], 'list', true],
			'help' => [['cli.php', 'help', 'db:migrate'], 'help', true],
			'the db namespace' => [['cli.php', 'db'], 'db', true],
			'db:migrate' => [['cli.php', 'db:migrate'], 'db:migrate', true],
			'a data migration with options' => [['cli.php', 'db:migrate_mail_log_data', '-b', '2'], 'db:migrate_mail_log_data', true],
			'options before the command' => [['cli.php', '-v', '--batch=2', 'db:migrate', '--force'], 'db:migrate', true],
			'another command' => [['cli.php', 'cron:cleanupdb'], 'cron:cleanupdb', false],
			'another db command' => [['cli.php', 'db:map_hits_backfill'], 'db:map_hits_backfill', false],
			'db:migrate as an argument only' => [['cli.php', 'user:add', 'db:migrate'], 'user:add', false],
			'a near miss' => [['cli.php', 'db:migrat'], 'db:migrat', false],
			// as Symfony: a short option's separate value is taken for the command
			'short option with a separate value' => [['cli.php', '-b', '2', 'db:migrate'], '2', false],
			// as Symfony: an empty first argument is the command name, so it runs the list
			'an empty argument first' => [['cli.php', '', 'db:wipe'], '', true],
		];
	}

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		/** @var mixed $argv */
		$argv = $_SERVER['argv'] ?? null;
		$this->argv = is_array($argv) ? $argv : null;
	}

	#[\Override]
	protected function tearDown(): void {
		if ($this->argv === null) {
			unset($_SERVER['argv']);
		} else {
			$_SERVER['argv'] = $this->argv;
		}
		parent::tearDown();
	}

	/** @param list<string> $argv */
	#[DataProvider('commandCases')]
	public function testCliCommandName(array $argv, string $command, bool $exempt): void {
		$_SERVER['argv'] = $argv;
		$this->assertSame($command, $this->call('cliCommandName'));
	}

	public function testNoArgv(): void {
		unset($_SERVER['argv']);
		$this->assertSame('', $this->call('cliCommandName'));
	}

	// CLI_MODE is undefined in the test process: a web request is never exempt
	public function testNotCli(): void {
		$_SERVER['argv'] = ['cli.php', 'db:migrate'];
		$this->assertFalse($this->call('migrationCommandRequested'));
	}

	// defining CLI_MODE would change other tests, so in its own process
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testMigrationCommandRequested(): void {
		define('CLI_MODE', true);
		foreach (self::commandCases() as $label => [$argv, , $exempt]) {
			$_SERVER['argv'] = $argv;
			$this->assertSame($exempt, $this->call('migrationCommandRequested'), $label);
		}
	}

	private function call(string $method): mixed {
		$kernel = (new ReflectionClass(Kernel::class))->newInstanceWithoutConstructor();
		return (new ReflectionMethod(Kernel::class, $method))->invoke($kernel);
	}

	// a kernel past its loggers, with the cache given
	private function kernel(?RedisCache $cache = null): Kernel {
		$kernel = (new ReflectionClass(Kernel::class))->newInstanceWithoutConstructor();
		(new ReflectionProperty(Kernel::class, 'fileLogger'))->setValue($kernel, $this->log);
		(new ReflectionProperty(Kernel::class, 'cache'))->setValue($kernel, $cache);
		return $kernel;
	}

	private static function invoke(Kernel $kernel, string $method, mixed ...$arguments): mixed {
		return (new ReflectionMethod(Kernel::class, $method))->invoke($kernel, ...$arguments);
	}

	private static function property(object $object, string $name): mixed {
		return (new ReflectionProperty($object, $name))->getValue($object);
	}

	public function testRedisCacheDisabled(): void {
		$this->setEnv('REDIS_ENABLE', null);
		$kernel = $this->kernel();
		self::invoke($kernel, 'createRedisCache');
		$this->assertSame([null, ['debug Redis cache disabled']], [self::property($kernel, 'cache'), $this->log->lines]);
	}

	// connected on first use, so nothing is logged yet
	public function testRedisCacheEnabled(): void {
		$this->setEnv('REDIS_ENABLE', 'true');
		$kernel = $this->kernel();
		self::invoke($kernel, 'createRedisCache');
		$this->assertInstanceOf(RedisCache::class, self::property($kernel, 'cache'));
		$this->assertSame([], $this->log->lines);
	}

	public function testMigrationStatus(): void {
		$cache = new RedisCache($this->log);
		$kernel = $this->kernel($cache);
		self::invoke($kernel, 'createMigrationStatus');
		$status = self::property($kernel, 'migrationStatus');
		$this->assertInstanceOf(MigrationStatus::class, $status);
		$this->assertSame([null, $this->log, $cache], [self::property($status, 'capsule'), self::property($status, 'fileLogger'), self::property($status, 'cache')]);
	}

	public function testNoErrorsToRefuse(): void {
		self::invoke($this->kernel(), 'refuseOnErrors', 'config', []);
		$this->assertSame([], $this->log->lines);
	}

	// the metadata importer logs the errors and carries on
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testErrorsLoggedForTheImporter(): void {
		define('ALLOW_DEGRADED_DB', true);
		self::invoke($this->kernel(), 'refuseOnErrors', '.env', ['MAIL_API_USER is missing', 'REDIS_ENABLE is not a boolean']);
		$this->assertSame(['critical .env: MAIL_API_USER is missing; REDIS_ENABLE is not a boolean'], $this->log->lines);
	}

	// only the entry point that declares it may boot without a database
	public function testNoDegradedBoot(): void {
		Config::set('import_spool', true);
		$this->assertFalse(self::invoke($this->kernel(new RedisCache($this->log)), 'degradedDbAllowed'));
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testDegradedBootDeclaredOff(): void {
		define('ALLOW_DEGRADED_DB', false);
		Config::set('import_spool', true);
		$this->assertFalse(self::invoke($this->kernel(new RedisCache($this->log)), 'degradedDbAllowed'));
	}

	// with Redis and the spool, or a spool setting that cannot be read (logged, spooling)
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testDegradedBoot(): void {
		define('ALLOW_DEGRADED_DB', true);
		$redis = new RedisCache($this->log);
		Config::set('import_spool', true);
		$this->assertSame([true, false], [self::invoke($this->kernel($redis), 'degradedDbAllowed'), self::invoke($this->kernel(), 'degradedDbAllowed')]);
		Config::set('import_spool', false);
		$this->assertFalse(self::invoke($this->kernel($redis), 'degradedDbAllowed'));
		Config::set('import_spool', 'yes');
		$this->assertTrue(self::invoke($this->kernel($redis), 'degradedDbAllowed'));
		$this->assertCount(1, $this->log->lines);
		$this->assertStringStartsWith('error [MailLogSpool] boot: ', $this->log->lines[0]);
	}
}
