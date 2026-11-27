<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The search filters of MailLogService, as SQL built on RecordingDatabase.
 getQueryByFilters() maps each filter's label and choice to a column and an
 operator, drops a filter with an unknown label or choice (and the fields
 a non-admin may not search, unless in CLI), wraps a contains value in %,
 and hands the pseudo-columns to their own filters: recipients (lower case,
 trimmed; a non-admin only among their own addresses), headers and symbols
 (in the table's collation, = as contains), viruses (by name or, when the
 name is empty, the antivirus symbol; = exact), local symbols (their ids
 looked up first; none found matches no mail, or every mail when negated),
 notification skipped, fuzzy hit and map hit (= and <> against 0/1 as
 NULL or EXISTS, any other operator compares the 0/1 in raw SQL). A
 negated choice is NOT EXISTS with the positive operator. The Date
 filters are repeated on the virus and symbol tables' own day; a map hit
 = 1 with a Date range probes per mail. Only known operators reach raw
 SQL. Mail ever stored is ever_stored once migrated, else stored or with
 a location.
*/

namespace Tests\Unit\Services;

use App\Configuration\Config;
use App\Core\App;
use App\Core\AppContainer;
use App\Core\Database\MigrationStatus;
use App\Inventory\Migrations;
use App\Models\MailLog;
use App\Services\MailLogService;
use App\Services\MapService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use ReflectionMethod;
use ReflectionProperty;
use Tests\Unit\RecordingDatabase;
use Tests\Unit\UnitTestCase;

final class MailLogFilterTest extends UnitTestCase
{
	private const string ALL = 'select * from "mail_logs"';
	private const string RCPT = 'exists (select * from "mail_log_recipients" where "mail_logs"."id" = "mail_log_recipients"."mail_log_id" and "recipient_email" ';
	private const string DATA = 'exists (select * from "mail_log_data" where "mail_logs"."id" = "mail_log_data"."mail_log_id" and `mail_log_data`.`';
	private const string VIRUS = 'exists (select 1 from "mail_log_viruses" as "vf" where "vf"."mail_log_id" = "mail_logs"."id"';
	private const string SYMBOL = 'exists (select 1 from "mail_log_symbol_hits" as "sh" where "sh"."mail_log_id" = "mail_logs"."id" and "sh"."symbol_id" in (4, 9)';
	private const string FUZZY = 'select 1 from "mail_log_fuzzy_hits" as "fh" where "fh"."mail_log_id" = "mail_logs"."id"';
	private const string HITS = 'select 1 from "mail_log_symbol_hits" as "sh" where "sh"."mail_log_id" = "mail_logs"."id" and (`sh`.`entry_model` <> 0';
	private const string IDS = ' or "sh"."symbol_id" in (4, 9))';
	private const string COLLATE = ' COLLATE utf8mb4_general_ci';
	private const array SINCE = ['filter' => 'Date', 'choice' => 'is greater than or equal', 'value' => '2026-11-01'];

	private RecordingDatabase $db;

	private MigrationStatus $status;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->db = new RecordingDatabase($this->createStub(PDO::class));
		$this->db->capsule->bootEloquent();
		// symbol ids for every name looked up
		$this->db->answer = fn (string $sql): array => str_contains($sql, '"symbol_names"') ? [['id' => 4], ['id' => 9]] : [];
		$this->status = new MigrationStatus(null, $this->log);
		foreach ([Migrations::MAIL_LOG_VIRUSES, Migrations::MAIL_LOG_FUZZY, Migrations::MAIL_LOG_FUZZY_HITS_MAILS, Migrations::MAP_HITS] as $migration) {
			$this->status->setMigrationState($migration, Migrations::STATUS_COMPLETED);
		}
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, $this->db->capsule, $this->status, null, false));
		Config::set('fuzzy_learn_enable', true);
		Config::set('map_symbols', ['whitelist' => ['WL_FROM', 'WL_IP']]);
		(new ReflectionProperty(MapService::class, 'mappedSymbolSet'))->setValue(null, null);
		// without a warning: a filter may lack its column or operator
		set_error_handler(fn (int $no, string $message): never => throw new \ErrorException($message, 0, $no));
	}

	#[\Override]
	protected function tearDown(): void {
		restore_error_handler();
		(new ReflectionProperty(MapService::class, 'mappedSymbolSet'))->setValue(null, null);
		Model::unsetConnectionResolver();
		RecordingDatabase::forget();
		parent::tearDown();
	}

	private static function admin(): MailLogService {
		return new MailLogService(['is_admin' => true, 'email' => 'root@example.org']);
	}

	/**
	 * @param Builder<MailLog> $query
	 * @return array{string, list<mixed>}
	 */
	private static function sql(Builder $query): array {
		return [$query->toSql(), $query->getBindings()];
	}

	/**
	 * @param list<array{filter: string, choice: string, value: string}> $filters
	 * @return array{string, list<mixed>}
	 */
	private static function filtered(MailLogService $service, array $filters): array {
		return self::sql($service->getQueryByFilters(MailLog::query(), $filters));
	}

	/** @return array{string, list<mixed>} a private filter's SQL on a fresh query */
	private static function apply(MailLogService $service, string $method, mixed ...$arguments): array {
		$query = MailLog::query();
		(new ReflectionMethod($service, $method))->invoke($service, $query, ...$arguments);
		return self::sql($query);
	}

	/** @return list<string> the symbol_names lookups */
	private function symbolLookups(): array {
		return array_values(array_map(
			fn (array $select): string => $select[0] . ' ' . json_encode($select[1], JSON_THROW_ON_ERROR),
			array_filter($this->db->selects, fn (array $select): bool => str_contains($select[0], '"symbol_names"'))
		));
	}

	public function testNoFilters(): void {
		$query = MailLog::query();
		$this->assertSame($query, self::admin()->getQueryByFilters($query, []));
		$this->assertSame([self::ALL, []], self::sql($query));
	}

	/** @return array<string, array{string, string, string, string, list<string>}> label, choice, value, where, bindings */
	public static function columns(): array {
		return [
			'contains' => ['Subject', 'contains', 'invoice', '"subject" LIKE ?', ['%invoice%']],
			'does not contain' => ['Subject', 'does not contain', 'invoice', '"subject" NOT LIKE ?', ['%invoice%']],
			'is equal to' => ['Subject', 'is equal to', 'invoice', '"subject" = ?', ['invoice']],
			'is not equal to' => ['Action', 'is not equal to', 'reject', '"action" <> ?', ['reject']],
			'is greater than' => ['Score', 'is greater than', '5', '"score" > ?', ['5']],
			'is less than or equal' => ['Size', 'is less than or equal', '1000', '"size" <= ?', ['1000']],
			'matches REGEXP' => ['MAIL From', 'matches REGEXP', '^a', '"mail_from" REGEXP ?', ['^a']],
			'the date' => ['Date', 'is equal to', '2026-11-26', '"created_day" = ?', ['2026-11-26']],
		];
	}

	/** @param list<string> $bindings */
	#[DataProvider('columns')]
	public function testColumn(string $label, string $choice, string $value, string $where, array $bindings): void {
		$this->assertSame([self::ALL . " where {$where}", $bindings], self::filtered(self::admin(), [['filter' => $label, 'choice' => $choice, 'value' => $value]]));
	}

	/** @return array<string, array{string, string, string, string, list<string>}> label, choice, value, its where, its bindings */
	public static function pseudoColumns(): array {
		return [
			'RCPT To' => ['RCPT To', 'is equal to', 'Bob@Example.org', self::RCPT . '= ?)', ['bob@example.org']],
			'Headers' => ['Headers', 'contains', 'X-Spam', self::DATA . 'headers`' . self::COLLATE . ' LIKE ?)', ['%X-Spam%']],
			'Rspamd symbols' => ['Rspamd symbols', 'is equal to', 'BAYES', self::DATA . 'symbols`' . self::COLLATE . ' LIKE ?)', ['%BAYES%']],
			'Virus' => ['Virus', 'is equal to', 'Eicar', self::VIRUS . ' and (`vf`.`name` = ? or ("vf"."name" = ? and `vf`.`av` = ?)))', ['Eicar', '', 'Eicar']],
			'Local symbol' => ['Local symbol', 'is equal to', 'WL_FROM', self::SYMBOL . ')', []],
			'Notification skipped, contains' => ['Notification skipped (0/1)', 'contains', '1', '(`notified` IS NULL) LIKE ?', ['%1%']],
			'Fuzzy hit, does not contain' => ['Fuzzy hit (0/1)', 'does not contain', '1', '(EXISTS (' . self::FUZZY . ')) NOT LIKE ?', ['%1%']],
			'Map hit' => ['Map hit (0/1)', 'is equal to', '1', 'exists (' . self::HITS . self::IDS . ')', []],
		];
	}

	/**
	 * each to its own filter, not to a mail_logs column, and the filter after it still applied
	 * @param list<string> $bindings
	 */
	#[DataProvider('pseudoColumns')]
	public function testPseudoColumn(string $label, string $choice, string $value, string $where, array $bindings): void {
		$this->assertSame(
			[self::ALL . " where {$where} and \"subject\" LIKE ?", [...$bindings, '%invoice%']],
			self::filtered(self::admin(), [
				['filter' => $label, 'choice' => $choice, 'value' => $value],
				['filter' => 'Subject', 'choice' => 'contains', 'value' => 'invoice'],
			])
		);
	}

	/** @return array<string, array{array{is_admin?: bool, email?: string}, array{filter: string, choice: string, value: string}}> */
	public static function dropped(): array {
		return [
			'an unknown label' => [['is_admin' => true], ['filter' => 'Body', 'choice' => 'contains', 'value' => 'x']],
			'an unknown choice' => [['is_admin' => true], ['filter' => 'Subject', 'choice' => 'sounds like', 'value' => 'x']],
			'an admin field, for a user' => [['is_admin' => false, 'email' => 'alice@example.org'], ['filter' => 'Server', 'choice' => 'is equal to', 'value' => 'mx1']],
		];
	}

	/**
	 * @param array{is_admin?: bool, email?: string} $context
	 * @param array{filter: string, choice: string, value: string} $filter
	 */
	#[DataProvider('dropped')]
	public function testDropped(array $context, array $filter): void {
		$this->assertSame([self::ALL . ' where "score" > ?', ['5']], self::filtered(new MailLogService($context), [
			$filter,
			['filter' => 'Score', 'choice' => 'is greater than', 'value' => '5'],
		]));
	}

	// only the Date filters with a known choice reach the virus table; the others stay on mail_logs
	public function testDatesForTheChildTables(): void {
		$this->assertSame(
			[
				self::ALL . ' where "created_day" >= ? and ' . self::VIRUS . ' and "vf"."created_day" >= ? and "vf"."created_day" LIKE ? and "vf"."created_day" NOT LIKE ?'
					. ' and (`vf`.`name` = ? or ("vf"."name" = ? and `vf`.`av` = ?)))'
					. ' and "subject" = ? and "created_day" LIKE ? and "created_day" NOT LIKE ?',
				['2026-11-01', '2026-11-01', '%2026-11%', '%11-3%', 'Eicar', '', 'Eicar', 'invoice', '%2026-11%', '%11-3%'],
			],
			self::filtered(self::admin(), [
				self::SINCE,
				['filter' => 'Date', 'choice' => 'sounds like', 'value' => '2026'],
				['filter' => 'Virus', 'choice' => 'is equal to', 'value' => 'Eicar'],
				['filter' => 'Subject', 'choice' => 'is equal to', 'value' => 'invoice'],
				['filter' => 'Date', 'choice' => 'contains', 'value' => '2026-11'],
				['filter' => 'Date', 'choice' => 'does not contain', 'value' => '11-3'],
			])
		);
	}

	// a cron searches as an admin: the admin fields, every recipient
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testCliSearchesAsAdmin(): void {
		define('CLI_MODE', true);
		$this->assertSame(
			[self::ALL . ' where "server" = ? and ' . self::RCPT . '= ?)', ['mx1', 'bob@example.org']],
			self::filtered(new MailLogService(['is_admin' => false, 'email' => 'alice@example.org']), [
				['filter' => 'Server', 'choice' => 'is equal to', 'value' => 'mx1'],
				['filter' => 'RCPT To', 'choice' => 'is equal to', 'value' => 'bob@example.org'],
			])
		);
	}

	/** @return array<string, array{string, bool, string, string}> choice, negated, operator, value */
	public static function recipientChoices(): array {
		return [
			'=' => ['=', false, '=', 'bob@example.org'],
			'<>' => ['<>', true, '=', 'bob@example.org'],
			'!=' => ['!=', true, '=', 'bob@example.org'],
			'LIKE' => ['LIKE', false, 'LIKE', '%bob@example.org%'],
			'NOT LIKE' => ['NOT LIKE', true, 'LIKE', '%bob@example.org%'],
			'REGEXP' => ['REGEXP', false, 'REGEXP', 'bob@example.org'],
			'NOT REGEXP' => ['NOT REGEXP', true, 'REGEXP', 'bob@example.org'],
			'>' => ['>', false, '>', 'bob@example.org'],
		];
	}

	#[DataProvider('recipientChoices')]
	public function testRecipient(string $choice, bool $negated, string $op, string $value): void {
		$this->assertSame(
			[self::ALL . ' where ' . ($negated ? 'not ' : '') . self::RCPT . "{$op} ?)", [$value]],
			self::apply(self::admin(), 'filterByRecipient', $choice, " Bob@Example.org\t")
		);
	}

	/** @return array<string, array{array{is_admin?: bool, email?: string, user_aliases?: array<int, string>}|null, string, list<string>}> context, the scope added, its bindings */
	public static function recipientScopes(): array {
		return [
			'an admin' => [['is_admin' => true, 'email' => 'root@example.org'], '', []],
			'no user context (mail APIs)' => [null, '', []],
			'a user' => [['is_admin' => false, 'email' => ' Alice@Example.org'], ' and "recipient_email" in (?)', ['alice@example.org']],
			'a user with aliases' => [['is_admin' => false, 'email' => 'alice@example.org', 'user_aliases' => ['Al@Example.org']], ' and "recipient_email" in (?, ?)', ['alice@example.org', 'al@example.org']],
		];
	}

	/**
	 * @param array{is_admin?: bool, email?: string, user_aliases?: array<int, string>}|null $context
	 * @param list<string> $bindings
	 */
	#[DataProvider('recipientScopes')]
	public function testRecipientScope(?array $context, string $scope, array $bindings): void {
		$this->assertSame(
			[self::ALL . ' where ' . self::RCPT . "= ?{$scope})", ['bob@example.org', ...$bindings]],
			self::apply(new MailLogService($context), 'filterByRecipient', '=', 'bob@example.org')
		);
	}

	/** @return array<string, array{string, bool, string, string}> choice, negated, operator, value */
	public static function dataChoices(): array {
		return [
			'=' => ['=', false, 'LIKE', '%X-Spam: yes%'],
			'<>' => ['<>', true, 'LIKE', '%X-Spam: yes%'],
			'!=' => ['!=', true, 'LIKE', '%X-Spam: yes%'],
			'LIKE' => ['LIKE', false, 'LIKE', '%X-Spam: yes%'],
			'NOT LIKE' => ['NOT LIKE', true, 'LIKE', '%X-Spam: yes%'],
			'REGEXP' => ['REGEXP', false, 'REGEXP', 'X-Spam: yes'],
			'NOT REGEXP' => ['NOT REGEXP', true, 'REGEXP', 'X-Spam: yes'],
			'>=' => ['>=', false, '>=', 'X-Spam: yes'],
		];
	}

	#[DataProvider('dataChoices')]
	public function testHeaders(string $choice, bool $negated, string $op, string $value): void {
		$this->assertSame(
			[self::ALL . ' where ' . ($negated ? 'not ' : '') . self::DATA . 'headers`' . self::COLLATE . " {$op} ?)", [$value]],
			self::apply(self::admin(), 'filterByMailLogData', 'headers', $choice, 'X-Spam: yes')
		);
	}

	/** @return array<string, array{string, bool, string, string, string}> choice, negated, collation, operator, value */
	public static function virusChoices(): array {
		return [
			'= (exact)' => ['=', false, '', '=', 'Eicar'],
			'<>' => ['<>', true, '', '=', 'Eicar'],
			'!=' => ['!=', true, '', '=', 'Eicar'],
			'LIKE' => ['LIKE', false, self::COLLATE, 'LIKE', '%Eicar%'],
			'NOT LIKE' => ['NOT LIKE', true, self::COLLATE, 'LIKE', '%Eicar%'],
			'REGEXP' => ['REGEXP', false, self::COLLATE, 'REGEXP', 'Eicar'],
			'NOT REGEXP' => ['NOT REGEXP', true, self::COLLATE, 'REGEXP', 'Eicar'],
			'<' => ['<', false, self::COLLATE, '<', 'Eicar'],
		];
	}

	#[DataProvider('virusChoices')]
	public function testVirus(string $choice, bool $negated, string $collate, string $op, string $value): void {
		$this->assertSame(
			[
				self::ALL . ' where ' . ($negated ? 'not ' : '') . self::VIRUS . ' and "vf"."created_day" > ?'
					. " and (`vf`.`name`{$collate} {$op} ? or (\"vf\".\"name\" = ? and `vf`.`av`{$collate} {$op} ?)))",
				['2026-11-01', $value, '', $value],
			],
			self::apply(self::admin(), 'filterByVirus', $choice, 'Eicar', [['>', '2026-11-01']])
		);
	}

	/** @return array<string, array{string, bool, string, string}> choice, negated, the name lookup's where, value */
	public static function symbolChoices(): array {
		return [
			'= (exact)' => ['=', false, '`name` = ?', 'WL_FROM'],
			'<>' => ['<>', true, '`name` = ?', 'WL_FROM'],
			'!=' => ['!=', true, '`name` = ?', 'WL_FROM'],
			'LIKE' => ['LIKE', false, '`name`' . self::COLLATE . ' LIKE ?', '%WL_FROM%'],
			'NOT LIKE' => ['NOT LIKE', true, '`name`' . self::COLLATE . ' LIKE ?', '%WL_FROM%'],
			'REGEXP' => ['REGEXP', false, '`name`' . self::COLLATE . ' REGEXP ?', 'WL_FROM'],
			'NOT REGEXP' => ['NOT REGEXP', true, '`name`' . self::COLLATE . ' REGEXP ?', 'WL_FROM'],
			'<=' => ['<=', false, '`name`' . self::COLLATE . ' <= ?', 'WL_FROM'],
		];
	}

	#[DataProvider('symbolChoices')]
	public function testLocalSymbol(string $choice, bool $negated, string $lookup, string $value): void {
		$this->assertSame(
			[self::ALL . ' where ' . ($negated ? 'not ' : '') . self::SYMBOL . ' and "sh"."created_day" LIKE ?)', ['%2026-11%']],
			self::apply(self::admin(), 'filterByLocalSymbol', $choice, 'WL_FROM', [['LIKE', '2026-11']])
		);
		$this->assertSame(["select \"id\" from \"symbol_names\" where {$lookup} " . json_encode([$value], JSON_THROW_ON_ERROR)], $this->symbolLookups());
	}

	/** @return array<string, array{string, string}> choice, where */
	public static function unknownSymbol(): array {
		return [
			'has it: no mail' => ['=', ' where 0 = 1'],
			'lacks it: every mail' => ['<>', ''],
		];
	}

	#[DataProvider('unknownSymbol')]
	public function testUnknownLocalSymbol(string $choice, string $where): void {
		$this->db->answer = null;
		$this->assertSame([self::ALL . $where, []], self::apply(self::admin(), 'filterByLocalSymbol', $choice, 'NO_SUCH', [['>=', '2026-11-01']]));
	}

	/** @return array<string, array{string, string, string, list<string>}> choice, value, where, bindings */
	public static function skipped(): array {
		return [
			'= 1' => ['=', '1', '"notified" is null', []],
			'= 0' => ['=', '0', '"notified" is not null', []],
			'<> 1' => ['<>', '1', '"notified" is not null', []],
			'<> 0' => ['<>', '0', '"notified" is null', []],
			'= 01, not 0/1' => ['=', '01', '(`notified` IS NULL) = ?', ['01']],
			'<> 00, not 0/1' => ['<>', '00', '(`notified` IS NULL) <> ?', ['00']],
			'> 0' => ['>', '0', '(`notified` IS NULL) > ?', ['0']],
		];
	}

	/** @param list<string> $bindings */
	#[DataProvider('skipped')]
	public function testNotificationSkipped(string $choice, string $value, string $where, array $bindings): void {
		$this->assertSame([self::ALL . " where {$where}", $bindings], self::apply(self::admin(), 'filterByNotificationSkipped', $choice, $value));
	}

	/** @return array<string, array{string, string, string, list<string>}> choice, value, where, bindings */
	public static function fuzzy(): array {
		return [
			'= 1' => ['=', '1', 'exists (' . self::FUZZY . ')', []],
			'= 0' => ['=', '0', 'not exists (' . self::FUZZY . ')', []],
			'<> 1' => ['<>', '1', 'not exists (' . self::FUZZY . ')', []],
			'<> 0' => ['<>', '0', 'exists (' . self::FUZZY . ')', []],
			'= 01, not 0/1' => ['=', '01', '(EXISTS (' . self::FUZZY . ')) = ?', ['01']],
			'<> 00, not 0/1' => ['<>', '00', '(EXISTS (' . self::FUZZY . ')) <> ?', ['00']],
			'>= 1' => ['>=', '1', '(EXISTS (' . self::FUZZY . ')) >= ?', ['1']],
		];
	}

	/** @param list<string> $bindings */
	#[DataProvider('fuzzy')]
	public function testFuzzyHit(string $choice, string $value, string $where, array $bindings): void {
		$this->assertSame([self::ALL . " where {$where}", $bindings], self::apply(self::admin(), 'filterByFuzzyHit', $choice, $value));
	}

	/** @return array<string, array{string, string, string, list<string>}> choice, value, where, bindings */
	public static function mapHit(): array {
		$hits = self::HITS . self::IDS;
		return [
			'= 1' => ['=', '1', "exists ({$hits})", []],
			'= 0' => ['=', '0', "not exists ({$hits})", []],
			'<> 1' => ['<>', '1', "not exists ({$hits})", []],
			'<> 0' => ['<>', '0', "exists ({$hits})", []],
			'= 01, not 0/1' => ['=', '01', "(EXISTS ({$hits})) = ?", ['01']],
			'<> 00, not 0/1' => ['<>', '00', "(EXISTS ({$hits})) <> ?", ['00']],
			'< 1' => ['<', '1', "(EXISTS ({$hits})) < ?", ['1']],
		];
	}

	/** @param list<string> $bindings */
	#[DataProvider('mapHit')]
	public function testMapHit(string $choice, string $value, string $where, array $bindings): void {
		$this->assertSame([self::ALL . " where {$where}", $bindings], self::apply(self::admin(), 'filterByMapHit', $choice, $value, []));
		$this->assertSame(['select "id" from "symbol_names" where "name" in (?, ?) ["WL_FROM","WL_IP"]'], $this->symbolLookups());
	}

	/** @return array<string, array{string, bool}> the Date filter's choice, a range */
	public static function mapHitDays(): array {
		return [
			'=' => ['=', true],
			'<' => ['<', true],
			'<=' => ['<=', true],
			'>' => ['>', true],
			'>=' => ['>=', true],
			'<>' => ['<>', false],
			'LIKE' => ['LIKE', false],
			'REGEXP' => ['REGEXP', false],
		];
	}

	// with a Date range, a mail with a map hit is probed per mail; without one, EXISTS
	#[DataProvider('mapHitDays')]
	public function testMapHitWithDates(string $choice, bool $ranged): void {
		$where = $ranged ? '(' . self::HITS . self::IDS . ' limit 1) IS NOT NULL' : 'exists (' . self::HITS . self::IDS . ')';
		$this->assertSame([self::ALL . " where {$where}", []], self::apply(self::admin(), 'filterByMapHit', '=', '1', [['LIKE', '2026'], [$choice, '2026-11-01']]));
	}

	/** @return array<string, array{array<string, list<string>>, ?list<array{id: int}>, list<string>}> map_symbols, the ids found, lookups */
	public static function mapHitSymbols(): array {
		return [
			'no mapped symbols: no lookup' => [[], [['id' => 4]], []],
			'mapped symbols without ids' => [['whitelist' => ['WL_FROM']], null, ['select "id" from "symbol_names" where "name" in (?) ["WL_FROM"]']],
		];
	}

	/**
	 * only hits credited to an entry
	 * @param array<string, list<string>> $symbols
	 * @param ?list<array{id: int}> $ids
	 * @param list<string> $lookups
	 */
	#[DataProvider('mapHitSymbols')]
	public function testMapHitWithoutSymbolIds(array $symbols, ?array $ids, array $lookups): void {
		Config::set('map_symbols', $symbols);
		$this->db->answer = $ids === null ? null : fn (): array => $ids;
		$this->assertSame([self::ALL . ' where exists (' . self::HITS . '))', []], self::apply(self::admin(), 'filterByMapHit', '=', '1', []));
		$this->assertSame($lookups, $this->symbolLookups());
	}

	/** @return array<string, array{string}> */
	public static function operators(): array {
		return [
			'=' => ['='],
			'<>' => ['<>'],
			'>' => ['>'],
			'>=' => ['>='],
			'<' => ['<'],
			'<=' => ['<='],
			'LIKE' => ['LIKE'],
			'NOT LIKE' => ['NOT LIKE'],
			'REGEXP' => ['REGEXP'],
			'NOT REGEXP' => ['NOT REGEXP'],
		];
	}

	#[DataProvider('operators')]
	public function testRawOperator(string $op): void {
		$service = self::admin();
		$this->assertSame($op, (new ReflectionMethod($service, 'rawOperator'))->invoke($service, $op));
	}

	public function testRawOperatorRefusesOthers(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Unknown filter operator');
		self::apply(self::admin(), 'filterByFuzzyHit', '= 1 or 1 =', '1');
	}

	/** @return array<string, array{?string, string, list<mixed>}> EVER_STORED's state, where, bindings */
	public static function everStored(): array {
		return [
			'migrated' => [Migrations::STATUS_COMPLETED, '"ever_stored" = ?', [1]],
			'running' => [Migrations::STATUS_RUNNING, '("mail_stored" = ? or ("mail_location" is not null and "mail_location" <> ?))', [1, '0']],
			'never run' => [null, '("mail_stored" = ? or ("mail_location" is not null and "mail_location" <> ?))', [1, '0']],
		];
	}

	/** @param list<mixed> $bindings */
	#[DataProvider('everStored')]
	public function testEverStored(?string $state, string $where, array $bindings): void {
		$this->status->setMigrationState(Migrations::EVER_STORED, $state);
		$this->assertSame([self::ALL . " where {$where}", $bindings], self::apply(self::admin(), 'whereEverStored'));
	}
}
