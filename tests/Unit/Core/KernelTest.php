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
 'db:migrate*' commands, help and the command list are let through.
*/

namespace Tests\Unit\Core;

use App\Core\Kernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use ReflectionClass;
use ReflectionMethod;
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
}
