<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The cleanup crons' side of MailLogService, on RecordingDatabase and a temp
 QUARANTINE_DIR. Mails older than DATABASE_DAYS (366 by default), of one
 server when given (an empty name is refused), are deleted in batches
 (CLEANDB_CHUNK when below 1), each pass the first ids still matching,
 until none match or a pass deletes nothing. Mail stored longer than
 QUARANTINE_DAYS (366 by default) is read with its recipients; cleaning it
 deletes each mail's directory and marks not stored every mail whose
 directory was deleted, or, for this node's own mail, is already gone from
 a quarantine that is there and not empty; with release links their
 tokens are deleted too. A directory that cannot be deleted keeps its mail
 stored. The verbose CLI output, or the log, shows the queries with
 DEBUG_SEARCH_SQL and what was done.
*/

namespace Tests\Unit\Services;

use App\Core\App;
use App\Core\AppContainer;
use App\Core\Database\MigrationStatus;
use App\Inventory\Migrations;
use App\Models\MailLog;
use App\Services\MailLogService;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Tests\Unit\RecordingDatabase;
use Tests\Unit\UnitTestCase;

final class MailLogCleanupTest extends UnitTestCase
{
	private RecordingDatabase $db;

	private MigrationStatus $status;

	private string $dir = '';

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->db = new RecordingDatabase($this->createStub(PDO::class));
		$this->db->capsule->bootEloquent();
		$this->status = new MigrationStatus(null, $this->log);
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, $this->db->capsule, $this->status, null, false));
		$this->dir = sys_get_temp_dir() . '/rqwatch-mc-' . bin2hex(random_bytes(4));
		mkdir("{$this->dir}/q/2026-01-02/Q1", 0777, true);
		mkdir("{$this->dir}/outside/Q5", 0777, true);
		file_put_contents("{$this->dir}/q/2026-01-02/Q1/Q1", 'mail');
		$this->setEnv('QUARANTINE_DIR', "{$this->dir}/q");
		$this->setEnv('MY_API_SERVER_ALIAS', 'mx1');
		$this->setEnv('DATABASE_DAYS', null);
		$this->setEnv('QUARANTINE_DAYS', null);
		$this->setEnv('NOTIFICATION_RELEASE_LINKS', null);
		// without a warning: a row may lack any key; one silenced with @ stays silent
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

	private static function admin(): MailLogService {
		return new MailLogService(['is_admin' => true, 'email' => 'root@example.org']);
	}

	private static function daysAgo(int $days): string {
		return (new DateTimeImmutable())->sub(new DateInterval("P{$days}D"))->format('Y-m-d');
	}

	/** @return array<string, array{int, int, list<list<int>>}> batch, the limit used, the ids deleted per pass */
	public static function batches(): array {
		return [
			'two of five, then one' => [2, 2, [[1, 2], [3, 4], [5]]],
			'0: CLEANDB_CHUNK' => [0, 1000, [[1, 2, 3, 4, 5]]],
			'one at a time' => [1, 1, [[1], [2], [3], [4], [5]]],
		];
	}

	/** @param list<list<int>> $passes */
	#[DataProvider('batches')]
	public function testCleanDb(int $batch, int $limit, array $passes): void {
		$left = [1, 2, 3, 4, 5];
		$this->db->answer = function (string $sql, array $bindings) use (&$left, $limit): array {
			return array_map(fn (int $id): array => ['id' => $id], array_slice($left, 0, $limit));
		};
		$this->db->affect = function (string $sql, array $bindings) use (&$left): int {
			$left = array_values(array_filter($left, fn (int $id): bool => !in_array($id, $bindings, true)));
			return count($bindings);
		};
		$this->assertSame(5, self::admin()->cleanDb(MailLog::select('id')->where('created_day', '<', '2025-01-01'), $batch));
		$this->assertSame(
			array_fill(0, count($passes) + 1, ['select "id" from "mail_logs" where "created_day" < ? order by "id" asc limit ' . $limit, ['2025-01-01']]),
			$this->db->selects
		);
		$this->assertSame(
			array_map(fn (array $ids): array => ['delete from "mail_logs" where "id" in (' . implode(', ', array_fill(0, count($ids), '?')) . ')', $ids], $passes),
			$this->db->changes
		);
	}

	// a pass that deletes nothing stops the cleanup, the ids still matching
	public function testCleanDbStuck(): void {
		// still matching on the next two passes, should the cleanup go on
		$this->db->answer = fn (): array => count($this->db->selects) <= 3 ? [['id' => 1], ['id' => 2]] : [];
		$this->db->affect = fn (string $sql): int => count($this->db->changes) === 1 ? 2 : 0;
		$this->assertSame(2, self::admin()->cleanDb(MailLog::select('id'), 2));
		$this->assertCount(2, $this->db->changes);
	}

	public function testCleanNothing(): void {
		$this->assertSame(0, self::admin()->cleanDb(MailLog::select('id'), 10));
		$this->assertSame([[], 1], [$this->db->changes, count($this->db->selects)]);
	}

	/** @return array<string, array{?string, int}> DATABASE_DAYS, the days used */
	public static function databaseDays(): array {
		return [
			'not set: 366' => [null, 366],
			'30 days' => ['30', 30],
			'with a newline' => ["30\n", 30],
		];
	}

	#[DataProvider('databaseDays')]
	public function testCleanUpDb(?string $days, int $used): void {
		$this->setEnv('DATABASE_DAYS', $days);
		$query = self::admin()->getCleanUpDb(null, 'mx2');
		$this->assertSame(
			['select "id", "qid" from "mail_logs" where "created_day" < ? and "server" = ?', [self::daysAgo($used), 'mx2']],
			[$query->toSql(), $query->getBindings()]
		);
	}

	public function testCleanUpDbScope(): void {
		$query = (new MailLogService(['is_admin' => false, 'email' => 'alice@example.org']))->getCleanUpDb();
		$this->assertSame(
			'select "id", "qid" from "mail_logs" where "created_day" < ? and exists (select 1 from "mail_log_recipients" as "r" where "r"."mail_log_id" = "mail_logs"."id" and "r"."recipient_email" in (?))',
			$query->toSql()
		);
	}

	public function testCleanUpDbEmptyServer(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('getCleanUpDb(): server set but is empty');
		self::admin()->getCleanUpDb(null, '');
	}

	public function testCleanUpDbDebug(): void {
		$this->setEnv('DEBUG_SEARCH_SQL', 'true');
		$verbose = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
		$quiet = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL);
		self::admin()->getCleanUpDb($verbose);
		self::admin()->getCleanUpDb($quiet);
		self::admin()->getCleanUpDb();
		$sql = 'select "id", "qid" from "mail_logs" where "created_day" < "' . self::daysAgo(366) . '"';
		$this->assertSame(["{$sql}\n", '', ["info {$sql}"]], [$verbose->fetch(), $quiet->fetch(), $this->log->lines]);
	}

	/** @return array<string, array{?string, int}> QUARANTINE_DAYS, the days used */
	public static function quarantineDays(): array {
		return [
			'not set: 366' => [null, 366],
			'30 days' => ['30', 30],
			'with a newline' => ["30\n", 30],
		];
	}

	#[DataProvider('quarantineDays')]
	public function testQuarantine(?string $days, int $used): void {
		$this->setEnv('QUARANTINE_DAYS', $days);
		$this->db->answer = fn (string $sql): array => str_contains($sql, 'from "mail_logs"') ? [['id' => 7, 'qid' => 'Q7']] : [];
		$this->assertSame([7], self::admin()->getQuarantine(null, 'mx2')->pluck('id')->all());
		$fields = 'select "' . implode('", "', MailLog::SELECT_FIELDS) . '" from "mail_logs"';
		$this->assertSame([
			[$fields . ' where "mail_stored" = ? and "created_day" < ? and "server" = ?', [1, self::daysAgo($used), 'mx2']],
			['select * from "mail_log_recipients" where "mail_log_recipients"."mail_log_id" in (7)', []],
		], $this->db->selects);
	}

	public function testQuarantineScopeAndDebug(): void {
		$this->setEnv('DEBUG_SEARCH_SQL', 'true');
		$verbose = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
		$quiet = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL);
		$alice = new MailLogService(['is_admin' => false, 'email' => 'alice@example.org']);
		$alice->getQuarantine($verbose);
		$alice->getQuarantine($quiet);
		$alice->getQuarantine();
		$sql = 'select "' . implode('", "', MailLog::SELECT_FIELDS) . '" from "mail_logs" where "mail_stored" = "1" and "created_day" < "' . self::daysAgo(366) . '"'
			. ' and exists (select 1 from "mail_log_recipients" as "r" where "r"."mail_log_id" = "mail_logs"."id" and "r"."recipient_email" in ("alice@example.org"))';
		$this->assertSame(["{$sql}\n", '', ["info {$sql}"]], [$verbose->fetch(), $quiet->fetch(), $this->log->lines]);
	}

	private static function mail(int $id, string $server, ?string $location): MailLog {
		$mail = new MailLog();
		$mail->setRawAttributes(['id' => $id, 'qid' => "Q{$id}", 'server' => $server, 'mail_location' => $location], true);
		return $mail;
	}

	/**
	 * @param array<int, MailLog> $mails
	 * @return Collection<int, MailLog>
	 */
	private static function collection(array $mails): Collection {
		return new Collection($mails);
	}

	/** @return Collection<int, MailLog> */
	private function quarantined(): Collection {
		return self::collection([
			// deleted
			self::mail(1, 'mx1', "{$this->dir}/q/2026-01-02/Q1/Q1"),
			// no location
			self::mail(2, 'mx1', null),
			self::mail(3, 'mx1', ''),
			// this node's, already gone
			self::mail(4, 'mx1', "{$this->dir}/q/2026-01-03/Q4/Q4"),
			// outside QUARANTINE_DIR: not deleted
			self::mail(5, 'mx1', "{$this->dir}/outside/Q5/Q5"),
			// another node's, not here
			self::mail(6, 'mx2', "{$this->dir}/q/2026-01-03/Q6/Q6"),
		]);
	}

	public function testCleanQuarantine(): void {
		$this->setEnv('NOTIFICATION_RELEASE_LINKS', 'true');
		$this->status->setMigrationState(Migrations::MAIL_LOG_TOKENS, Migrations::STATUS_COMPLETED);
		$this->db->affect = fn (string $sql): int => str_contains($sql, 'mail_log_tokens') ? 3 : 2;
		$output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
		self::admin()->cleanQuarantine($this->quarantined(), $output);
		$q = "{$this->dir}/q";
		$this->assertSame([false, true, true], [is_dir("{$q}/2026-01-02/Q1"), is_dir("{$q}/2026-01-02"), is_dir("{$this->dir}/outside/Q5")]);
		$this->assertSame([
			['update "mail_logs" set "mail_stored" = ?, "mail_logs"."updated_at" = ? where "id" in (?, ?)', 0, [1, 4]],
			['delete from "mail_log_tokens" where "mail_log_id" in (?, ?)', null, [1, 4]],
		], [
			[$this->db->changes[0][0], $this->db->changes[0][1][0], array_slice($this->db->changes[0][1], 2)],
			[$this->db->changes[1][0] ?? '', null, $this->db->changes[1][1] ?? []],
		]);
		$this->assertCount(2, $this->db->changes);
		$this->assertSame(
			"Deleted 1 {$q}/2026-01-02/Q1\n"
			. "Already gone 4 {$q}/2026-01-03/Q4, marking it not stored\n"
			. "Not deleted 5 {$this->dir}/outside/Q5, see the log\n"
			. "Not deleted 6 {$q}/2026-01-03/Q6, see the log\n"
			. "Setting mail_stored to 0 on 2 entries\n"
			. "Deleted 3 notification token(s)\n",
			$output->fetch()
		);
		$real = (string) realpath($this->dir);
		$this->assertSame([
			"info [cleanQuarantine] Deleted mail Q1 from {$q}/2026-01-02/Q1",
			"warning Quarantine directory problem: {$q}/2026-01-03/Q4",
			"warning [cleanQuarantine] Q4 {$q}/2026-01-03/Q4 is already gone, marking it not stored",
			"warning Quarantine {$real}/outside/Q5 is not inside QUARANTINE_DIR",
			"warning Quarantine directory problem: {$q}/2026-01-03/Q6",
		], $this->log->lines);
	}

	// without release links, or before their table, no tokens; nothing deleted, no token line
	public function testCleanQuarantineWithoutTokens(): void {
		$this->setEnv('NOTIFICATION_RELEASE_LINKS', 'true');
		$this->db->affect = fn (): int => 1;
		$output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
		self::admin()->cleanQuarantine(self::collection([self::mail(1, 'mx1', "{$this->dir}/q/2026-01-02/Q1/Q1")]), $output);
		$this->assertCount(1, $this->db->changes);
		$this->assertStringEndsWith("Setting mail_stored to 0 on 1 entries\n", $output->fetch());
	}

	/** @return array<string, array{int, non-empty-string}> tokens deleted, the output's end */
	public static function tokensDeleted(): array {
		return [
			'none' => [0, "Setting mail_stored to 0 on 1 entries\n"],
			'one' => [1, "Setting mail_stored to 0 on 1 entries\nDeleted 1 notification token(s)\n"],
		];
	}

	/** @param non-empty-string $end */
	#[DataProvider('tokensDeleted')]
	public function testCleanQuarantineTokens(int $tokens, string $end): void {
		$this->setEnv('NOTIFICATION_RELEASE_LINKS', 'true');
		$this->status->setMigrationState(Migrations::MAIL_LOG_TOKENS, Migrations::STATUS_COMPLETED);
		$this->db->affect = fn (string $sql): int => str_contains($sql, 'mail_log_tokens') ? $tokens : 1;
		$output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
		self::admin()->cleanQuarantine(self::collection([self::mail(1, 'mx1', "{$this->dir}/q/2026-01-02/Q1/Q1")]), $output);
		$this->assertCount(2, $this->db->changes);
		$this->assertStringEndsWith($end, $output->fetch());
	}

	public function testCleanQuarantineRelaseLinksOff(): void {
		$this->status->setMigrationState(Migrations::MAIL_LOG_TOKENS, Migrations::STATUS_COMPLETED);
		self::admin()->cleanQuarantine(self::collection([self::mail(1, 'mx1', "{$this->dir}/q/2026-01-02/Q1/Q1")]));
		$this->assertCount(1, $this->db->changes);
	}

	// no output asked for: the work and the log the same
	public function testCleanQuarantineQuietly(): void {
		self::admin()->cleanQuarantine($this->quarantined());
		$this->assertSame([false, [1, 4]], [is_dir("{$this->dir}/q/2026-01-02/Q1"), array_slice($this->db->changes[0][1] ?? [], 2)]);
		$this->assertCount(5, $this->log->lines);
	}

	public function testCleanNoQuarantine(): void {
		$output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
		self::admin()->cleanQuarantine(self::collection([self::mail(2, 'mx1', null)]), $output);
		$this->assertSame([[], ''], [$this->db->changes, $output->fetch()]);
	}

	/** @return array<string, array{string, string, bool}> QUARANTINE_DIR (under the temp dir), the mail directory, gone */
	public static function gone(): array {
		return [
			'gone' => ['q', 'q/2026-01-03/Q4', true],
			'QUARANTINE_DIR with a slash' => ['q/', 'q/2026-01-03/Q4', true],
			'there' => ['q', 'q/2026-01-02/Q1', false],
			'a dangling link' => ['q', 'q/link', false],
			'outside QUARANTINE_DIR' => ['q', 'outside/Q9', false],
			'sharing its prefix' => ['q', 'q-old/Q9', false],
			'through ..' => ['q', 'q/2026-01-02/../../Q9', false],
			'QUARANTINE_DIR missing' => ['none', 'none/Q9', false],
			'QUARANTINE_DIR empty (unmounted)' => ['empty', 'empty/Q9', false],
		];
	}

	#[DataProvider('gone')]
	public function testQuarantineDirGone(string $root, string $dir, bool $gone): void {
		mkdir("{$this->dir}/empty");
		symlink("{$this->dir}/nowhere", "{$this->dir}/q/link");
		$this->setEnv('QUARANTINE_DIR', "{$this->dir}/{$root}");
		$this->assertSame($gone, (new ReflectionMethod(MailLogService::class, 'quarantineDirGone'))->invoke(null, "{$this->dir}/{$dir}"));
	}

	/** @return array<string, array{?string}> */
	public static function noRoot(): array {
		return [
			'not set' => [null],
			'empty' => [''],
			'only a slash' => ['/'],
		];
	}

	#[DataProvider('noRoot')]
	public function testQuarantineDirGoneWithoutRoot(?string $root): void {
		$this->setEnv('QUARANTINE_DIR', $root);
		$this->assertFalse((new ReflectionMethod(MailLogService::class, 'quarantineDirGone'))->invoke(null, '/Q9'));
	}
}
