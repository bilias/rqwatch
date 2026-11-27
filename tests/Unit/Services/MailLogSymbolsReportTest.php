<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 MailLogService's symbols and virus reports, on RecordingDatabase. The
 symbols report reads the newest max_items mails of the search (of the
 last 7 days, from the database's date, when there are no filters), every
 symbol from mail_log_data.symbols or only the recorded ones from
 mail_log_symbol_hits, and gives per symbol its mails, mails per action
 and average score: without informational symbols only those that scored
 in some mail, ordered by the largest average either way or by mails, then
 by name, the first $limit; a symbol or action without a name is ''. Its
 actions are rspamd's in their order, then the others found, sorted. The
 virus report counts the mails of each antivirus name (the symbol when the
 name is empty) in the last 31 days per window, the top $limit names of
 each window by mails then name, and the scan failures (_FAIL symbols) by
 the month's mails; its mails are the ids of one name, window and kind,
 newest first, max_items of them. Both apply the search (but its Date
 filters) and the user's scope, unless an admin has no filters.
*/

namespace Tests\Unit\Services;

use App\Configuration\Config;
use App\Core\App;
use App\Core\AppContainer;
use App\Core\Database\MigrationStatus;
use App\Inventory\Migrations;
use App\Services\MailLogService;
use Illuminate\Database\Eloquent\Model;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Unit\RecordingDatabase;
use Tests\Unit\UnitTestCase;

final class MailLogSymbolsReportTest extends UnitTestCase
{
	private const string OWN = 'exists (select 1 from `mail_log_recipients` as `r` where `r`.`mail_log_id` = `mail_logs`.`id` and `r`.`recipient_email` in (?))';
	private const string CELLS = 'SELECT {name} AS name, ml.action, COUNT(*) AS mails, SUM(COALESCE(jt.score, 0) <> 0) AS scored, SUM(COALESCE(jt.score, 0)) AS score FROM ';
	private const string WINDOWS = 'COUNT(DISTINCT IF(v.created_day = CURDATE(), v.mail_log_id, NULL)) AS today, COUNT(DISTINCT IF(v.created_day = CURDATE() - INTERVAL 1 DAY, v.mail_log_id, NULL)) AS yesterday, '
		. 'COUNT(DISTINCT IF(v.created_day >= CURDATE() - INTERVAL 6 DAY, v.mail_log_id, NULL)) AS week, COUNT(DISTINCT IF(v.created_day >= CURDATE() - INTERVAL 30 DAY, v.mail_log_id, NULL)) AS month';

	private RecordingDatabase $db;

	/** @var list<array<string, mixed>> the rows of the report's main query */
	private array $cells = [];

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->db = new RecordingDatabase($this->createStub(PDO::class));
		$this->db->capsule->bootEloquent();
		$this->db->answer = fn (string $sql): array => match (true) {
			str_starts_with($sql, 'SELECT CURDATE()') => [['d' => '2026-11-20']],
			str_starts_with($sql, 'SELECT COUNT(*) AS n') => [['n' => 12]],
			default => $this->cells,
		};
		$status = new MigrationStatus(null, $this->log);
		$status->setMigrationState(Migrations::MAIL_LOG_VIRUSES, Migrations::STATUS_COMPLETED);
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, $this->db->capsule, $status, null, false));
		Config::set('max_items', 500);
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

	private static function admin(): MailLogService {
		return new MailLogService(['is_admin' => true, 'email' => 'root@example.org']);
	}

	private static function alice(): MailLogService {
		return new MailLogService(['is_admin' => false, 'email' => 'alice@example.org']);
	}

	/** @return list<array<string, mixed>> */
	private static function symbolCells(): array {
		$cell = fn (?string $name, ?string $action, int $mails, int $scored, float $score): array => compact('name', 'action', 'mails', 'scored', 'score');
		return [
			$cell('ABS_BIG', 'greylist', 1, 1, -9.0),
			$cell('BAYES_SPAM', 'reject', 3, 3, 15.3),
			$cell('R_DKIM_NA', 'reject', 4, 0, 0.0),
			$cell('BAYES_SPAM', 'add header', 1, 1, 5.1),
			$cell('MAILLIST', 'custom', 2, 2, -0.4),
			$cell(null, null, 1, 1, 2.0),
			$cell('AAA_NEG', 'add header', 1, 1, -2.0),
			// scored in some mails, then not
			$cell('MIXED', 'reject', 2, 2, 3.0),
			$cell('MIXED', 'add header', 1, 0, 0.0),
		];
	}

	/**
	 * @param array<string, int> $actions
	 * @return array{name: string, mails: int, actions: array<string, int>, avg_score: float}
	 */
	private static function row(string $name, int $mails, array $actions, float $avg): array {
		return ['name' => $name, 'mails' => $mails, 'actions' => $actions, 'avg_score' => $avg];
	}

	/** @return array<string, array{bool, string, int, list<string>}> informational, sort, limit, the names in order */
	public static function symbolOrders(): array {
		return [
			'by score, informational' => [true, 'score', 10, ['ABS_BIG', 'BAYES_SPAM', '', 'AAA_NEG', 'MIXED', 'MAILLIST', 'R_DKIM_NA']],
			'by score' => [false, 'score', 10, ['ABS_BIG', 'BAYES_SPAM', '', 'AAA_NEG', 'MIXED', 'MAILLIST']],
			'by mails, informational' => [true, 'mails', 10, ['BAYES_SPAM', 'R_DKIM_NA', 'MIXED', 'MAILLIST', '', 'AAA_NEG', 'ABS_BIG']],
			'by anything else: mails' => [true, 'name', 10, ['BAYES_SPAM', 'R_DKIM_NA', 'MIXED', 'MAILLIST', '', 'AAA_NEG', 'ABS_BIG']],
			'the first 2' => [true, 'score', 2, ['ABS_BIG', 'BAYES_SPAM']],
		];
	}

	/** @param list<string> $names */
	#[DataProvider('symbolOrders')]
	public function testSymbolsOrder(bool $informational, string $sort, int $limit, array $names): void {
		$this->cells = self::symbolCells();
		[, , $rows] = self::admin()->getSymbolsReport([['filter' => 'Subject', 'choice' => 'contains', 'value' => 'x']], $informational, $sort, $limit);
		$this->assertSame($names, array_column($rows, 'name'));
	}

	/** @return array<string, array{string, array{name: string, avg_score: float, mails: int}, array{name: string, avg_score: float, mails: int}, int}> comparator, a, b, a's place */
	public static function comparisons(): array {
		$row = fn (string $name, float $avg, int $mails): array => ['name' => $name, 'avg_score' => $avg, 'mails' => $mails];
		return [
			'score: the larger negative first' => ['bySymbolScore', $row('B', -9.0, 1), $row('A', 5.0, 9), -1],
			'score: after the larger negative' => ['bySymbolScore', $row('A', 5.0, 9), $row('B', -9.0, 1), 1],
			'score: equal either way, by name' => ['bySymbolScore', $row('B', -2.0, 1), $row('A', 2.0, 1), 1],
			'score: the same' => ['bySymbolScore', $row('A', 2.0, 1), $row('A', 2.0, 5), 0],
			'mails: more first' => ['bySymbolMails', $row('B', 0.0, 3), $row('A', 9.0, 2), -1],
			'mails: equal, by name' => ['bySymbolMails', $row('B', 0.0, 3), $row('A', 0.0, 3), 1],
		];
	}

	/**
	 * @param array{name: string, avg_score: float, mails: int} $a
	 * @param array{name: string, avg_score: float, mails: int} $b
	 */
	#[DataProvider('comparisons')]
	public function testSymbolOrder(string $comparator, array $a, array $b, int $place): void {
		$this->assertSame($place, (new \ReflectionMethod(MailLogService::class, $comparator))->invoke(null, $a, $b));
	}

	public function testSymbols(): void {
		$this->cells = self::symbolCells();
		$this->assertSame(
			[
				12,
				['greylist', 'add header', 'reject', '', 'custom'],
				[
					self::row('ABS_BIG', 1, ['greylist' => 1], -9.0),
					self::row('BAYES_SPAM', 4, ['reject' => 3, 'add header' => 1], 20.4 / 4.0),
					self::row('', 1, ['' => 1], 2.0),
					self::row('AAA_NEG', 1, ['add header' => 1], -2.0),
					self::row('MIXED', 3, ['reject' => 2, 'add header' => 1], 1.0),
					self::row('MAILLIST', 2, ['custom' => 2], -0.2),
					self::row('R_DKIM_NA', 4, ['reject' => 4], 0.0),
				],
				null,
			],
			self::alice()->getSymbolsReport([['filter' => 'Subject', 'choice' => 'contains', 'value' => 'x']], true, 'score', 10)
		);
		$ids = 'select `id` from `mail_logs` where `subject` LIKE ? and ' . self::OWN . ' order by `id` desc limit 500';
		$this->assertSame([
			["SELECT COUNT(*) AS n FROM ({$ids}) ids", ['%x%', 'alice@example.org']],
			[
				str_replace('{name}', 'jt.name', self::CELLS) . "({$ids}) ids JOIN mail_logs ml ON ml.id = ids.id JOIN mail_log_data md ON md.mail_log_id = ml.id"
					. " CROSS JOIN JSON_TABLE(md.symbols, '$[*]' COLUMNS (name VARCHAR(128) PATH '$.name', score DOUBLE PATH '$.score')) jt GROUP BY jt.name, ml.action",
				['%x%', 'alice@example.org'],
			],
		], $this->db->selects);
	}

	// no filters: the last 7 days by the database's date; only the recorded symbols
	public function testLocalSymbolsOfTheWeek(): void {
		$this->assertSame([12, [], [], '2026-11-20'], self::admin()->getSymbolsReport([], false, 'score', 10, true));
		$ids = 'select `id` from `mail_logs` where `created_day` >= ? order by `id` desc limit 500';
		$this->assertSame([
			['SELECT CURDATE() - INTERVAL 6 DAY AS d', []],
			["SELECT COUNT(*) AS n FROM ({$ids}) ids", ['2026-11-20']],
			[
				str_replace('{name}', 'sn.name', self::CELLS) . "(SELECT DISTINCT h.symbol_id, h.mail_log_id, h.score FROM ({$ids}) ids JOIN mail_log_symbol_hits h ON h.mail_log_id = ids.id) jt"
					. ' JOIN symbol_names sn ON sn.id = jt.symbol_id JOIN mail_logs ml ON ml.id = jt.mail_log_id GROUP BY sn.name, ml.action',
				['2026-11-20'],
			],
		], $this->db->selects);
	}

	/** @return list<array<string, mixed>> */
	private static function virusCells(): array {
		$cell = fn (int $fail, ?string $virus, ?string $engines, int $today, int $yesterday, int $week, int $month): array => compact('fail', 'virus', 'engines', 'today', 'yesterday', 'week', 'month');
		return [
			$cell(1, 'CLAM_VIRUS_FAIL', 'CLAM_VIRUS_FAIL', 0, 1, 1, 3),
			$cell(0, 'Eicar', 'CLAM_VIRUS', 2, 0, 3, 5),
			$cell(0, 'Zeus', 'CLAM_VIRUS, SOPHOS', 2, 1, 2, 2),
			$cell(1, 'AVAST_FAIL', null, 0, 0, 0, 3),
			$cell(0, null, 'CLAM_VIRUS', 0, 0, 1, 1),
			$cell(0, 'Agent', 'SOPHOS', 1, 0, 4, 9),
			$cell(1, 'SOPHOS_FAIL', 'SOPHOS_FAIL', 1, 1, 2, 7),
		];
	}

	/** @return array{virus: string, engines: string, mails: int} */
	private static function virus(string $virus, string $engines, int $mails): array {
		return ['virus' => $virus, 'engines' => $engines, 'mails' => $mails];
	}

	public function testVirusReport(): void {
		$this->cells = self::virusCells();
		$this->assertSame(
			[
				[
					'today' => [self::virus('Eicar', 'CLAM_VIRUS', 2), self::virus('Zeus', 'CLAM_VIRUS, SOPHOS', 2), self::virus('Agent', 'SOPHOS', 1)],
					'yesterday' => [self::virus('Zeus', 'CLAM_VIRUS, SOPHOS', 1)],
					'week' => [self::virus('Agent', 'SOPHOS', 4), self::virus('Eicar', 'CLAM_VIRUS', 3), self::virus('Zeus', 'CLAM_VIRUS, SOPHOS', 2), self::virus('', 'CLAM_VIRUS', 1)],
					'month' => [self::virus('Agent', 'SOPHOS', 9), self::virus('Eicar', 'CLAM_VIRUS', 5), self::virus('Zeus', 'CLAM_VIRUS, SOPHOS', 2), self::virus('', 'CLAM_VIRUS', 1)],
				],
				[
					['virus' => 'SOPHOS_FAIL', 'engines' => 'SOPHOS_FAIL', 'windows' => ['today' => 1, 'yesterday' => 1, 'week' => 2, 'month' => 7]],
					['virus' => 'AVAST_FAIL', 'engines' => '', 'windows' => ['today' => 0, 'yesterday' => 0, 'week' => 0, 'month' => 3]],
					['virus' => 'CLAM_VIRUS_FAIL', 'engines' => 'CLAM_VIRUS_FAIL', 'windows' => ['today' => 0, 'yesterday' => 1, 'week' => 1, 'month' => 3]],
				],
			],
			self::admin()->getVirusReport([], 10)
		);
		$this->assertSame([[
			"select RIGHT(v.av, 5) = '_FAIL' AS fail, IF(v.name = '', v.av, v.name) AS virus, GROUP_CONCAT(DISTINCT v.av ORDER BY v.av SEPARATOR ', ') AS engines, " . self::WINDOWS
				. ' from `mail_log_viruses` as `v` where v.created_day >= CURDATE() - INTERVAL 30 DAY group by `fail`, `virus`',
			[],
		]], $this->db->selects);
	}

	public function testVirusReportTop(): void {
		$this->cells = self::virusCells();
		[$windows, $failures] = self::admin()->getVirusReport([], 1);
		$this->assertSame(
			[['Eicar'], ['Zeus'], ['Agent'], ['Agent'], 3],
			[array_column($windows['today'], 'virus'), array_column($windows['yesterday'], 'virus'), array_column($windows['week'], 'virus'), array_column($windows['month'], 'virus'), count($failures)]
		);
	}

	public function testNoVirus(): void {
		$this->assertSame([['today' => [], 'yesterday' => [], 'week' => [], 'month' => []], []], self::admin()->getVirusReport([], 10));
	}

	/** @return array<string, array{array{is_admin: bool, email: string}, list<array{filter: string, choice: string, value: string}>, string, list<string>}> context, filters, the scope, its bindings */
	public static function virusScopes(): array {
		$admin = ['is_admin' => true, 'email' => 'root@example.org'];
		$date = ['filter' => 'Date', 'choice' => 'is equal to', 'value' => '2026-11-26'];
		$subject = ['filter' => 'Subject', 'choice' => 'contains', 'value' => 'x'];
		return [
			'an admin, no filters' => [$admin, [], '', []],
			'an admin, only a date' => [$admin, [$date], '', []],
			'an admin, a filter' => [$admin, [$date, $subject], ' and `v`.`mail_log_id` in (select `id` from `mail_logs` where `subject` LIKE ?)', ['%x%']],
			'a user' => [['is_admin' => false, 'email' => 'alice@example.org'], [], ' and `v`.`mail_log_id` in (select `id` from `mail_logs` where ' . self::OWN . ')', ['alice@example.org']],
		];
	}

	/**
	 * @param array{is_admin: bool, email: string} $context
	 * @param list<array{filter: string, choice: string, value: string}> $filters
	 * @param list<string> $bindings
	 */
	#[DataProvider('virusScopes')]
	public function testVirusScope(array $context, array $filters, string $scope, array $bindings): void {
		(new MailLogService($context))->getVirusReport($filters, 10);
		(new MailLogService($context))->getVirusMailIds($filters, 'Eicar', 'today', false);
		$this->assertSame([$scope, $bindings], [substr($this->db->selects[0][0], (int) strrpos($this->db->selects[0][0], ' INTERVAL 30 DAY') + 16, -strlen(' group by `fail`, `virus`')), $this->db->selects[0][1]]);
		$this->assertSame([$scope, ['Eicar', '', 'Eicar', ...$bindings]], [substr($this->db->selects[1][0], (int) strpos($this->db->selects[1][0], "'_FAIL'") + 7, -strlen(' order by `v`.`mail_log_id` desc limit 500')), $this->db->selects[1][1]]);
	}

	/** @return array<string, array{string, bool, string, string}> window, failures, its condition, the kind */
	public static function virusMails(): array {
		return [
			'today' => ['today', false, '= CURDATE()', '<>'],
			'yesterday, failures' => ['yesterday', true, '= CURDATE() - INTERVAL 1 DAY', '='],
			'week' => ['week', false, '>= CURDATE() - INTERVAL 6 DAY', '<>'],
			'month' => ['month', false, '>= CURDATE() - INTERVAL 30 DAY', '<>'],
		];
	}

	#[DataProvider('virusMails')]
	public function testVirusMailIds(string $window, bool $fail, string $condition, string $kind): void {
		$this->cells = [['mail_log_id' => 9], ['mail_log_id' => '7']];
		$this->assertSame([9, 7], self::admin()->getVirusMailIds([], 'Eicar', $window, $fail));
		$this->assertSame([[
			'select distinct `v`.`mail_log_id` from `mail_log_viruses` as `v` where (`v`.`name` = ? or (`v`.`name` = ? and `v`.`av` = ?))'
				. " and v.created_day {$condition} and RIGHT(v.av, 5) {$kind} '_FAIL' order by `v`.`mail_log_id` desc limit 500",
			['Eicar', '', 'Eicar'],
		]], $this->db->selects);
	}
}
