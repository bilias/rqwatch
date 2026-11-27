<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 MailLogService's single mails and pages, on RecordingDatabase. A mail is
 read by id (any mail, or a stored one) or qid within the user's scope, with
 its recipients (a user's own only) and its symbols, or its headers too for
 the detail page; findMailLog() reads any mail, for the APIs. A missing mail
 is an InvalidArgumentException, logged as a warning with the caller's
 place. detail() checks the id or qid first, and returns the mail with its
 symbols formatted and the relays of its headers. A page holds
 items_per_page mails, newest first: of ids resolved elsewhere (a user's
 kept only when in scope), of a search capped at max_items (a timeout is a
 QueryTimeoutException), of a day, of a day's or a month's quarantine (a
 month given as YYYY-MM, the current one by default, counting mail ever
 stored); the quarantine pages count q_items_per_page days or months.
 DEBUG_SEARCH_SQL logs the queries.
*/

namespace Tests\Unit\Services;

use App\Configuration\Config;
use App\Core\App;
use App\Core\AppContainer;
use App\Core\Database\MigrationStatus;
use App\Core\Exception\QueryTimeoutException;
use App\Models\MailLog;
use App\Services\MailLogService;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use InvalidArgumentException;
use PDO;
use PDOException;
use PhpMimeMailParser\Attachment;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\Unit\RecordingDatabase;
use Tests\Unit\UnitTestCase;

final class MailLogLookupTest extends UnitTestCase
{
	private const string ALICE = 'alice@example.org';
	private const string OWN = 'exists (select 1 from "mail_log_recipients" as "r" where "r"."mail_log_id" = "mail_logs"."id" and "r"."recipient_email" in (?))';
	private const string RCPT_ALL = 'select * from "mail_log_recipients" where "mail_log_recipients"."mail_log_id" in (7)';
	private const string RCPT_OWN = self::RCPT_ALL . ' and "recipient_email" in (?)';
	private const string SYMBOLS = 'select "mail_log_id", "symbols" from "mail_log_data" where "mail_log_data"."mail_log_id" in (7)';
	private const string DATA = 'select * from "mail_log_data" where "mail_log_data"."mail_log_id" in (7)';
	private const string HEADERS = "Received: from mx.example.net (mx.example.net [192.0.2.25])\r\n\tby mx1 with ESMTP\r\nSubject: hi";

	private RecordingDatabase $db;

	/** @var list<array<string, mixed>> the mail_logs rows a SELECT returns */
	private array $mails = [['id' => 7, 'qid' => 'Q7', 'mail_stored' => 1, 'score' => 9.5, 'has_virus' => 0]];

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->db = new RecordingDatabase($this->createStub(PDO::class));
		$this->db->capsule->bootEloquent();
		$this->db->answer = $this->rows(...);
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, $this->db->capsule, new MigrationStatus(null, $this->log), null, false));
		// without a warning: a row may lack any key
		set_error_handler(fn (int $no, string $message): never => throw new \ErrorException($message, 0, $no));
	}

	#[\Override]
	protected function tearDown(): void {
		restore_error_handler();
		Model::unsetConnectionResolver();
		RecordingDatabase::forget();
		parent::tearDown();
	}

	/**
	 * @param list<mixed> $bindings
	 * @return list<array<string, mixed>>
	 */
	private function rows(string $sql, array $bindings): array {
		return match (true) {
			str_contains($sql, 'aggregate') => [['aggregate' => count($this->mails)]],
			str_contains($sql, 'from "mail_log_recipients" where') => [['mail_log_id' => 7, 'recipient_email' => self::ALICE]],
			str_contains($sql, 'from "mail_log_data"') => [['mail_log_id' => 7, 'symbols' => '[{"name":"BAYES_SPAM","score":5.1},{"name":"R_DUMMY","score":0}]', 'headers' => self::HEADERS]],
			str_starts_with($sql, 'select "id" from "mail_logs"') => array_map(fn (array $mail): array => ['id' => $mail['id']], $this->mails),
			str_contains($sql, '"id" in (') => array_values(array_filter($this->mails, fn (array $mail): bool => in_array($mail['id'], $bindings, true))),
			default => $this->mails,
		};
	}

	private static function admin(): MailLogService {
		return new MailLogService(['is_admin' => true, 'email' => 'root@example.org']);
	}

	private static function alice(): MailLogService {
		return new MailLogService(['is_admin' => false, 'username' => 'alice', 'email' => self::ALICE]);
	}

	private static function fields(): string {
		return 'select "' . implode('", "', MailLog::SELECT_FIELDS) . '" from "mail_logs"';
	}

	/** @return list<string> the log, each caller's file and line cut */
	private function lines(): array {
		return array_map(fn (string $line): string => preg_replace('~ /\S+/app/Services/MailLogService\.php:\d+ ~', ' ', $line) ?? $line, $this->log->lines);
	}

	/** @param Closure(): mixed $call */
	private function assertNotFound(Closure $call, string $message, string $logged): void {
		try {
			$call();
			$this->fail('No InvalidArgumentException');
		} catch (InvalidArgumentException $e) {
			$this->assertSame($message, $e->getMessage());
		}
		$this->assertSame([$logged], $this->lines());
	}

	/**
	 * @param LengthAwarePaginator<int, MailLog> $page
	 * @return array{list<mixed>, int, int, int, string}
	 */
	private static function page(LengthAwarePaginator $page): array {
		return [array_values(array_map(fn (MailLog $mail): mixed => $mail->id, $page->items())), $page->total(), $page->perPage(), $page->currentPage(), $page->path() ?? ''];
	}

	public function testGetMailLog(): void {
		$this->setEnv('DEBUG_SEARCH_SQL', 'true');
		$service = self::alice();
		$log = (new ReflectionMethod($service, 'getMailLog'))->invoke($service, 7);
		$this->assertInstanceOf(MailLog::class, $log);
		$this->assertSame([7, true, true], [$log->id, $log->relationLoaded('recipients'), $log->relationLoaded('mailLogData')]);
		$this->assertSame([
			[self::fields() . ' where "id" = ? and ' . self::OWN . ' limit 1', [7, self::ALICE]],
			[self::RCPT_OWN, [self::ALICE]],
			[self::SYMBOLS, []],
		], $this->db->selects);
		$this->assertSame(['info ' . self::fields() . ' where "id" = "7" and ' . str_replace('(?)', '("alice@example.org")', self::OWN)], $this->log->lines);
	}

	public function testGetMailLogNotFound(): void {
		$this->mails = [];
		$service = self::alice();
		$this->assertNotFound(
			fn (): mixed => (new ReflectionMethod($service, 'getMailLog'))->invoke($service, 7),
			"Mail with ID '7' not found",
			"warning '[MailLogService_getMailLog] Mail with ID '7' not found'"
		);
	}

	// any mail, for the APIs; the recipients still the user's own
	public function testFindMailLog(): void {
		$this->setEnv('DEBUG_SEARCH_SQL', 'true');
		$this->assertSame(7, self::alice()->findMailLog(7)->id);
		$this->assertSame([
			['select * from "mail_logs" where "mail_logs"."id" = ? limit 1', [7]],
			[self::RCPT_OWN, [self::ALICE]],
			[self::SYMBOLS, []],
		], $this->db->selects);
		$this->assertSame(['info select * from "mail_logs"'], $this->log->lines);
	}

	public function testFindMailLogNotFound(): void {
		$this->mails = [];
		$this->assertNotFound(fn (): MailLog => self::admin()->findMailLog(7), "Mail with ID '7' not found", "warning '[MailLogService_findMailLog] Mail with ID '7' not found'");
	}

	public function testQuarantinedMailLog(): void {
		$this->setEnv('DEBUG_SEARCH_SQL', 'true');
		$this->assertSame(7, self::admin()->getQuarantinedMailLog(7)->id);
		$this->assertSame([
			[self::fields() . ' where "id" = ? and "mail_stored" = ? limit 1', [7, 1]],
			[self::RCPT_ALL, []],
			[self::SYMBOLS, []],
		], $this->db->selects);
		$this->assertSame(['info ' . self::fields() . ' where "id" = "7" and "mail_stored" = "1"'], $this->log->lines);
	}

	public function testQuarantinedMailLogNotFound(): void {
		$this->mails = [];
		$this->assertNotFound(fn (): MailLog => self::admin()->getQuarantinedMailLog(7), "Mail with ID '7' not found", "warning '[MailLogService_getQuarantinedMailLog] Mail with ID '7' not found'");
	}

	public function testDetailById(): void {
		$this->setEnv('DEBUG_SEARCH_SQL', 'true');
		$this->assertSame(7, self::alice()->detailById(7)->id);
		$this->assertSame([
			['select * from "mail_logs" where "id" = ? and ' . self::OWN . ' limit 1', [7, self::ALICE]],
			[self::RCPT_OWN, [self::ALICE]],
			[self::DATA, []],
		], $this->db->selects);
		$this->assertSame(['info select * from "mail_logs" where "id" = "7" and ' . str_replace('(?)', '("alice@example.org")', self::OWN)], $this->log->lines);
	}

	public function testDetailByIdNotFound(): void {
		$this->mails = [];
		$this->assertNotFound(fn (): MailLog => self::alice()->detailById(7), "Mail with ID '7' not found", "warning '[MailLogService_detailById] Mail with ID '7' not found'");
	}

	public function testDetailByQid(): void {
		$this->setEnv('DEBUG_SEARCH_SQL', 'true');
		$this->assertSame(7, self::admin()->detailByQid('Q7')->id);
		$this->assertSame([
			['select * from "mail_logs" where "qid" = ? limit 1', ['Q7']],
			[self::RCPT_ALL, []],
			[self::DATA, []],
		], $this->db->selects);
		$this->assertSame(['info select * from "mail_logs" where "qid" = "Q7"'], $this->log->lines);
	}

	public function testDetailByQidNotFound(): void {
		$this->mails = [];
		$this->assertNotFound(fn (): MailLog => self::alice()->detailByQid('Q7'), "Mail with QID 'Q7' not found", "warning 'MailLogService_detailByQid Mail with QID 'Q7' not found by user: 'alice''");
	}

	/** @return array<string, array{string, string|int, string}> type, value, the mail's where */
	public static function details(): array {
		return [
			'by id' => ['id', '7', '"id" = ?'],
			'by id, an int' => ['id', 7, '"id" = ?'],
			'by qid' => ['qid', 'Q7', '"qid" = ?'],
		];
	}

	#[DataProvider('details')]
	public function testDetail(string $type, string|int $value, string $where): void {
		$detail = self::admin()->detail($type, $value);
		$this->assertSame(7, $detail['log']->id);
		$this->assertSame(
			[['BAYES_SPAM:5.1', 'R_DUMMY:0'], null, [['ip' => '192.0.2.25', 'host' => 'mx.example.net', 'country' => null]]],
			[$detail['symbols'], $detail['virus_found'], $detail['received']]
		);
		$this->assertSame("select * from \"mail_logs\" where {$where} limit 1", $this->db->selects[0][0]);
	}

	/** @return array<string, array{?string}> */
	public static function noHeaders(): array {
		return [
			'none' => [null],
			'empty' => [''],
		];
	}

	#[DataProvider('noHeaders')]
	public function testDetailWithoutHeaders(?string $headers): void {
		$this->db->answer = fn (string $sql, array $bindings): array => str_contains($sql, 'from "mail_log_data"')
			? [['mail_log_id' => 7, 'symbols' => null, 'headers' => $headers]]
			: $this->rows($sql, array_values($bindings));
		$detail = self::admin()->detail('id', 7);
		$this->assertSame([[], null], [$detail['symbols'], $detail['received']]);
	}

	/** @return array<string, array{string, string, string}> type, value, error */
	public static function badDetails(): array {
		return [
			'no value' => ['id', '', 'Empty value'],
			'not an id' => ['id', 'Q7', 'ID wrong format'],
			'not a qid' => ['qid', 'Q-7', 'QID wrong format'],
			'unknown type' => ['message_id', '7', 'Unknown error'],
		];
	}

	#[DataProvider('badDetails')]
	public function testDetailRefused(string $type, string $value, string $error): void {
		$this->assertNotFound(fn (): array => self::admin()->detail($type, $value), "Error: {$error}", "error 'MailLogService_detail Error: {$error}'");
		$this->assertSame([], $this->db->selects);
	}

	public function testAttachment(): void {
		$stream = fopen('php://memory', 'r+');
		$this->assertNotFalse($stream);
		fwrite($stream, 'PDF');
		rewind($stream);
		$attachment = self::admin()->getAttachment([2 => new Attachment('a.pdf', 'application/pdf', $stream)], 2);
		$this->assertSame(['a.pdf', 'application/pdf', 'PDF'], [$attachment->getFilename(), $attachment->getFileType(), $attachment->getContent()]);
	}

	public function testNoSuchAttachment(): void {
		try {
			self::admin()->getAttachment([], 2);
			$this->fail('No exception');
		} catch (\Exception $e) {
			$this->assertSame('Attachment not found', $e->getMessage());
		}
		$this->assertSame(["error 'MailLogService_getAttachment Attachment not found'"], $this->lines());
	}

	/** @return array<string, array{int, list<int>}> page, its ids */
	public static function idPages(): array {
		return [
			'first' => [1, [9, 8]],
			'second' => [2, [7]],
			'past the last' => [3, []],
		];
	}

	/** @param list<int> $ids */
	#[DataProvider('idPages')]
	public function testPaginatedByIds(int $page, array $ids): void {
		Config::set('items_per_page', 2);
		$this->mails = [['id' => 9], ['id' => 8], ['id' => 7]];
		$this->assertSame([$ids, 3, 2, $page, '/hits'], self::page(self::admin()->getPaginatedByIds([9, 8, 7], '/hits', $page)));
		$read = $ids === [] ? [] : [self::fields() . ' where "id" in (' . implode(', ', array_fill(0, count($ids), '?')) . ') order by "id" desc', $ids];
		$this->assertSame($read, $this->db->selects[0] ?? []);
	}

	// the first page by default
	public function testPaginatedByIdsFirstPage(): void {
		Config::set('items_per_page', 2);
		$this->mails = [['id' => 9], ['id' => 8], ['id' => 7]];
		$this->assertSame([[9, 8], 3, 2, 1, '/hits'], self::page(self::admin()->getPaginatedByIds([9, 8, 7], '/hits')));
	}

	// a user's ids are those of their mails; none read without ids
	public function testPaginatedByIdsInScope(): void {
		$this->setEnv('DEBUG_SEARCH_SQL', 'true');
		$this->mails = [['id' => 9], ['id' => 7]];
		$this->assertSame([[9, 7], 2, 50, 1, '/hits'], self::page(self::alice()->getPaginatedByIds([9, 8, 7], '/hits')));
		$this->assertSame(['select "id" from "mail_logs" where "id" in (?, ?, ?) and ' . self::OWN . ' order by "id" desc', [9, 8, 7, self::ALICE]], $this->db->selects[0]);
		$this->assertSame(['info ' . self::fields() . ' where "id" in ("9", "7") order by "id" desc'], $this->log->lines);
		$this->db->selects = [];
		$this->assertSame([[], 0, 50, 1, '/hits'], self::page(self::alice()->getPaginatedByIds([], '/hits')));
		$this->assertSame([], $this->db->selects);
	}

	public function testPaginatedAll(): void {
		$this->setEnv('DEBUG_SEARCH_SQL', 'true');
		Config::set('max_items', 500);
		Config::set('items_per_page', 1);
		$this->mails = [['id' => 9], ['id' => 8]];
		$this->assertSame([[8], 2, 1, 2, '/search'], self::page(self::alice()->getPaginatedAll([['filter' => 'Subject', 'choice' => 'contains', 'value' => 'x']], '/search', 2)));
		$this->assertSame(['select "id" from "mail_logs" where "subject" LIKE ? and ' . self::OWN . ' order by "id" desc limit 500', ['%x%', self::ALICE]], $this->db->selects[0]);
		$this->assertSame('info select "id" from "mail_logs" where "subject" LIKE "%x%" and ' . str_replace('(?)', '("alice@example.org")', self::OWN) . ' order by "id" desc limit 500', $this->log->lines[0]);
	}

	public function testPaginatedAllFirstPage(): void {
		Config::set('items_per_page', 1);
		$this->mails = [['id' => 9], ['id' => 8]];
		$this->assertSame([[9], 2, 1, 1, '/search'], self::page(self::admin()->getPaginatedAll([], '/search')));
	}

	/** @return array<string, array{int|string}> */
	public static function timeouts(): array {
		return [
			'the code' => [1969],
			'its digits' => ['1969'],
		];
	}

	#[DataProvider('timeouts')]
	public function testPaginatedAllTimeout(int|string $code): void {
		$pdo = new PDOException('max_statement_time exceeded');
		$pdo->errorInfo = ['70100', $code, 'max_statement_time exceeded'];
		$e = new QueryException('default', 'select 1', [], $pdo);
		$this->db->answer = fn (): never => throw $e;
		try {
			self::admin()->getPaginatedAll([], '/search');
			$this->fail('No QueryTimeoutException');
		} catch (QueryTimeoutException $timeout) {
			$this->assertSame(['The search took too long and was stopped', 0, $e], [$timeout->getMessage(), $timeout->getCode(), $timeout->getPrevious()]);
		}
		$this->assertSame(['warning MailLogService_getPaginatedAll query stopped by max_statement_time: ' . $e->getMessage()], $this->log->lines);
	}

	public function testPaginatedDay(): void {
		$this->setEnv('DEBUG_SEARCH_SQL', 'true');
		Config::set('items_per_page', 5);
		$this->assertSame([[7], 1, 5, 2, '/day'], self::page(self::alice()->getPaginatedDay('2026-11-26', '/day', 2)));
		$where = ' where "created_day" = ? and ' . self::OWN;
		$this->assertSame([
			['select count(*) as aggregate from "mail_logs"' . $where, ['2026-11-26', self::ALICE]],
			[self::fields() . $where . ' order by "id" desc limit 5 offset 5', ['2026-11-26', self::ALICE]],
			[self::RCPT_OWN, [self::ALICE]],
			[self::SYMBOLS, []],
		], $this->db->selects);
		$this->assertSame(['info ' . self::fields() . ' where "created_day" = "2026-11-26" and ' . str_replace('(?)', '("alice@example.org")', self::OWN) . ' order by "id" desc'], $this->log->lines);
	}

	public function testPaginatedDayFirstPage(): void {
		self::admin()->getPaginatedDay('2026-11-26', '/day');
		$this->assertStringEndsWith(' order by "id" desc limit 50 offset 0', $this->db->selects[1][0] ?? '');
	}

	public function testPaginatedQuarantineDay(): void {
		$this->setEnv('DEBUG_SEARCH_SQL', 'true');
		$this->assertSame([[7], 1, 50, 1, '/q/day'], self::page(self::admin()->getPaginatedQuarantineDay('2026-11-26', '/q/day')));
		$where = ' where "created_day" = ? and "mail_stored" = ?';
		$this->assertSame([
			['select count(*) as aggregate from "mail_logs"' . $where, ['2026-11-26', 1]],
			[self::fields() . $where . ' order by "id" desc limit 50 offset 0', ['2026-11-26', 1]],
			[self::RCPT_ALL, []],
			[self::SYMBOLS, []],
		], $this->db->selects);
		$this->assertSame(['info ' . self::fields() . ' where "created_day" = "2026-11-26" and "mail_stored" = "1" order by "id" desc'], $this->log->lines);
	}

	/** @return array<string, array{int, list<int>}> page, its ids */
	public static function monthPages(): array {
		return [
			'first' => [1, [9, 8]],
			'second' => [2, [7]],
			'past the last' => [3, []],
		];
	}

	// the month's ids sorted newest first in PHP, then one page read
	/** @param list<int> $ids */
	#[DataProvider('monthPages')]
	public function testPaginatedQuarantineMonth(int $page, array $ids): void {
		$this->setEnv('DEBUG_SEARCH_SQL', 'true');
		Config::set('items_per_page', 2);
		$this->mails = [['id' => 7], ['id' => 9], ['id' => 8]];
		$this->assertSame([$ids, 3, 2, $page, '/q/month'], self::page(self::alice()->getPaginatedQuarantineMonth('2026-12', '/q/month', $page)));
		$stored = '("mail_stored" = ? or ("mail_location" is not null and "mail_location" <> ?))';
		$this->assertSame(
			['select "id" from "mail_logs" where "created_day" >= ? and "created_day" < ? and ' . $stored . ' and ' . self::OWN, ['2026-12-01', '2027-01-01', 1, '0', self::ALICE]],
			$this->db->selects[0]
		);
		$this->assertSame($ids === [] ? null : [self::fields() . ' where "id" in (' . implode(', ', array_fill(0, count($ids), '?')) . ') order by "id" desc', $ids], $this->db->selects[1] ?? null);
		$this->assertSame(['info select "id" from "mail_logs" where "created_day" >= "2026-12-01" and "created_day" < "2027-01-01" and ("mail_stored" = "1" or ("mail_location" is not null and "mail_location" <> "0")) and '
			. str_replace('(?)', '("alice@example.org")', self::OWN)], $this->log->lines);
	}

	public function testPaginatedQuarantineMonthFirstPage(): void {
		Config::set('items_per_page', 2);
		$this->mails = [['id' => 7], ['id' => 9], ['id' => 8]];
		$this->assertSame([[9, 8], 3, 2, 1, '/q/month'], self::page(self::admin()->getPaginatedQuarantineMonth('2026-12', '/q/month')));
	}

	public function testPaginatedQuarantineThisMonth(): void {
		$start = new \DateTimeImmutable('first day of this month');
		self::admin()->getPaginatedQuarantineMonth(null, '/q/month');
		$this->assertSame([$start->format('Y-m-d'), $start->modify('+1 month')->format('Y-m-d')], array_slice($this->db->selects[0][1], 0, 2));
	}

	/** @return array<string, array{string}> */
	public static function badMonths(): array {
		return [
			'not a month' => ['November'],
			'month 13' => ['2026-13'],
			'one digit' => ['2026-1'],
			'a day' => ['2026-11-01'],
		];
	}

	#[DataProvider('badMonths')]
	public function testBadMonth(string $month): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage("Invalid month '{$month}'");
		self::admin()->getPaginatedQuarantineMonth($month, '/q/month');
	}

	public function testPaginatedQuarantine(): void {
		$this->setEnv('DEBUG_SEARCH_SQL', 'true');
		$this->setEnv('QUARANTINE_DAYS', '30');
		Config::set('q_items_per_page', 7);
		$this->mails = [['day' => '2026-11-26', 'cnt' => 3]];
		$days = self::alice()->getPaginatedQuarantine('/q', 2);
		$this->assertSame([1, 7, 2, '/q'], [$days->total(), $days->perPage(), $days->currentPage(), $days->path()]);
		$query = 'select created_day as day, COUNT(*) as cnt from "mail_logs" where "mail_stored" = ? and ' . self::OWN . ' group by day';
		$this->assertSame([
			['select count(*) as aggregate from (' . $query . ') as "aggregate_table"', [1, self::ALICE]],
			[$query . ' order by "day" desc limit 7 offset 7', [1, self::ALICE]],
		], $this->db->selects);
		$this->assertSame(['info ' . str_replace(['= ?', '(?)'], ['= "1"', '("alice@example.org")'], $query) . ' order by "day" desc limit 30'], $this->log->lines);
	}

	public function testPaginatedQuarantineDefaultPage(): void {
		$this->setEnv('QUARANTINE_DAYS', '30');
		$this->assertSame([31, 1], [self::admin()->getPaginatedQuarantine('/q')->perPage(), self::admin()->getPaginatedQuarantine('/q')->currentPage()]);
	}

	public function testPaginatedQuarantinePerMonth(): void {
		$this->setEnv('DEBUG_SEARCH_SQL', 'true');
		Config::set('q_items_per_page', 2);
		$this->mails = [['month' => '2026-11', 'cnt' => 3], ['month' => '2026-10', 'cnt' => 2], ['month' => '2026-09', 'cnt' => 1]];
		$months = self::alice()->getPaginatedQuarantinePerMonth('/q/months', 2);
		$this->assertSame(
			[[['month' => '2026-09', 'cnt' => 1]], 3, 2, 2, '/q/months'],
			[array_map(fn (MailLog $m): array => $m->getAttributes(), $months->items()), $months->total(), $months->perPage(), $months->currentPage(), $months->path()]
		);
		$query = "select DATE_FORMAT(created_day, '%Y-%m') AS month, COUNT(*) as cnt from \"mail_logs\" where (\"mail_stored\" = ? or (\"mail_location\" is not null and \"mail_location\" <> ?)) and " . self::OWN . ' group by month order by "month" desc';
		$this->assertSame([[$query, [1, '0', self::ALICE]]], $this->db->selects);
		$this->assertSame(['info ' . str_replace(['"mail_stored" = ?', '<> ?', '(?)'], ['"mail_stored" = "1"', '<> "0"', '("alice@example.org")'], $query)], $this->log->lines);
	}

	public function testPaginatedQuarantinePerMonthFirstPage(): void {
		$this->mails = [['month' => '2026-11', 'cnt' => 3]];
		$months = self::admin()->getPaginatedQuarantinePerMonth('/q/months');
		$this->assertSame([1, 31, 1], [count($months->items()), $months->perPage(), $months->currentPage()]);
	}
}
