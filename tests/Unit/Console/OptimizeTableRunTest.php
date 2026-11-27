<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 db:optimize_table run on RecordingDatabase: the table is rebuilt with
 OPTIMIZE TABLE, the session's max_statement_time lifted first; its size
 (data, index, free, from information_schema; NULL as none) said before
 and after, logged. A table information_schema does not list is of
 unknown size. -vv adds the runtime. A rebuild that fails is reported, logged, and fails.
*/

namespace Tests\Unit\Console;

use App\Console\OptimizeTable;
use App\Core\App;
use App\Core\AppContainer;
use PDO;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Unit\PrivateLock;
use Tests\Unit\RecordingDatabase;
use Tests\Unit\UnitTestCase;

final class OptimizeTableRunTest extends UnitTestCase
{
	private const string SIZE = "SELECT data_length, index_length, data_free\n\t\t\t   FROM information_schema.TABLES\n\t\t\t  WHERE table_schema = DATABASE() AND table_name = ?";

	private RecordingDatabase $db;

	private string $dir = '';

	/** @var list<list<array<string, mixed>>> what each size lookup finds, in turn */
	private array $sizes = [];

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->db = new RecordingDatabase($this->createStub(PDO::class));
		$this->db->answer = fn (): array => array_shift($this->sizes) ?? [];
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, $this->db->capsule, null, null, false));
		$this->dir = sys_get_temp_dir() . '/rqwatch-otr-' . bin2hex(random_bytes(4));
		mkdir($this->dir);
	}

	#[\Override]
	protected function tearDown(): void {
		$files = glob("{$this->dir}/*");
		foreach ($files === false ? [] : $files as $file) {
			unlink($file);
		}
		rmdir($this->dir);
		RecordingDatabase::forget();
		parent::tearDown();
	}

	/** @return array{int, string} */
	private function optimize(string $table, int $verbosity = OutputInterface::VERBOSITY_NORMAL): array {
		$tester = new CommandTester(PrivateLock::give(new OptimizeTable(), $this->dir));
		$status = $tester->execute(['--table' => $table], ['verbosity' => $verbosity]);
		return [$status, $tester->getDisplay()];
	}

	public function testRebuild(): void {
		$this->sizes = [
			[['data_length' => 5 * 1024 ** 3, 'index_length' => '2097152', 'data_free' => 3 * 1024 ** 3]],
			[['data_length' => 5 * 1024 ** 3, 'index_length' => 1024 * 1024, 'data_free' => null]],
		];
		[$status, $display] = $this->optimize('mail_log_data');
		$this->assertSame(Command::SUCCESS, $status);
		$this->assertSame("Rebuilding `mail_log_data`, currently data 5G, index 2M, free 3G\nThis will take some time, please be patient\n"
			. "Rebuilt `mail_log_data`: data 5G, index 2M, free 3G -> data 5G, index 1M, free 0B\n", $display);
		$this->assertSame([['SET SESSION max_statement_time = 0', []], ['OPTIMIZE TABLE `mail_log_data`', []]], $this->db->statements);
		$this->assertSame([[self::SIZE, ['mail_log_data']], [self::SIZE, ['mail_log_data']]], $this->db->selects);
		$this->assertSame([
			'info db:optimize_table rebuilding mail_log_data (data 5G, index 2M, free 3G)',
			'info db:optimize_table rebuilt mail_log_data: data 5G, index 2M, free 3G -> data 5G, index 1M, free 0B',
		], $this->log->lines);
	}

	public function testUnknownSize(): void {
		[$status, $display] = $this->optimize('mail_logs');
		$this->assertSame(Command::SUCCESS, $status);
		$this->assertStringEndsWith("Rebuilt `mail_logs`: unknown size -> unknown size\n", $display);
	}

	public function testNullSizesAreNone(): void {
		$this->sizes = [
			[['data_length' => null, 'index_length' => null, 'data_free' => null]],
			[['data_length' => null, 'index_length' => null, 'data_free' => null]],
		];
		[$status, $display] = $this->optimize('mail_logs');
		$this->assertSame(Command::SUCCESS, $status);
		$this->assertStringEndsWith("Rebuilt `mail_logs`: data 0B, index 0B, free 0B -> data 0B, index 0B, free 0B\n", $display);
	}

	public function testRuntimeWhenVeryVerbose(): void {
		[$status, $display] = $this->optimize('mail_logs', OutputInterface::VERBOSITY_VERY_VERBOSE);
		$this->assertSame(Command::SUCCESS, $status);
		$this->assertMatchesRegularExpression('/\nRebuilt `mail_logs`: unknown size -> unknown size\nExecution time: [^\n]+\n$/D', $display);
	}

	public function testRebuildFails(): void {
		$this->db->onStatement = function (string $sql): void {
			if (str_starts_with($sql, 'OPTIMIZE')) {
				throw new RuntimeException('Lock wait timeout exceeded');
			}
		};
		[$status, $display] = $this->optimize('mail_log_tokens');
		$this->assertSame(Command::FAILURE, $status);
		$this->assertStringEndsWith("Rebuild failed: Lock wait timeout exceeded\n", $display);
		$this->assertSame('error db:optimize_table failed to rebuild mail_log_tokens: Lock wait timeout exceeded', $this->log->lines[1] ?? null);
		$this->assertCount(1, $this->db->selects);
	}
}
