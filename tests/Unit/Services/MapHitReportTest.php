<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 MapHitService beside recordHits(), on RecordingDatabase. An entry whose
 key changed has its hits detached (credited to no entry, or dropped when
 the mail has such a row already), logged when there were any; nothing
 before the map hits migration; a failure logged. The backfill reads its
 window (the last n days, today included, or all) from the mail log: the
 first id, the count from an id, and batches after an id, whose hits are
 deleted and recorded again. The reports: an entry's mails in a window and
 last day; the mail ids credited to an entry, or to a symbol uncredited,
 newest first; a mail's hits by symbol; the map of each entry still there;
 per map the mails in each report window and the last day, its symbols'
 hits and its entries'; the symbols hit but credited to no map; a map's
 mail ids, limited by the mails' outcome or a day; its mails per day,
 every day of the window; its mails by action, released and notified.
 Only the symbols recorded so far are asked for.
*/

namespace Tests\Unit\Services;

use App\Configuration\Config;
use App\Core\App;
use App\Core\AppContainer;
use App\Core\Database\MigrationStatus;
use App\Inventory\Migrations;
use App\Services\MapHitService;
use Illuminate\Database\Eloquent\Model;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Unit\RecordingDatabase;
use Tests\Unit\UnitTestCase;

final class MapHitReportTest extends UnitTestCase
{
	private const string WINDOWS_T = 'COUNT(DISTINCT CASE WHEN t.created_day >= CURDATE() - INTERVAL 0 DAY THEN t.mail_log_id END) AS d1,'
		. ' COUNT(DISTINCT CASE WHEN t.created_day >= CURDATE() - INTERVAL 6 DAY THEN t.mail_log_id END) AS d7,'
		. ' COUNT(DISTINCT CASE WHEN t.created_day >= CURDATE() - INTERVAL 30 DAY THEN t.mail_log_id END) AS d31';
	private const string SYMBOLS_SOURCE = 'SELECT h.created_day, h.mail_log_id FROM mail_log_symbol_hits h WHERE h.symbol_id IN ';
	private const string COMBINED_SOURCE = 'SELECT h.created_day, h.mail_log_id FROM mail_log_symbol_hits h JOIN maps_combined e ON e.id = h.entry_id'
		. ' WHERE h.entry_model = ? AND e.map_name = ?';
	private const string CUSTOM_SOURCE = 'SELECT h.created_day, h.mail_log_id FROM mail_log_symbol_hits h JOIN maps_custom e ON e.id = h.entry_id'
		. ' WHERE h.entry_model = ? AND e.map_name = ?';
	private const string WINDOW = ' AND h.created_day >= CURDATE() - INTERVAL ? DAY';

	private RecordingDatabase $db;

	private MigrationStatus $status;

	/** @var list<array{string, list<array<string, mixed>>}> the rows of the first query holding each text */
	private array $answers = [];

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->db = new RecordingDatabase($this->createStub(PDO::class));
		$this->db->capsule->bootEloquent();
		$this->db->answer = function (string $sql): array {
			foreach ($this->answers as [$needle, $rows]) {
				if (str_contains($sql, $needle)) {
					return $rows;
				}
			}
			return [];
		};
		$this->status = new MigrationStatus(null, $this->log);
		$this->status->setMigrationState(Migrations::MAP_HITS, Migrations::STATUS_COMPLETED);
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, $this->db->capsule, $this->status, null, false));
	}

	#[\Override]
	protected function tearDown(): void {
		Model::unsetConnectionResolver();
		RecordingDatabase::forget();
		parent::tearDown();
	}

	/** @return array<string, array{int, int, list<string>}> rows moved, dropped, the log */
	public static function detached(): array {
		return [
			'moved and dropped' => [2, 1, ['info [MapHitService] entry 1:5 key changed, detached 3 hit(s)']],
			'one dropped' => [0, 1, ['info [MapHitService] entry 1:5 key changed, detached 1 hit(s)']],
			'none' => [0, 0, []],
		];
	}

	/** @param list<string> $log */
	#[DataProvider('detached')]
	public function testDetachEntry(int $moved, int $dropped, array $log): void {
		$this->db->affect = fn (string $sql): int => str_starts_with($sql, 'UPDATE') ? $moved : $dropped;
		(new MapHitService())->detachEntry(MapHitService::ENTRY_COMBINED, 5);
		$this->assertSame([
			['UPDATE IGNORE `mail_log_symbol_hits` SET entry_model = ?, entry_id = 0 WHERE entry_model = ? AND entry_id = ?', [0, 1, 5]],
			['DELETE FROM `mail_log_symbol_hits` WHERE entry_model = ? AND entry_id = ?', [1, 5]],
		], $this->db->changes);
		$this->assertSame($log, $this->log->lines);
	}

	public function testDetachEntryBeforeMapHits(): void {
		$this->status->setMigrationState(Migrations::MAP_HITS, Migrations::STATUS_RUNNING);
		(new MapHitService())->detachEntry(MapHitService::ENTRY_CUSTOM, 5);
		$this->assertSame([[], []], [$this->db->changes, $this->log->lines]);
	}

	public function testDetachEntryFailure(): void {
		$this->db->affect = fn (): never => throw new RuntimeException('server has gone away');
		(new MapHitService())->detachEntry(MapHitService::ENTRY_CUSTOM, 5);
		$this->assertSame(['error [MapHitService_detachEntry] entry 2:5: server has gone away'], $this->log->lines);
	}

	public function testBackfillWindow(): void {
		$this->answers = [['min(`id`)', [['aggregate' => '12']]], ['count(*)', [['aggregate' => 30]]]];
		$service = new MapHitService();
		$this->assertSame([12, 12, 30, 30], [$service->backfillStartId(3), $service->backfillStartId(null), $service->backfillCount(10, 3), $service->backfillCount(10, null)]);
		$this->assertSame([
			['select min(`id`) as aggregate from `mail_logs` where created_day >= CURDATE() - INTERVAL ? DAY', [2]],
			['select min(`id`) as aggregate from `mail_logs`', []],
			['select count(*) as aggregate from `mail_logs` where `id` >= ? and created_day >= CURDATE() - INTERVAL ? DAY', [10, 2]],
			['select count(*) as aggregate from `mail_logs` where `id` >= ?', [10]],
		], $this->db->selects);
	}

	public function testBackfillNoMail(): void {
		$this->answers = [['min(`id`)', [['aggregate' => null]]]];
		$service = new MapHitService();
		$this->assertNull($service->backfillStartId(1));
		$this->assertSame([null, 0], $service->backfillBatch(10, 50, 1));
		$this->assertSame(
			'select `ml`.`id`, `ml`.`qid`, `md`.`symbols` from `mail_logs` as `ml` inner join `mail_log_data` as `md` on `md`.`mail_log_id` = `ml`.`id`'
				. ' where `ml`.`id` > ? and ml.created_day >= CURDATE() - INTERVAL ? DAY order by `ml`.`id` asc limit 50',
			$this->db->selects[1][0] ?? null
		);
		$this->assertSame([10, 0], $this->db->selects[1][1] ?? null);
		$this->assertSame([], $this->db->changes);
	}

	// the batch's hits replaced: deleted, then recorded again per mail
	public function testBackfillBatch(): void {
		Config::set('local_symbol_patterns', ['LOCAL_*']);
		$symbols = '[{"name":"LOCAL_RULE","score":2}]';
		$this->answers = [
			['as `ml`', [['id' => 11, 'qid' => 'Q11', 'symbols' => $symbols], ['id' => 12, 'qid' => 'Q12', 'symbols' => $symbols]]],
			['from `symbol_names`', [['id' => 5, 'name' => 'LOCAL_RULE']]],
			['from `mail_logs`', [['created_day' => '2026-11-20']]],
		];
		$this->assertSame([12, 2], (new MapHitService())->backfillBatch(10, 2, null));
		$this->assertSame(
			'select `ml`.`id`, `ml`.`qid`, `md`.`symbols` from `mail_logs` as `ml` inner join `mail_log_data` as `md` on `md`.`mail_log_id` = `ml`.`id`'
				. ' where `ml`.`id` > ? order by `ml`.`id` asc limit 2',
			$this->db->selects[0][0] ?? null
		);
		$this->assertSame([['delete from `mail_log_symbol_hits` where `mail_log_id` in (?, ?)', [11, 12]]], $this->db->changes);
		$this->assertSame([11, 12], array_column($this->db->rows('mail_log_symbol_hits'), 'mail_log_id'));
		$this->assertSame([], $this->log->lines);
	}

	// each mail recorded with its own qid
	public function testBackfillBatchMailGone(): void {
		Config::set('local_symbol_patterns', ['LOCAL_*']);
		$this->answers = [
			['as `ml`', [['id' => 11, 'qid' => 'Q11', 'symbols' => '[{"name":"LOCAL_RULE"}]'], ['id' => 12, 'qid' => null, 'symbols' => null]]],
			['from `symbol_names`', [['id' => 5, 'name' => 'LOCAL_RULE']]],
		];
		$this->assertSame([12, 2], (new MapHitService())->backfillBatch(10, 2, null));
		$this->assertSame(['error [MapHitService_recordHits] Q11 mail id 11 not found'], $this->log->lines);
	}

	public function testEntryHits(): void {
		$this->answers = [
			['COUNT(DISTINCT', [['entry_id' => 3, 'hits' => '2']]],
			['MAX(created_day)', [['entry_id' => 3, 'last' => '2026-11-20'], ['entry_id' => 4, 'last' => '2026-10-01']]],
		];
		$this->assertSame([
			3 => ['hits' => 2, 'last' => '2026-11-20'],
			4 => ['hits' => 0, 'last' => '2026-10-01'],
			5 => ['hits' => 0, 'last' => null],
		], (new MapHitService())->entryHits(MapHitService::ENTRY_CUSTOM, ['3', 4, 3, 5], 7));
		$this->assertSame([
			['select entry_id, COUNT(DISTINCT mail_log_id) AS hits from `mail_log_symbol_hits` where `entry_model` = ? and `entry_id` in (?, ?, ?)'
				. ' and created_day >= CURDATE() - INTERVAL ? DAY group by `entry_id`', [2, 3, 4, 5, 6]],
			['select entry_id, MAX(created_day) AS last from `mail_log_symbol_hits` where `entry_model` = ? and `entry_id` in (?, ?, ?) group by `entry_id`', [2, 3, 4, 5]],
		], $this->db->selects);
	}

	public function testEntryHitsNone(): void {
		$this->assertSame([], (new MapHitService())->entryHits(MapHitService::ENTRY_CUSTOM, [], 7));
		$this->assertSame([], $this->db->selects);
	}

	public function testEntryHitMailIds(): void {
		$this->answers = [['mail_log_symbol_hits', [['mail_log_id' => '9'], ['mail_log_id' => 7]]]];
		$service = new MapHitService();
		$this->assertSame([9, 7], $service->entryHitMailIds(MapHitService::ENTRY_COMBINED, 5, 7, 10));
		$service->entryHitMailIds(MapHitService::ENTRY_COMBINED, 5, null, 3);
		$this->assertSame([
			['select distinct `mail_log_id` from `mail_log_symbol_hits` where `entry_model` = ? and `entry_id` = ?'
				. ' and created_day >= CURDATE() - INTERVAL ? DAY order by `mail_log_id` desc limit 10', [1, 5, 6]],
			['select distinct `mail_log_id` from `mail_log_symbol_hits` where `entry_model` = ? and `entry_id` = ? order by `mail_log_id` desc limit 3', [1, 5]],
		], $this->db->selects);
	}

	// a symbol hit with no entry is listed with none
	public function testMailHits(): void {
		$this->answers = [['symbol_names', [
			['name' => 'RQWATCH_IP_BL', 'entry_model' => 1, 'entry_id' => 11],
			['name' => 'RQWATCH_IP_BL', 'entry_model' => '2', 'entry_id' => '27'],
			['name' => 'LOCAL_RULE', 'entry_model' => 0, 'entry_id' => 0],
		]]];
		$this->assertSame(['RQWATCH_IP_BL' => [[1, 11], [2, 27]], 'LOCAL_RULE' => []], (new MapHitService())->mailHits(9));
		$this->assertSame([[
			'select `sn`.`name`, `h`.`entry_model`, `h`.`entry_id` from `mail_log_symbol_hits` as `h` inner join `symbol_names` as `sn` on `sn`.`id` = `h`.`symbol_id` where `h`.`mail_log_id` = ?',
			[9],
		]], $this->db->selects);
	}

	public function testEntryMaps(): void {
		$this->answers = [
			['maps_combined', [['map_name' => 'ip_blacklist', 'id' => 3]]],
			['maps_custom', [['map_name' => 'phish_urls', 'id' => '4']]],
		];
		$this->assertSame(['1:3' => 'ip_blacklist', '2:4' => 'phish_urls'], (new MapHitService())->entryMaps([[1, 3], [2, 4], [1, 3], [1, 8]]));
		$this->assertSame([
			['select `map_name`, `id` from `maps_combined` where `id` in (?, ?)', [3, 8]],
			['select `map_name`, `id` from `maps_custom` where `id` in (?)', [4]],
		], $this->db->selects);
	}

	// one model's entries only: the other's table not asked
	public function testEntryMapsOneModel(): void {
		$service = new MapHitService();
		$service->entryMaps([[2, 4]]);
		$service->entryMaps([[1, 3]]);
		$service->entryMaps([]);
		$this->assertSame(['select `map_name`, `id` from `maps_custom` where `id` in (?)', 'select `map_name`, `id` from `maps_combined` where `id` in (?)'], array_column($this->db->selects, 0));
	}

	public function testMapHits(): void {
		$this->answers = [
			['from `symbol_names`', [['id' => 5, 'name' => 'RQWATCH_A'], ['id' => 6, 'name' => 'RQWATCH_B']]],
			['AS d1', [['d1' => '1', 'd7' => 3, 'd31' => null]]],
			['AS last', [['last' => '2026-11-20']]],
		];
		$this->assertSame([
			'ips' => ['windows' => [1 => 1, 7 => 3, 31 => 0], 'last' => '2026-11-20'],
			'phish' => ['windows' => [1 => 1, 7 => 3, 31 => 0], 'last' => '2026-11-20'],
		], (new MapHitService())->mapHits(['ips' => ['MapCombined', ['RQWATCH_A', 'RQWATCH_B']], 'phish' => ['MapCustom', ['RQWATCH_B']]]));
		$symbols = self::SYMBOLS_SOURCE;
		$this->assertSame([
			['select `id`, `name` from `symbol_names` where `name` in (?, ?)', ['RQWATCH_A', 'RQWATCH_B']],
			['SELECT ' . self::WINDOWS_T . " FROM ({$symbols}(?,?)" . self::WINDOW . ' UNION ALL ' . self::COMBINED_SOURCE . self::WINDOW . ') t', [5, 6, 30, 1, 'ips', 30]],
			["SELECT MAX(t.created_day) AS last FROM ({$symbols}(?,?) UNION ALL " . self::COMBINED_SOURCE . ') t', [5, 6, 1, 'ips']],
			['SELECT ' . self::WINDOWS_T . " FROM ({$symbols}(?)" . self::WINDOW . ' UNION ALL ' . self::CUSTOM_SOURCE . self::WINDOW . ') t', [6, 30, 2, 'phish', 30]],
			["SELECT MAX(t.created_day) AS last FROM ({$symbols}(?) UNION ALL " . self::CUSTOM_SOURCE . ') t', [6, 2, 'phish']],
		], $this->db->selects);
	}

	// no symbol recorded, no hit: its entries' hits only, none counted
	public function testMapHitsNone(): void {
		$this->assertSame(['ips' => ['windows' => [1 => 0, 7 => 0, 31 => 0], 'last' => null]], (new MapHitService())->mapHits(['ips' => ['MapCombined', ['RQWATCH_A']]]));
		$this->assertSame([
			['select `id`, `name` from `symbol_names` where `name` in (?)', ['RQWATCH_A']],
			['SELECT ' . self::WINDOWS_T . ' FROM (' . self::COMBINED_SOURCE . self::WINDOW . ') t', [1, 'ips', 30]],
			['SELECT MAX(t.created_day) AS last FROM (' . self::COMBINED_SOURCE . ') t', [1, 'ips']],
		], $this->db->selects);
	}

	// no symbols at all: none asked for
	public function testMapHitsNoSymbols(): void {
		(new MapHitService())->mapHits(['ips' => ['MapCombined', []]]);
		$this->assertSame(['SELECT ' . self::WINDOWS_T . ' FROM (' . self::COMBINED_SOURCE . self::WINDOW . ') t', [1, 'ips', 30]], $this->db->selects[0] ?? null);
	}

	private const string UNMAPPED = 'select sn.name, COUNT(DISTINCT CASE WHEN h.created_day >= CURDATE() - INTERVAL 0 DAY THEN h.mail_log_id END) AS d1,'
		. ' COUNT(DISTINCT CASE WHEN h.created_day >= CURDATE() - INTERVAL 6 DAY THEN h.mail_log_id END) AS d7,'
		. ' COUNT(DISTINCT CASE WHEN h.created_day >= CURDATE() - INTERVAL 30 DAY THEN h.mail_log_id END) AS d31, MAX(h.created_day) AS last'
		. ' from `mail_log_symbol_hits` as `h` inner join `symbol_names` as `sn` on `sn`.`id` = `h`.`symbol_id`'
		. ' where `h`.`entry_model` = ? and h.created_day >= CURDATE() - INTERVAL ? DAY';

	public function testUnmappedHits(): void {
		$this->answers = [
			['from `symbol_names` where', [['id' => 5, 'name' => 'RQWATCH_A']]],
			['group by', [
				['name' => 'LOCAL_RULE', 'd1' => 0, 'd7' => '2', 'd31' => 4, 'last' => '2026-11-19'],
				['name' => 'LOCAL_OTHER', 'd1' => 1, 'd7' => 1, 'd31' => 1, 'last' => '2026-11-20'],
			]],
		];
		$this->assertSame([
			'LOCAL_RULE' => ['windows' => [1 => 0, 7 => 2, 31 => 4], 'last' => '2026-11-19'],
			'LOCAL_OTHER' => ['windows' => [1 => 1, 7 => 1, 31 => 1], 'last' => '2026-11-20'],
		], (new MapHitService())->unmappedHits(['RQWATCH_A', 'RQWATCH_B']));
		$this->assertSame([
			['select `id`, `name` from `symbol_names` where `name` in (?, ?)', ['RQWATCH_A', 'RQWATCH_B']],
			[self::UNMAPPED . ' and `h`.`symbol_id` not in (?) group by `sn`.`name`', [0, 30, 5]],
		], $this->db->selects);
	}

	// no mapped symbol recorded: every uncredited hit
	public function testUnmappedHitsAll(): void {
		$this->assertSame([], (new MapHitService())->unmappedHits([]));
		$this->assertSame([[self::UNMAPPED . ' group by `sn`.`name`', [0, 30]]], $this->db->selects);
	}

	public function testMapHitMailIds(): void {
		$this->answers = [
			['from `symbol_names`', [['id' => 5, 'name' => 'RQWATCH_A']]],
			['DISTINCT t.mail_log_id', [['mail_log_id' => '12'], ['mail_log_id' => 9]]],
		];
		$this->assertSame([12, 9], (new MapHitService())->mapHitMailIds('phish', 'MapCustom', ['RQWATCH_A'], 7, 20, ['action' => 'reject', 'released' => true, 'notified' => true]));
		$this->assertSame([
			'SELECT DISTINCT t.mail_log_id FROM (' . self::SYMBOLS_SOURCE . '(?)' . self::WINDOW . ' UNION ALL ' . self::CUSTOM_SOURCE . self::WINDOW . ') t'
				. ' JOIN mail_logs ml ON ml.id = t.mail_log_id WHERE ml.action = ? AND ml.released = 1 AND ml.notified = 1 ORDER BY t.mail_log_id DESC LIMIT 20',
			[5, 6, 2, 'phish', 6, 'reject'],
		], $this->db->selects[1] ?? null);
	}

	/** @return array<string, array{array{action?: string, released?: bool, notified?: bool}, string, list<int|string>}> */
	public static function outcomes(): array {
		return [
			'any' => [[], '', [1, 'ips', '2026-11-20']],
			'not released, not notified' => [['released' => false, 'notified' => false], '', [1, 'ips', '2026-11-20']],
			'released' => [['released' => true], ' JOIN mail_logs ml ON ml.id = t.mail_log_id WHERE ml.released = 1', [1, 'ips', '2026-11-20']],
			'notified' => [['notified' => true], ' JOIN mail_logs ml ON ml.id = t.mail_log_id WHERE ml.notified = 1', [1, 'ips', '2026-11-20']],
			'an action' => [['action' => ''], ' JOIN mail_logs ml ON ml.id = t.mail_log_id WHERE ml.action = ?', [1, 'ips', '2026-11-20', '']],
		];
	}

	/**
	 * @param array{action?: string, released?: bool, notified?: bool} $outcome
	 * @param list<int|string> $bindings
	 */
	#[DataProvider('outcomes')]
	public function testMapHitMailIdsOfDay(array $outcome, string $join, array $bindings): void {
		(new MapHitService())->mapHitMailIds('ips', 'MapCombined', [], null, 5, $outcome, '2026-11-20');
		$this->assertSame([[
			'SELECT DISTINCT t.mail_log_id FROM (' . self::COMBINED_SOURCE . " AND h.created_day = ?) t{$join} ORDER BY t.mail_log_id DESC LIMIT 5",
			$bindings,
		]], $this->db->selects);
	}

	// every day of the window, oldest first, by the database's today
	public function testMapDailyHits(): void {
		$this->answers = [
			['from `symbol_names`', [['id' => 5, 'name' => 'RQWATCH_A']]],
			['CURDATE() AS d', [['d' => '2026-11-20']]],
			['GROUP BY t.created_day', [['day' => '2026-11-18', 'mails' => '3'], ['day' => '2026-11-20', 'mails' => 1]]],
		];
		$this->assertSame(
			['2026-11-17' => 0, '2026-11-18' => 3, '2026-11-19' => 0, '2026-11-20' => 1],
			(new MapHitService())->mapDailyHits('ips', 'MapCombined', ['RQWATCH_A'], 4)
		);
		$this->assertSame([
			['SELECT t.created_day AS day, COUNT(DISTINCT t.mail_log_id) AS mails FROM (' . self::SYMBOLS_SOURCE . '(?)' . self::WINDOW
				. ' UNION ALL ' . self::COMBINED_SOURCE . self::WINDOW . ') t GROUP BY t.created_day', [5, 3, 1, 'ips', 3]],
			['SELECT CURDATE() AS d', []],
		], array_slice($this->db->selects, 1));
	}

	public function testMapOutcome(): void {
		$this->answers = [
			['from `symbol_names`', [['id' => 5, 'name' => 'RQWATCH_A']]],
			['GROUP BY ml.action', [
				['action' => 'reject', 'mails' => '5', 'released' => '1', 'notified' => 0],
				['action' => null, 'mails' => 2, 'released' => 0, 'notified' => '2'],
			]],
		];
		$this->assertSame(
			['reject' => ['mails' => 5, 'released' => 1, 'notified' => 0], '' => ['mails' => 2, 'released' => 0, 'notified' => 2]],
			(new MapHitService())->mapOutcome('ips', 'MapCombined', ['RQWATCH_A'], 7)
		);
		$this->assertSame([
			'SELECT ml.action, COUNT(*) AS mails, SUM(ml.released = 1) AS released, SUM(ml.notified = 1) AS notified'
				. ' FROM (SELECT DISTINCT t.mail_log_id FROM (' . self::SYMBOLS_SOURCE . '(?)' . self::WINDOW . ' UNION ALL ' . self::COMBINED_SOURCE . self::WINDOW . ') t) x'
				. ' JOIN mail_logs ml ON ml.id = x.mail_log_id GROUP BY ml.action ORDER BY mails DESC',
			[5, 6, 1, 'ips', 6],
		], $this->db->selects[1] ?? null);
	}

	public function testSymbolHitMailIds(): void {
		$this->answers = [
			['from `symbol_names`', [['id' => 5, 'name' => 'LOCAL_RULE']]],
			['mail_log_symbol_hits', [['mail_log_id' => '8']]],
		];
		$service = new MapHitService();
		$this->assertSame([8], $service->symbolHitMailIds('LOCAL_RULE', 7, 10));
		$service->symbolHitMailIds('LOCAL_RULE', null, 3);
		$this->assertSame([
			['select `id`, `name` from `symbol_names` where `name` in (?)', ['LOCAL_RULE']],
			['select distinct `mail_log_id` from `mail_log_symbol_hits` where `symbol_id` = ? and `entry_model` = ?'
				. ' and created_day >= CURDATE() - INTERVAL ? DAY order by `mail_log_id` desc limit 10', [5, 0, 6]],
			['select `id`, `name` from `symbol_names` where `name` in (?)', ['LOCAL_RULE']],
			['select distinct `mail_log_id` from `mail_log_symbol_hits` where `symbol_id` = ? and `entry_model` = ? order by `mail_log_id` desc limit 3', [5, 0]],
		], $this->db->selects);
	}

	// a symbol never recorded: no hit to look for
	public function testSymbolHitMailIdsUnknown(): void {
		$this->assertSame([], (new MapHitService())->symbolHitMailIds('LOCAL_RULE', 7, 10));
		$this->assertCount(1, $this->db->selects);
	}
}
