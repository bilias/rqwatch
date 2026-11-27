<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 cron:quarantine on RecordingDatabase and a temp QUARANTINE_DIR. Only one
 run at a time. The mail stored longer than QUARANTINE_DAYS (366 by
 default), of this server only with -l, is listed with -s and its files
 deleted with -d; a row without a mail_location is logged and left alone,
 and without -d nothing is deleted. With -p, the mail directories of the
 date directories before the cutoff (QUARANTINE_DAYS ago, at least one day
 ago) that no row names, through any path to them, are removed with -d and
 listed with -s; a date that cannot be looked up is logged and left; then
 the empty date directories are pruned. Without QUARANTINE_DIR there is
 nothing to sweep. As a command line: no user scope on the rows.
*/

namespace Tests\Unit\Console;

use App\Console\CronQuarantine;
use App\Core\App;
use App\Core\AppContainer;
use App\Core\Database\MigrationStatus;
use App\Models\MailLog;
use Illuminate\Database\Eloquent\Model;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Unit\RecordingDatabase;
use Tests\Unit\UnitTestCase;

// as a cron: a command line, each test in a process of its own
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CronQuarantineTest extends UnitTestCase
{
	private RecordingDatabase $db;

	private string $dir = '';

	/** @var list<array<string, mixed>> the mail_logs rows the quarantine query finds */
	private array $quarantined = [];

	/** @var array<string, list<?string>> date => the mail_location of the rows under it */
	private array $locations = [];

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		define('CLI_MODE', true);
		$this->db = new RecordingDatabase($this->createStub(PDO::class));
		$this->db->capsule->bootEloquent();
		$this->db->affect = fn (): int => 1;
		$this->db->answer = function (string $sql, array $bindings): array {
			if (str_starts_with($sql, 'select `mail_location` from `mail_logs`')) {
				/** @var mixed $like */
				$like = $bindings[0] ?? null;
				$day = is_string($like) ? trim($like, '%/') : '';
				return array_map(fn (?string $location): array => ['mail_location' => $location], $this->locations[$day] ?? throw new RuntimeException("Lost connection reading {$day}"));
			}
			return str_contains($sql, 'from `mail_logs`') ? $this->quarantined : [];
		};
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, $this->db->capsule, new MigrationStatus(null, $this->log), null, false));
		$this->dir = sys_get_temp_dir() . '/rqwatch-cqd-' . bin2hex(random_bytes(4));
		mkdir("{$this->dir}/q", 0777, true);
		$this->setEnv('QUARANTINE_DIR', "{$this->dir}/q");
		$this->setEnv('QUARANTINE_DAYS', null);
		$this->setEnv('MY_API_SERVER_ALIAS', 'mx1');
		$this->setEnv('NOTIFICATION_RELEASE_LINKS', null);
		$this->setEnv('DEBUG_SEARCH_SQL', null);
		// without a warning: a directory may be missing; one silenced with @ stays silent
		set_error_handler(fn (int $no, string $message): bool => (error_reporting() & $no) === 0 ? false : throw new \ErrorException($message, 0, $no));
	}

	#[\Override]
	protected function tearDown(): void {
		restore_error_handler();
		self::remove($this->dir);
		Model::unsetConnectionResolver();
		RecordingDatabase::forget();
		parent::tearDown();
	}

	private static function remove(string $path): void {
		if (is_link($path) || is_file($path)) {
			unlink($path);
			return;
		}
		if (!is_dir($path)) {
			return;
		}
		$entries = scandir($path);
		foreach ($entries === false ? [] : array_diff($entries, ['.', '..']) as $entry) {
			self::remove("{$path}/{$entry}");
		}
		rmdir($path);
	}

	// a mail directory under the quarantine, as store_raw_mail() makes it
	private function mail(string $path): string {
		mkdir("{$this->dir}/q/{$path}", 0777, true);
		file_put_contents("{$this->dir}/q/{$path}/mail.eml", 'mail');
		return "{$this->dir}/q/{$path}";
	}

	private static function daysAgo(int $days): string {
		return date('Y-m-d', (int) strtotime("-{$days} days"));
	}

	/**
	 * @param array<string, true> $options
	 * @return array{int, string}
	 */
	private static function cron(array $options, int $verbosity = OutputInterface::VERBOSITY_VERBOSE): array {
		$tester = new CommandTester(new CronQuarantine());
		$status = $tester->execute($options, ['verbosity' => $verbosity]);
		return [$status, $tester->getDisplay()];
	}

	// the quarantine cutoff, as said
	private function cutoff(string $display): string {
		if (preg_match('/ quarantine before (\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $display, $match) !== 1) {
			$this->fail("No cutoff in: {$display}");
		}
		return $match[1];
	}

	private static function quarantineQuery(): string {
		return 'select `' . implode('`, `', MailLog::SELECT_FIELDS) . '` from `mail_logs` where `mail_stored` = ? and `created_day` < ?';
	}

	public function testAlreadyRunning(): void {
		$running = new CronQuarantine();
		$this->assertTrue((new ReflectionMethod($running, 'lock'))->invoke($running));
		try {
			$this->assertSame([Command::FAILURE, "Already running in another process.\n"], self::cron([]));
		} finally {
			(new ReflectionMethod($running, 'release'))->invoke($running);
		}
		$this->assertSame(['warning cron:quarantine Already running in another process'], $this->log->lines);
		$this->assertSame([], $this->db->selects);
	}

	public function testNothingToClean(): void {
		$before = date('Y-m-d H:i:s', (int) strtotime('-366 days'));
		[$status, $display] = self::cron([]);
		$after = date('Y-m-d H:i:s', (int) strtotime('-366 days'));
		$cutoff = $this->cutoff($display);
		$this->assertGreaterThanOrEqual($before, $cutoff);
		$this->assertLessThanOrEqual($after, $cutoff);
		$this->assertSame([Command::SUCCESS, "No entries found in quarantine before {$cutoff}\n"], [$status, $display]);
		$this->assertSame(["debug cron:quarantine No entries found in quarantine before {$cutoff}"], $this->log->lines);
		$this->assertSame([[self::quarantineQuery(), [1, self::daysAgo(366)]]], $this->db->selects);
		// the runtime, very verbosely
		$this->assertMatchesRegularExpression('/\nExecution time: [^\n]+\n$/D', self::cron([], OutputInterface::VERBOSITY_VERY_VERBOSE)[1]);
	}

	// this server's only, QUARANTINE_DAYS ago
	public function testLocal(): void {
		$this->setEnv('QUARANTINE_DAYS', '30');
		[, $display] = self::cron(['-l' => true]);
		$cutoff = $this->cutoff($display);
		$this->assertSame(self::daysAgo(30), substr($cutoff, 0, 10));
		$this->assertSame("No entries found in quarantine before {$cutoff} on server: mx1\n", $display);
		$this->assertSame(["debug cron:quarantine No entries found in quarantine before {$cutoff} on server: mx1"], $this->log->lines);
		$this->assertSame([[self::quarantineQuery() . ' and `server` = ?', [1, self::daysAgo(30), 'mx1']]], $this->db->selects);
	}

	public function testNoLocation(): void {
		$this->quarantined = [['id' => 3, 'qid' => 'Q3', 'server' => 'mx1', 'mail_location' => null], ['id' => 4, 'qid' => 'Q4', 'server' => 'mx2', 'mail_location' => null]];
		[$status, $display] = self::cron(['-d' => true, '-l' => true]);
		$cutoff = $this->cutoff($display);
		$this->assertSame([Command::SUCCESS,
			"2 entries found in quarantine before {$cutoff} on server: mx1\n"
			. "Empty `mail_location` for id: 3, disabling quarantine clean on server: mx1\n"
			. "Empty `mail_location` for id: 4, disabling quarantine clean on server: mx1\n"
			. "No entries remain with a valid mail_location on server: mx1\n",
		], [$status, $display]);
		$this->assertSame([
			"info cron:quarantine 2 entries found in quarantine before {$cutoff} on server: mx1",
			'warning cron:quarantine Empty `mail_location` for id: 3, disabling quarantine clean on server: mx1',
			'warning cron:quarantine Empty `mail_location` for id: 4, disabling quarantine clean on server: mx1',
			'warning cron:quarantine No entries remain with a valid mail_location on server: mx1',
		], $this->log->lines);
		$this->assertSame([], $this->db->changes);
		$this->assertSame([Command::SUCCESS, ''], self::cron(['-d' => true], OutputInterface::VERBOSITY_NORMAL));
	}

	public function testShowWithoutDelete(): void {
		$q1 = $this->mail('2025-01-02/Q1');
		$this->quarantined = [
			['id' => 1, 'qid' => 'Q1', 'server' => 'mx1', 'mail_location' => "{$q1}/mail.eml"],
			['id' => 2, 'qid' => 'Q2', 'server' => 'mx1', 'mail_location' => null],
			['id' => 5, 'qid' => 'Q5', 'server' => 'mx2', 'mail_location' => '/var/quarantine/2025-01-02/Q5/mail.eml'],
		];
		[$status, $display] = self::cron(['-s' => true, '-l' => true]);
		$cutoff = $this->cutoff($display);
		$this->assertSame([Command::SUCCESS,
			"3 entries found in quarantine before {$cutoff} on server: mx1\n"
			. "Empty `mail_location` for id: 2, disabling quarantine clean on server: mx1\n"
			. "2 entries remain with a valid mail_location on server: mx1\n"
			. "Quarantine pending delete on server: mx1:\nQID: Q1\nQID: Q5\n"
			. "Use -d to delete entries from quarantine on server: mx1\n",
		], [$status, $display]);
		$this->assertSame("info cron:quarantine 2 entries remain with a valid mail_location on server: mx1", $this->log->lines[2] ?? null);
		$this->assertSame([true, []], [is_file("{$q1}/mail.eml"), $this->db->changes]);
		// the list is shown at the normal verbosity, nothing else
		[, $quiet] = self::cron(['-s' => true], OutputInterface::VERBOSITY_NORMAL);
		$this->assertSame("Quarantine pending delete:\nQID: Q1\nQID: Q5\n", $quiet);
	}

	public function testDelete(): void {
		$q1 = $this->mail('2025-01-02/Q1');
		$this->quarantined = [['id' => 1, 'qid' => 'Q1', 'server' => 'mx1', 'mail_location' => "{$q1}/mail.eml"]];
		[$status, $display] = self::cron(['-d' => true]);
		$cutoff = $this->cutoff($display);
		$this->assertSame([Command::SUCCESS,
			"1 entries found in quarantine before {$cutoff}\n"
			. "1 entries remain with a valid mail_location\n"
			. "Deleted 1 {$q1}\n"
			. "Setting mail_stored to 0 on 1 entries\n",
		], [$status, $display]);
		$this->assertFalse(is_dir($q1));
		$this->assertSame([['update `mail_logs` set `mail_stored` = ?, `mail_logs`.`updated_at` = ? where `id` in (?)', 1]], array_map(fn (array $change): array => [$change[0], count($change[1]) - 2], $this->db->changes));
	}

	/** @return list<string> the date directories and mail directories left under the quarantine */
	private function left(): array {
		$left = [];
		$days = scandir("{$this->dir}/q");
		foreach ($days === false ? [] : array_diff($days, ['.', '..']) as $day) {
			$left[] = $day;
			$dirs = is_link("{$this->dir}/q/{$day}") ? false : @scandir("{$this->dir}/q/{$day}");
			foreach ($dirs === false ? [] : array_diff($dirs, ['.', '..']) as $dir) {
				$left[] = "{$day}/{$dir}";
			}
		}
		return $left;
	}

	public function testSweep(): void {
		$old = self::daysAgo(400);
		$kept = $this->mail("{$old}/Q1");
		$this->mail("{$old}/Q2");
		$this->mail("{$old}/unknown/0a1b");
		$moved = $this->mail("{$old}/Q3-0abc");
		$this->mail(self::daysAgo(366) . '/Q4');
		$this->mail('not-a-date/Q5');
		$this->mail('elsewhere/Q6');
		symlink("{$this->dir}/q/elsewhere", "{$this->dir}/q/" . self::daysAgo(500));
		// a date that is no directory
		file_put_contents("{$this->dir}/q/" . self::daysAgo(450), 'x');
		// the rows of the day: with no location, through a link to the quarantine, by a file now gone, or elsewhere
		symlink("{$this->dir}/q", "{$this->dir}/link");
		$this->locations[$old] = [null, "{$this->dir}/link/{$old}/Q1/mail.eml", "{$moved}/gone.eml", "{$this->dir}/none/{$old}/Q9/mail.eml"];
		[$status, $display] = self::cron(['-d' => true, '-p' => true]);
		$q = "{$this->dir}/q";
		$this->assertSame(Command::SUCCESS, $status);
		$this->assertStringEndsWith(
			"Removed unreferenced {$q}/{$old}/Q2\n"
			. "Removed unreferenced {$q}/{$old}/unknown/0a1b\n"
			. "Pruned empty quarantine group dir {$q}/{$old}/unknown\n",
			$display
		);
		$this->assertSame([self::daysAgo(500), self::daysAgo(450), $old, "{$old}/Q1", "{$old}/Q3-0abc", self::daysAgo(366), self::daysAgo(366) . '/Q4', 'elsewhere', 'elsewhere/Q6', 'not-a-date', 'not-a-date/Q5'], $this->left());
		$this->assertTrue(is_file("{$kept}/mail.eml"));
		$this->assertSame(['select `mail_location` from `mail_logs` where `mail_location` like ?', ["%/{$old}/%"]], $this->db->selects[1] ?? null);
		$this->assertCount(2, $this->db->selects);
		$this->assertSame([
			"info cron:quarantine removed unreferenced quarantine directory {$q}/{$old}/Q2",
			"info cron:quarantine removed unreferenced quarantine directory {$q}/{$old}/unknown/0a1b",
		], array_slice($this->log->lines, 1));
	}

	public function testSweepShown(): void {
		$old = self::daysAgo(400);
		$this->mail("{$old}/Q1");
		$this->mail("{$old}/Q2");
		$this->mail("{$old}/Q3");
		$this->locations[$old] = ["{$this->dir}/q/{$old}/Q1/mail.eml"];
		$q = "{$this->dir}/q";
		[, $shown] = self::cron(['-p' => true, '-s' => true], OutputInterface::VERBOSITY_NORMAL);
		[, $quiet] = self::cron(['-p' => true], OutputInterface::VERBOSITY_VERBOSE);
		$this->assertSame(["Unreferenced, would remove: {$q}/{$old}/Q2\nUnreferenced, would remove: {$q}/{$old}/Q3\n", 1], [$shown, substr_count($quiet, "\n")]);
		$this->assertSame([$old, "{$old}/Q1", "{$old}/Q2", "{$old}/Q3"], $this->left());
	}

	/** @return array<string, array{string, int, int}> QUARANTINE_DAYS, the days ago kept, swept */
	public static function cutoffs(): array {
		return [
			'the default' => ['', 366, 367],
			'three days' => ['3', 3, 4],
			'none: before yesterday' => ['0', 1, 2],
		];
	}

	#[DataProvider('cutoffs')]
	public function testSweepCutoff(string $days, int $kept, int $swept): void {
		$this->setEnv('QUARANTINE_DAYS', $days === '' ? null : $days);
		$this->mail(self::daysAgo($kept) . '/Q1');
		$this->mail(self::daysAgo($swept) . '/Q2');
		$this->locations[self::daysAgo($kept)] = [];
		$this->locations[self::daysAgo($swept)] = [];
		self::cron(['-d' => true, '-p' => true]);
		$this->assertSame([self::daysAgo($kept), self::daysAgo($kept) . '/Q1'], $this->left());
	}

	// a date that cannot be looked up is left; the next is swept
	public function testSweepLookupFails(): void {
		$first = self::daysAgo(401);
		$next = self::daysAgo(400);
		$this->mail("{$first}/Q1");
		$this->mail("{$next}/Q2");
		$this->locations[$next] = [];
		// what was removed is said only verbosely
		$this->assertSame([Command::SUCCESS, ''], self::cron(['-d' => true, '-p' => true], OutputInterface::VERBOSITY_NORMAL));
		$this->assertSame([$first, "{$first}/Q1"], $this->left());
		$this->assertSame("error cron:quarantine cannot read the mail locations of {$first}, not sweeping it: Lost connection reading {$first}", $this->log->lines[1] ?? null);
	}

	/** @return array<string, array{?string}> */
	public static function noRoot(): array {
		return ['not set' => [null], 'empty' => [''], 'missing' => ['{dir}/none']];
	}

	#[DataProvider('noRoot')]
	public function testSweepWithoutRoot(?string $root): void {
		$this->setEnv('QUARANTINE_DIR', $root === null ? null : str_replace('{dir}', $this->dir, $root));
		$this->mail(self::daysAgo(400) . '/Q1');
		[$status] = self::cron(['-d' => true, '-p' => true]);
		$this->assertSame([Command::SUCCESS, [self::daysAgo(400), self::daysAgo(400) . '/Q1']], [$status, $this->left()]);
		$this->assertCount(1, $this->db->selects);
	}
}
