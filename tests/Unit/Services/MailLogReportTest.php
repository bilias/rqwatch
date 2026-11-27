<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 MailLogService's reports, daily totals and search statistics, on
 RecordingDatabase. Every query is scoped to the user: an admin and the
 CLI see every mail, a user without an email none, a user the mails to
 their own address or aliases, and in the per-recipient reports only the
 rows of those addresses. A report groups by one of MailLog::REPORT_FIELDS
 (a domain from the address after the last @, a month by created_day's
 year and month, counting mail ever stored once migrated); any other
 field, or an admin field for a user, is refused with no query. Rows are
 ordered by count, size, stored or, for the date by day or month, date,
 top_reports of them (30 by default). The statistics count the matching
 mails, read the first and last mail's date by id, count the stored,
 notified, released and virus mails and the mails per action (the five
 actions always present, '' for none); a timeout is a QueryTimeoutException,
 any other failure logged and null. DEBUG_SEARCH_SQL logs the queries.
*/

namespace Tests\Unit\Services;

use App\Configuration\Config;
use App\Core\App;
use App\Core\AppContainer;
use App\Core\Database\MigrationStatus;
use App\Core\Exception\QueryTimeoutException;
use App\Inventory\Migrations;
use App\Services\MailLogService;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use ReflectionMethod;
use RuntimeException;
use Tests\Unit\RecordingDatabase;
use Tests\Unit\UnitTestCase;
use Throwable;

final class MailLogReportTest extends UnitTestCase
{
	private const string TOTALS = ', COUNT(*) AS total, SUM(size) as total_size, SUM(mail_stored) AS total_stored from "mail_logs"';
	private const string JOIN = ' inner join "mail_log_recipients" as "mlr" on "mlr"."mail_log_id" = "mail_logs"."id"';
	private const string OWN = 'exists (select 1 from "mail_log_recipients" as "r" where "r"."mail_log_id" = "mail_logs"."id" and "r"."recipient_email" in (?, ?))';
	private const string ALICE = 'alice@example.org';
	private const string ALIAS = 'al@example.org';

	private RecordingDatabase $db;

	private MigrationStatus $status;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->db = new RecordingDatabase($this->createStub(PDO::class));
		$this->db->capsule->bootEloquent();
		$this->status = new MigrationStatus(null, $this->log);
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, $this->db->capsule, $this->status, null, false));
		// without a warning: a row or a lookup may lack any key
		set_error_handler(fn (int $no, string $message): never => throw new \ErrorException($message, 0, $no));
	}

	#[\Override]
	protected function tearDown(): void {
		restore_error_handler();
		Model::unsetConnectionResolver();
		RecordingDatabase::forget();
		parent::tearDown();
	}

	private static function admin(): MailLogService {
		return new MailLogService(['is_admin' => true, 'email' => 'root@example.org']);
	}

	private static function alice(): MailLogService {
		return new MailLogService(['is_admin' => false, 'email' => self::ALICE, 'user_aliases' => [self::ALIAS]]);
	}

	/** @param Closure(string, list<mixed>): list<array<string, mixed>> $answer */
	private function answer(Closure $answer): void {
		$this->db->answer = $answer;
	}

	// a query that fails with this driver error code
	private function failWith(mixed $code): QueryException {
		$pdo = new PDOException('Query execution was interrupted (max_statement_time exceeded)');
		$pdo->errorInfo = ['70100', $code, 'Query execution was interrupted (max_statement_time exceeded)'];
		$e = new QueryException('default', 'select 1', [], $pdo);
		$this->answer(fn (): never => throw $e);
		return $e;
	}

	/** @return list<array{string, list<mixed>}> */
	private function selects(): array {
		return $this->db->selects;
	}

	/** @return array<string, array{array{is_admin?: bool, email?: string, user_aliases?: array<int, string>}, string, list<string>}> context, where, bindings */
	public static function userScopes(): array {
		return [
			'an admin' => [['is_admin' => true, 'email' => 'root@example.org'], '', []],
			'no email' => [['is_admin' => true], ' and "id" is null', []],
			"'0' as email" => [['is_admin' => false, 'email' => '0'], ' and "id" is null', []],
			'a user: own addresses' => [['is_admin' => false, 'email' => self::ALICE, 'user_aliases' => [self::ALIAS]], ' and ' . self::OWN, [self::ALICE, self::ALIAS]],
		];
	}

	/**
	 * @param array{is_admin?: bool, email?: string, user_aliases?: array<int, string>} $context
	 * @param list<string> $bindings
	 */
	#[DataProvider('userScopes')]
	public function testDailyTotalsScope(array $context, string $where, array $bindings): void {
		$this->answer(fn (): array => [['day' => '2026-11-02', 'total' => 4], ['day' => '2026-11-03', 'total' => '1']]);
		$this->assertSame(['2026-11-02' => 4, '2026-11-03' => 1], (new MailLogService($context))->getDailyTotals('2026-11-01', '2026-11-30'));
		$this->assertSame(
			[['select created_day AS day, COUNT(*) AS total from "mail_logs" where "created_day" between ? and ?' . $where . ' group by "created_day"', ['2026-11-01', '2026-11-30', ...$bindings]]],
			$this->selects()
		);
		$this->assertSame([], $this->log->lines);
	}

	public function testDailyTotalsDebug(): void {
		$this->setEnv('DEBUG_SEARCH_SQL', 'true');
		$this->assertSame([], self::admin()->getDailyTotals('2026-11-01', '2026-11-30'));
		$this->assertSame(['info select created_day AS day, COUNT(*) AS total from "mail_logs" where "created_day" between "2026-11-01" and "2026-11-30" group by "created_day"'], $this->log->lines);
	}

	/** @return array<string, array{array{is_admin?: bool, email?: string, user_aliases?: array<int, string>}, string, list<string>}> context, where, bindings */
	public static function recipientScopes(): array {
		return [
			'an admin' => [['is_admin' => true, 'email' => 'root@example.org'], '', []],
			"'0' as email" => [['is_admin' => false, 'email' => '0'], ' where "id" is null and "id" is null', []],
			'a user: own addresses only' => [['is_admin' => false, 'email' => self::ALICE, 'user_aliases' => [self::ALIAS]],
				' where ' . self::OWN . ' and "mlr"."recipient_email" in (?, ?)', [self::ALICE, self::ALIAS, self::ALICE, self::ALIAS]],
		];
	}

	/**
	 * @param array{is_admin?: bool, email?: string, user_aliases?: array<int, string>} $context
	 * @param list<string> $bindings
	 */
	#[DataProvider('recipientScopes')]
	public function testRecipientReportScope(array $context, string $where, array $bindings): void {
		(new MailLogService($context))->getReports([], 'mail_recipients');
		$this->assertSame(
			[['select mlr.recipient_email AS mail_recipients' . self::TOTALS . self::JOIN . $where . ' group by "mlr"."recipient_email" order by "total" desc limit 30', $bindings]],
			$this->selects()
		);
	}

	public function testRecipientDomainReportScope(): void {
		self::alice()->getReports([], 'mail_recipients_domain');
		$this->assertSame(
			[["select LOWER(TRIM(TRAILING '>' FROM SUBSTRING_INDEX(mlr.recipient_email, '@', -1))) AS mail_recipients_domain" . self::TOTALS . self::JOIN
				. ' where "mlr"."recipient_email" LIKE ? and ' . self::OWN . ' and "mlr"."recipient_email" in (?, ?)'
				. ' group by "mail_recipients_domain" order by "total" desc limit 30', ['%@%', self::ALICE, self::ALIAS, self::ALICE, self::ALIAS]]],
			$this->selects()
		);
	}

	// another field: the user's mails, but no recipient join to scope
	public function testUserReportScope(): void {
		self::alice()->getReports([['filter' => 'Subject', 'choice' => 'contains', 'value' => 'invoice']], 'ip');
		$this->assertSame(
			[['select ip AS ip' . self::TOTALS . ' where "subject" LIKE ? and ' . self::OWN . ' group by "ip" order by "total" desc limit 30', ['%invoice%', self::ALICE, self::ALIAS]]],
			$this->selects()
		);
	}

	// the CLI sees every mail and every field, as an admin
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testCliScope(): void {
		define('CLI_MODE', true);
		$alice = self::alice();
		$alice->getReports([], 'server');
		$alice->getReports([], 'mail_recipients');
		$alice->getDailyTotals('2026-11-01', '2026-11-30');
		$this->assertSame([
			['select server AS server' . self::TOTALS . ' group by "server" order by "total" desc limit 30', []],
			['select mlr.recipient_email AS mail_recipients' . self::TOTALS . self::JOIN . ' group by "mlr"."recipient_email" order by "total" desc limit 30', []],
			['select created_day AS day, COUNT(*) AS total from "mail_logs" where "created_day" between ? and ? group by "created_day"', ['2026-11-01', '2026-11-30']],
		], $this->selects());
	}

	/** @return array<string, array{string, string, list<string>}> field, the query up to its order, bindings */
	public static function reportFields(): array {
		$domain = fn (string $column, string $field): string => "select LOWER(TRIM(TRAILING '>' FROM SUBSTRING_INDEX({$column}, '@', -1))) AS {$field}";
		return [
			'mail_from_domain' => ['mail_from_domain', $domain('mail_from', 'mail_from_domain') . self::TOTALS . ' where "mail_from" LIKE ? group by "mail_from_domain"', ['%@%']],
			'mime_from_domain' => ['mime_from_domain', $domain('mime_from', 'mime_from_domain') . self::TOTALS . ' where "mime_from" LIKE ? group by "mime_from_domain"', ['%@%']],
			'mime_to_domain' => ['mime_to_domain', $domain('mime_to', 'mime_to_domain') . self::TOTALS . ' where "mime_to" LIKE ? group by "mime_to_domain"', ['%@%']],
			'mail_recipients' => ['mail_recipients', 'select mlr.recipient_email AS mail_recipients' . self::TOTALS . self::JOIN . ' group by "mlr"."recipient_email"', []],
			'mail_recipients_domain' => ['mail_recipients_domain', $domain('mlr.recipient_email', 'mail_recipients_domain') . self::TOTALS . self::JOIN
				. ' where "mlr"."recipient_email" LIKE ? group by "mail_recipients_domain"', ['%@%']],
			'date' => ['date', 'select created_day AS date' . self::TOTALS . ' group by "created_day"', []],
			'ip' => ['ip', 'select ip AS ip' . self::TOTALS . ' group by "ip"', []],
			'an admin field' => ['server', 'select server AS server' . self::TOTALS . ' group by "server"', []],
		];
	}

	/** @param list<string> $bindings */
	#[DataProvider('reportFields')]
	public function testReportField(string $field, string $sql, array $bindings): void {
		self::admin()->getReports([], $field);
		$this->assertSame([[$sql . ' order by "total" desc limit 30', $bindings]], $this->selects());
	}

	/** @return array<string, array{string, string, string}> field, mode, order */
	public static function orders(): array {
		return [
			'volume' => ['ip', 'volume', '"total_size" desc'],
			'stored' => ['ip', 'stored', '"total_stored" desc'],
			'count' => ['ip', 'count', '"total" desc'],
			'by day, not the date' => ['ip', 'day', '"total" desc'],
			'the date by day' => ['date', 'day', '"date" desc'],
			'the date by count' => ['date', 'count', '"total" desc'],
			'the date by volume' => ['date', 'volume', '"total_size" desc'],
		];
	}

	#[DataProvider('orders')]
	public function testReportOrder(string $field, string $mode, string $order): void {
		Config::set('top_reports', 5);
		self::admin()->getReports([], $field, $mode);
		$this->assertSame([[self::reportFields()[$field][1] . " order by {$order} limit 5", []]], $this->selects());
	}

	/** @return array<string, array{?string, string}> EVER_STORED's state, what counts as stored */
	public static function months(): array {
		return [
			'migrated' => [Migrations::STATUS_COMPLETED, 'ever_stored'],
			'not yet' => [Migrations::STATUS_RUNNING, "(mail_stored = 1 OR mail_location IS NOT NULL AND mail_location <> '0')"],
		];
	}

	#[DataProvider('months')]
	public function testReportByMonth(?string $state, string $stored): void {
		$this->status->setMigrationState(Migrations::EVER_STORED, $state);
		self::admin()->getReports([], 'date', 'month');
		$this->assertSame([[
			"select DATE_FORMAT(MIN(created_day), '%Y-%m') AS date, COUNT(*) AS total, SUM(size) as total_size, SUM({$stored}) AS total_stored from \"mail_logs\""
				. ' group by EXTRACT(YEAR_MONTH FROM created_day) order by "date" desc limit 30',
			[],
		]], $this->selects());
	}

	public function testReportRows(): void {
		$this->setEnv('DEBUG_SEARCH_SQL', 'true');
		$this->answer(fn (): array => [
			['ip' => '192.0.2.1', 'total' => 5, 'total_size' => '1000', 'total_stored' => 2],
			['ip' => null, 'total' => '1', 'total_size' => null, 'total_stored' => '0'],
		]);
		$this->assertSame(
			[
				['ip' => '192.0.2.1', 'total' => 5, 'total_size' => 1000, 'total_stored' => 2],
				['ip' => null, 'total' => 1, 'total_size' => null, 'total_stored' => 0],
			],
			self::admin()->getReports([['filter' => 'Subject', 'choice' => 'contains', 'value' => 'invoice']], 'ip')
		);
		$this->assertSame(['info select ip AS ip' . self::TOTALS . ' where "subject" LIKE "%invoice%" group by "ip" order by "total" desc limit 30'], $this->log->lines);
	}

	/** @return array<string, array{array{is_admin?: bool, email?: string}, string}> context, field */
	public static function refused(): array {
		return [
			'not a report field' => [['is_admin' => true, 'email' => 'root@example.org'], 'subject'],
			'an injection' => [['is_admin' => true, 'email' => 'root@example.org'], 'ip, (select 1)'],
			'an admin field, for a user' => [['is_admin' => false, 'email' => self::ALICE], 'server'],
		];
	}

	/** @param array{is_admin?: bool, email?: string} $context */
	#[DataProvider('refused')]
	public function testReportRefused(array $context, string $field): void {
		$this->assertSame([], (new MailLogService($context))->getReports([], $field));
		$this->assertSame([["error MailLogService_getReports rejected field: {$field}"], []], [$this->log->lines, $this->selects()]);
	}

	private const string MATCHING = 'from "mail_logs" where "subject" LIKE ? and ' . self::OWN;

	public function testStats(): void {
		$this->setEnv('DEBUG_SEARCH_SQL', 'yes');
		$this->answer(function (string $sql): array {
			return match (true) {
				str_contains($sql, 'MIN(id)') => [['cnt' => 4, 'first_id' => 10, 'last_id' => 20]],
				str_contains($sql, '"created_at", "id"') => [['created_at' => '2026-11-02 08:00:00', 'id' => 10], ['created_at' => '2026-11-26 17:30:00', 'id' => 20]],
				str_contains($sql, 'aggregate') => [['aggregate' => match (true) {
					str_ends_with($sql, '"mail_stored" = ?') => 3,
					str_ends_with($sql, '"notified" = ?') => 2,
					str_ends_with($sql, '"released" = ?') => 1,
					default => 0,
				}]],
				str_contains($sql, 'action, COUNT(*)') => [['action' => 'reject', 'cnt' => 2], ['action' => null, 'cnt' => 1], ['action' => 'greylist', 'cnt' => 1]],
				default => throw new \LogicException("Unexpected query: {$sql}"),
			};
		});
		$this->assertSame(
			[
				'count' => 4,
				'first' => '2026-11-02 08:00:00',
				'last' => '2026-11-26 17:30:00',
				'stored' => 3,
				'notified' => 2,
				'released' => 1,
				'has_virus' => 0,
				'action' => ['no action' => 0, 'add header' => 0, 'rewrite subject' => 0, 'reject' => 2, 'discard' => 0, '' => 1, 'greylist' => 1],
			],
			self::alice()->getStats([['filter' => 'Subject', 'choice' => 'contains', 'value' => 'invoice']])
		);
		$own = ['%invoice%', self::ALICE, self::ALIAS];
		$this->assertSame([
			['select COUNT(*) AS cnt, MIN(id) AS first_id, MAX(id) AS last_id ' . self::MATCHING . ' limit 1', $own],
			['select "created_at", "id" from "mail_logs" where "id" in (?, ?)', [10, 20]],
			['select count(*) as aggregate ' . self::MATCHING . ' and "mail_stored" = ?', [...$own, 1]],
			['select count(*) as aggregate ' . self::MATCHING . ' and "notified" = ?', [...$own, 1]],
			['select count(*) as aggregate ' . self::MATCHING . ' and "released" = ?', [...$own, 1]],
			['select count(*) as aggregate ' . self::MATCHING . ' and "has_virus" = ?', [...$own, 1]],
			['select "id", action, COUNT(*) as cnt ' . self::MATCHING . ' group by "action" order by "cnt" desc, "action" asc', $own],
		], $this->selects());
		$this->assertSame([
			'info select "id" from "mail_logs" where "subject" LIKE "%invoice%" and exists (select 1 from "mail_log_recipients" as "r" where "r"."mail_log_id" = "mail_logs"."id"'
				. ' and "r"."recipient_email" in ("alice@example.org", "al@example.org"))',
		], $this->log->lines);
	}

	// the first or last mail purged between the count and the dates; no action counted: the five, at 0
	public function testStatsWithoutADate(): void {
		$this->answer(fn (string $sql): array => match (true) {
			str_contains($sql, 'MIN(id)') => [['cnt' => 1, 'first_id' => 10, 'last_id' => 10]],
			str_contains($sql, 'aggregate') => [['aggregate' => 0]],
			default => [],
		});
		$stats = self::admin()->getStats([]);
		$this->assertNotNull($stats);
		$this->assertSame(
			[1, null, null, ['no action' => 0, 'add header' => 0, 'rewrite subject' => 0, 'reject' => 0, 'discard' => 0]],
			[$stats['count'], $stats['first'], $stats['last'], $stats['action']]
		);
	}

	public function testNoMatchingMail(): void {
		$this->answer(fn (): array => [['cnt' => 0, 'first_id' => null, 'last_id' => null]]);
		$this->assertSame(
			[
				'count' => 0,
				'last' => null,
				'first' => null,
				'stored' => 0,
				'notified' => 0,
				'released' => 0,
				'has_virus' => 0,
				'action' => ['no action' => 0, 'add header' => 0, 'rewrite subject' => 0, 'reject' => 0, 'discard' => 0],
			],
			self::admin()->getStats([])
		);
		$this->assertSame([['select COUNT(*) AS cnt, MIN(id) AS first_id, MAX(id) AS last_id from "mail_logs" limit 1', []]], $this->selects());
		$this->assertSame([], $this->log->lines);
	}

	/** @return array<string, array{int|string}> */
	public static function timeouts(): array {
		return [
			'the code' => [1969],
			'its digits' => ['1969'],
		];
	}

	#[DataProvider('timeouts')]
	public function testStatsTimeout(int|string $code): void {
		$e = $this->failWith($code);
		try {
			self::admin()->getStats([]);
			$this->fail('No QueryTimeoutException');
		} catch (QueryTimeoutException $timeout) {
			$this->assertSame(['Statistics took too long and were stopped', 0, $e], [$timeout->getMessage(), $timeout->getCode(), $timeout->getPrevious()]);
		}
		$this->assertSame(['warning MailLogService_getStats query stopped by max_statement_time: ' . $e->getMessage()], $this->log->lines);
	}

	/** @return array<string, array{Closure(self): Throwable}> */
	public static function failures(): array {
		return [
			'another query error' => [fn (self $t): Throwable => $t->failWith(1064)],
			'another driver error, as text' => [fn (self $t): Throwable => $t->failWith('01969')],
			'no driver error' => [fn (self $t): Throwable => $t->failWith(null)],
			'not a query error' => [function (self $t): Throwable {
				$e = new RuntimeException('Connection lost');
				$t->answer(fn (): never => throw $e);
				return $e;
			}],
		];
	}

	/** @param Closure(self): Throwable $fail */
	#[DataProvider('failures')]
	public function testStatsFail(Closure $fail): void {
		$e = $fail($this);
		$this->assertNull(self::admin()->getStats([]));
		$this->assertSame(['error MailLogService_getStats Query error: ' . $e->getMessage()], $this->log->lines);
	}

	// a driver error without its code is not a timeout
	public function testNoErrorCode(): void {
		$e = new QueryException('default', 'select 1', [], new PDOException('gone away'));
		$this->assertFalse((new ReflectionMethod(MailLogService::class, 'isStatementTimeout'))->invoke(null, $e));
	}
}
