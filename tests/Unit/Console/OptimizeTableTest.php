<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 db:optimize_table without the database: only one run at a time, and only
 the mail_logs tables (mail_logs by default) can be rebuilt; any other
 name is refused with the list. Table sizes are shown in B, K, M, G or T,
 whole units, 1024 to the next.
*/

namespace Tests\Unit\Console;

use App\Console\OptimizeTable;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Unit\PrivateLock;
use Tests\Unit\UnitTestCase;

final class OptimizeTableTest extends UnitTestCase
{
	private string $dir = '';

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/rqwatch-ot-' . bin2hex(random_bytes(4));
		mkdir($this->dir);
	}

	#[\Override]
	protected function tearDown(): void {
		$files = glob("{$this->dir}/*");
		foreach ($files === false ? [] : $files as $file) {
			unlink($file);
		}
		rmdir($this->dir);
		parent::tearDown();
	}

	// the lock in the temp dir: see PrivateLock
	private function command(): OptimizeTable {
		return PrivateLock::give(new OptimizeTable(), $this->dir);
	}

	public function testUnknownTable(): void {
		$tester = new CommandTester($this->command());
		$this->assertSame(Command::FAILURE, $tester->execute(['--table' => 'users']));
		$this->assertSame("Unknown table 'users'\nKnown tables: mail_logs, mail_log_data, mail_log_recipients, mail_log_tokens\n", $tester->getDisplay());
		$this->assertSame([], $this->log->lines);
	}

	// the refusal in red, the list in yellow
	public function testUnknownTableStyled(): void {
		$tester = new CommandTester($this->command());
		$tester->execute(['--table' => 'users'], ['decorated' => true]);
		$this->assertSame(
			"\e[37;41mUnknown table 'users'\e[39;49m\n\e[33mKnown tables: mail_logs, mail_log_data, mail_log_recipients, mail_log_tokens\e[39m\n",
			$tester->getDisplay()
		);
	}

	public function testAlreadyRunning(): void {
		$running = $this->command();
		$this->assertTrue((new ReflectionMethod($running, 'lock'))->invoke($running));
		$tester = new CommandTester($this->command());
		$this->assertSame(Command::FAILURE, $tester->execute(['--table' => 'users']));
		$this->assertSame("Already running in another process.\n", $tester->getDisplay());
		$this->assertSame(['warning db:optimize_table Already running in another process'], $this->log->lines);
		(new ReflectionMethod($running, 'release'))->invoke($running);
	}

	public function testDefaultTable(): void {
		$this->assertSame('mail_logs', (new OptimizeTable())->getDefinition()->getOption('table')->getDefault());
	}

	/** @return array<string, array{int, string}> */
	public static function sizes(): array {
		return [
			'none' => [0, '0B'],
			'bytes' => [1023, '1023B'],
			'a K' => [1024, '1K'],
			'K, whole' => [1024 * 1024 - 1, '1023K'],
			'a M' => [1024 ** 2, '1M'],
			'G' => [5 * 1024 ** 3 + 7, '5G'],
			'T' => [3 * 1024 ** 4, '3T'],
			'T at most' => [2048 * 1024 ** 4, '2048T'],
		];
	}

	#[DataProvider('sizes')]
	public function testHumanBytes(int $bytes, string $shown): void {
		$this->assertSame($shown, (new ReflectionMethod(OptimizeTable::class, 'humanBytes'))->invoke(new OptimizeTable(), $bytes));
	}
}
