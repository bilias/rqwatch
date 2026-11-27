<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 MapHitService::recordHits() on RecordingDatabase. The symbols worth a
 hit are those a map names (map_symbols in the config, a custom map's own
 list) and the local rules' (local_symbol_patterns), not the fuzzy ones.
 Each gets its symbol_names id, a name not seen before added (as INSERT
 IGNORE) and read back. A hit row goes in (as INSERT IGNORE) on the mail's
 own day, with the symbol's score, for each enabled map entry its options
 name: a built-in map's row by its fields (an IP also by the longest
 CIDR), a custom map's by its key (an option "key:<check>:<+|->" by its
 key too), a local rule's by the custom entries carrying it as their own
 symbol; a symbol with none gets one row with no entry. The active virus
 skip map's entries matching the mail's antivirus symbols are recorded
 under RQWATCH_VIRUS_NO_NOTIFY. Nothing wanted: nothing asked. A mail that
 is gone, or a failing query, is logged, never thrown.
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

final class MapHitRecordTest extends UnitTestCase
{
	private RecordingDatabase $db;

	private MigrationStatus $status;

	/** @var array<array-key, int> the symbol names recorded before, with their ids (a name of digits keyed by an int) */
	private array $known = ['RQWATCH_IP_BL' => 3];

	/** @var list<array<string, mixed>> the mail's row, its day */
	private array $mail = [['created_day' => '2026-11-20']];

	/** @var list<array<string, mixed>> the custom maps' symbol lists */
	private array $customMaps = [['symbols' => 'RQWATCH_PHISH, bad-name', 'map_name' => 'phish_urls']];

	/** @var array<string, list<array<string, mixed>>> what a maps_combined lookup finds, by its first binding (the map) and the rest */
	private array $combined = [];

	/** @var list<array<string, mixed>> the maps_custom rows any lookup there finds (filtered here by map) */
	private array $custom = [];

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->db = new RecordingDatabase($this->createStub(PDO::class));
		$this->db->capsule->bootEloquent();
		$this->db->answer = $this->answer(...);
		$this->status = new MigrationStatus(null, $this->log);
		$this->status->setMigrationState(Migrations::MAP_HITS, Migrations::STATUS_COMPLETED);
		$this->status->setMigrationState(Migrations::VIRUS_SKIP_MAP, Migrations::STATUS_COMPLETED);
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, $this->db->capsule, $this->status, null, false));
		Config::set('map_symbols', ['ip_blacklist' => ['RQWATCH_IP_BL'], 'mail_from_rcpt_to_whitelist' => ['RQWATCH_FROM_TO_WL']]);
		Config::set('local_symbol_patterns', ['RQWATCH_*', 'LOCAL_*']);
		Config::set('notification_skip_virus_map', null);
	}

	#[\Override]
	protected function tearDown(): void {
		Model::unsetConnectionResolver();
		RecordingDatabase::forget();
		parent::tearDown();
	}

	/**
	 * @param list<mixed> $bindings
	 * @return list<array<string, mixed>>
	 */
	private function answer(string $sql, array $bindings): array {
		if (str_contains($sql, 'from `custom_map_config`')) {
			return $this->customMaps;
		}
		if (str_contains($sql, 'from `symbol_names`')) {
			$ids = $this->known;
			foreach ($this->db->rows('symbol_names') as $i => $row) {
				/** @var mixed $name */
				$name = $row['name'] ?? null;
				if (is_string($name)) {
					$ids[$name] = 100 + $i;
				}
			}
			return array_values(array_map(
				fn (mixed $name): array => ['id' => $ids[$name], 'name' => $name],
				array_filter($bindings, fn (mixed $name): bool => is_string($name) && isset($ids[$name]))
			));
		}
		if (str_contains($sql, 'from `mail_logs`')) {
			return $this->mail;
		}
		if (str_contains($sql, 'from `maps_combined`')) {
			return $this->combined[implode('|', array_map(fn (mixed $b): string => is_scalar($b) ? (string) $b : '', $bindings))] ?? [];
		}
		if (str_contains($sql, 'from `maps_custom`')) {
			return $this->custom;
		}
		throw new RuntimeException("Unexpected query: {$sql}");
	}

	/** @param array<string, mixed> ...$symbols */
	private static function symbols(array ...$symbols): string {
		return json_encode($symbols, JSON_THROW_ON_ERROR);
	}

	/** @return list<list<mixed>> the hits recorded, each as [symbol id, model, entry id, score] */
	private function hits(): array {
		return array_map(function (array $row): array {
			$this->assertSame(['created_day' => '2026-11-20', 'mail_log_id' => 7], ['created_day' => $row['created_day'] ?? null, 'mail_log_id' => $row['mail_log_id'] ?? null]);
			return [$row['symbol_id'] ?? null, $row['entry_model'] ?? null, $row['entry_id'] ?? null, $row['score'] ?? null];
		}, $this->db->rows('mail_log_symbol_hits'));
	}

	public function testRecorded(): void {
		$this->combined = [
			'ip_blacklist|0|192.0.2.7' => [['id' => 11]],
		];
		$this->custom = [
			['id' => 21, 'pattern' => 'evil.example.org'],
			['id' => 22, 'pattern' => 'evil.example.org.uk'],
			['id' => 31, 'pattern' => '/^x/i LOCAL_RULE:2'],
		];
		(new MapHitService())->recordHits(7, self::symbols(
			['name' => 'RQWATCH_IP_BL', 'score' => 5.5, 'options' => [' ', ' 192.0.2.7 ']],
			['name' => 'RQWATCH_PHISH', 'score' => '2', 'options' => ['evil.example.org:d:+', ['not', 'scalar'], 'evil.example.org']],
			['name' => 'LOCAL_RULE', 'score' => 'n/a'],
			['name' => 'RQWATCH_FROM_TO_WL', 'score' => -1, 'options' => ['a@example.org|b@example.org']],
			['name' => 'RQWATCH_FUZZY_DENIED', 'score' => 9],
			['name' => 'BAYES_SPAM', 'score' => 3],
		), 'Q1');
		$this->assertSame([], $this->log->lines);
		$this->assertSame([['name' => 'RQWATCH_PHISH'], ['name' => 'LOCAL_RULE'], ['name' => 'RQWATCH_FROM_TO_WL']], $this->db->rows('symbol_names'));
		$this->assertSame([
			[3, MapHitService::ENTRY_COMBINED, 11, 5.5],
			[100, MapHitService::ENTRY_CUSTOM, 21, 2.0],
			[101, MapHitService::ENTRY_CUSTOM, 31, null],
			[102, 0, 0, -1.0],
		], $this->hits());
		$custom = 'select `pattern`, `id` from `maps_custom` where `map_name` = ? and `disabled` = ? and (`pattern` = ? or `pattern` like ?)';
		$this->assertSame([
			['select `symbols`, `map_name` from `custom_map_config` where `symbols` is not null', []],
			['select `id`, `name` from `symbol_names` where `name` in (?, ?, ?, ?)', ['RQWATCH_IP_BL', 'RQWATCH_PHISH', 'LOCAL_RULE', 'RQWATCH_FROM_TO_WL']],
			['select `id`, `name` from `symbol_names` where `name` in (?, ?, ?)', ['RQWATCH_PHISH', 'LOCAL_RULE', 'RQWATCH_FROM_TO_WL']],
			['select `created_day` from `mail_logs` where `id` = ? limit 1', [7]],
			['select `id` from `maps_combined` where `map_name` = ? and `disabled` = ? and `ip` = ?', ['ip_blacklist', 0, '192.0.2.7']],
			[$custom, ['phish_urls', 0, 'evil.example.org:d:+', 'evil.example.org:d:+ %']],
			[$custom, ['phish_urls', 0, 'evil.example.org', 'evil.example.org %']],
			[$custom, ['phish_urls', 0, 'evil.example.org', 'evil.example.org %']],
			['select `pattern`, `id` from `maps_custom` where `disabled` = ? and (`pattern` like ? or `pattern` like ?)', [0, '% LOCAL\\_RULE:%', '% LOCAL\\_RULE']],
			['select `id` from `maps_combined` where `map_name` = ? and `disabled` = ? and `mail_from` = ? and `rcpt_to` = ?', ['mail_from_rcpt_to_whitelist', 0, 'a@example.org', 'b@example.org']],
		], $this->db->selects);
	}

	// every entry found is recorded once: several of a custom key, of a local rule,
	// and the same id in two maps naming the symbol (a built-in and a custom one)
	public function testSeveralEntries(): void {
		$this->customMaps = [['symbols' => 'RQWATCH_PHISH', 'map_name' => 'phish_urls'], ['symbols' => 'RQWATCH_IP_BL', 'map_name' => 'ips']];
		$this->combined = ['ip_blacklist|0|192.0.2.7' => [['id' => 27]]];
		$this->custom = [
			['id' => 21, 'pattern' => 'evil.example.org'],
			['id' => 26, 'pattern' => 'EVIL.example.org reported'],
			['id' => 27, 'pattern' => '192.0.2.7'],
			['id' => 31, 'pattern' => '/a/ LOCAL_RULE'],
			['id' => 33, 'pattern' => '/b/ LOCAL_RULE:1'],
		];
		(new MapHitService())->recordHits(7, self::symbols(
			['name' => 'RQWATCH_IP_BL', 'score' => 1, 'options' => ['192.0.2.7']],
			['name' => 'RQWATCH_PHISH', 'score' => 2, 'options' => ['evil.example.org']],
			['name' => 'LOCAL_RULE', 'score' => 3],
		), 'Q1');
		$this->assertSame([
			[3, MapHitService::ENTRY_COMBINED, 27, 1.0], [3, MapHitService::ENTRY_CUSTOM, 27, 1.0],
			[100, MapHitService::ENTRY_CUSTOM, 21, 2.0], [100, MapHitService::ENTRY_CUSTOM, 26, 2.0],
			[101, MapHitService::ENTRY_CUSTOM, 31, 3.0], [101, MapHitService::ENTRY_CUSTOM, 33, 3.0],
		], $this->hits());
	}

	// an option is also its key only when it ends in ":<check>:<+|->" and is one line
	public function testOptionKeys(): void {
		(new MapHitService())->recordHits(7, self::symbols(
			['name' => 'RQWATCH_PHISH', 'score' => 1, 'options' => ['a.example.org:d:+x', "b\nc.example.org:d:+"]],
		), 'Q1');
		$this->assertSame(
			[['phish_urls', 0, 'a.example.org:d:+x', 'a.example.org:d:+x %'], ['phish_urls', 0, "b\nc.example.org:d:+", "b\nc.example.org:d:+ %"]],
			array_column(array_slice($this->db->selects, 4), 1)
		);
	}

	// an IP no entry names: the entries with the longest prefix holding it
	public function testNetworks(): void {
		$this->customMaps = [['symbols' => 'RQWATCH_NETS', 'map_name' => 'nets']];
		$this->combined = ['ip_blacklist|0|%/%' => [['ip' => '192.0.2.0/24', 'id' => 12], ['ip' => '192.0.2.0/28', 'id' => 13], ['ip' => '198.51.100.0/24', 'id' => 14]]];
		$this->custom = [['id' => 23, 'pattern' => '192.0.2.0/24 a comment'], ['id' => 24, 'pattern' => '0.0.0.0/0']];
		$this->combined['mail_from_rcpt_to_whitelist|0|%/%'] = [['ip' => '192.0.2.0/24', 'id' => 15]];
		(new MapHitService())->recordHits(7, self::symbols(
			['name' => 'RQWATCH_IP_BL', 'score' => 1, 'options' => ['192.0.2.9']],
			['name' => 'RQWATCH_NETS', 'score' => 1, 'options' => ['192.0.2.9']],
			// a map of two fields: an IP is no key of it, nor a network
			['name' => 'RQWATCH_FROM_TO_WL', 'score' => 1, 'options' => ['192.0.2.9']],
		), 'Q1');
		$this->assertSame([[3, MapHitService::ENTRY_COMBINED, 13, 1.0], [100, MapHitService::ENTRY_CUSTOM, 23, 1.0], [101, 0, 0, 1.0]], $this->hits());
		$custom = 'select `pattern`, `id` from `maps_custom` where `map_name` = ? and `disabled` = ?';
		$this->assertSame([
			['select `id` from `maps_combined` where `map_name` = ? and `disabled` = ? and `ip` = ?', ['ip_blacklist', 0, '192.0.2.9']],
			['select `ip`, `id` from `maps_combined` where `map_name` = ? and `disabled` = ? and `ip` like ?', ['ip_blacklist', 0, '%/%']],
			["{$custom} and (`pattern` = ? or `pattern` like ?)", ['nets', 0, '192.0.2.9', '192.0.2.9 %']],
			["{$custom} and `pattern` like ?", ['nets', 0, '%/%']],
		], array_slice($this->db->selects, 4));
	}

	// the active virus skip map's entries matching an antivirus symbol or its options
	public function testVirusSkip(): void {
		Config::set('notification_skip_virus_map', ' virus_skip ');
		$this->custom = [['id' => 41, 'pattern' => 'eicar-*'], ['id' => 42, 'pattern' => 'Other*'], ['id' => 43, 'pattern' => 'CLAM_*']];
		(new MapHitService())->recordHits(7, self::symbols(
			['name' => 'CLAM_VIRUS', 'group' => 'antivirus', 'score' => 8, 'options' => ['Eicar-Test-Signature']],
		), 'Q1');
		$this->assertSame([[100, MapHitService::ENTRY_CUSTOM, 41, null], [100, MapHitService::ENTRY_CUSTOM, 43, null]], $this->hits());
		$this->assertSame([['name' => MapHitService::VIRUS_SKIP_SYMBOL]], $this->db->rows('symbol_names'));
		$this->assertSame(['select `pattern`, `id` from `maps_custom` where `map_name` = ? and `disabled` = ?', ['virus_skip', 0]], $this->db->selects[1] ?? null);
	}

	/** @return array<string, array{?string, ?string, string}> the skip map, its migration, the symbols */
	public static function noVirusSkip(): array {
		$virus = self::symbols(['name' => 'CLAM_VIRUS', 'group' => 'antivirus', 'options' => ['Eicar']]);
		return [
			'no skip map' => [null, Migrations::STATUS_COMPLETED, $virus],
			'its migration pending' => ['virus_skip', null, $virus],
			'no antivirus symbol' => ['virus_skip', Migrations::STATUS_COMPLETED, self::symbols(['name' => 'BAYES_SPAM', 'group' => 'statistics'])],
			'not a list' => ['virus_skip', Migrations::STATUS_COMPLETED, '"text"'],
		];
	}

	// nothing to record: nothing asked past the custom maps' symbols
	#[DataProvider('noVirusSkip')]
	public function testNothingWanted(?string $map, ?string $migration, string $symbols): void {
		Config::set('notification_skip_virus_map', $map);
		$this->status->setMigrationState(Migrations::VIRUS_SKIP_MAP, $migration);
		(new MapHitService())->recordHits(7, $symbols, 'Q1');
		$this->assertSame([[], [], []], [$this->db->rows('symbol_names'), $this->db->rows('mail_log_symbol_hits'), $this->log->lines]);
		$this->assertCount(1, $this->db->selects);
	}

	// a symbol named with digits is keyed by an int, and still found, a map's or a local rule's
	public function testDigitsName(): void {
		Config::set('map_symbols', ['ip_whitelist' => ['1234']]);
		Config::set('local_symbol_patterns', ['56*']);
		$this->known = ['1234' => 9, '5678' => 10];
		(new MapHitService())->recordHits(7, self::symbols(['name' => '1234', 'score' => 1], ['name' => '5678', 'score' => 2]), 'Q1');
		$this->assertSame([[[9, 0, 0, 1.0], [10, 0, 0, 2.0]], []], [$this->hits(), $this->log->lines]);
		// the local rule's own entries were asked for, by its name as text
		$this->assertSame([0, '% 5678:%', '% 5678'], end($this->db->selects)[1] ?? null);
	}

	// a custom map named with digits is keyed by an int, and still asked by its name as text
	public function testDigitsMapName(): void {
		$this->customMaps = [['symbols' => 'RQWATCH_D', 'map_name' => '1234']];
		$this->custom = [['id' => 25, 'pattern' => 'key']];
		(new MapHitService())->recordHits(7, self::symbols(['name' => 'RQWATCH_D', 'score' => 1, 'options' => ['key']]), 'Q1');
		$this->assertSame([[[100, MapHitService::ENTRY_CUSTOM, 25, 1.0]], []], [$this->hits(), $this->log->lines]);
		$this->assertSame(['1234', 0, 'key', 'key %'], end($this->db->selects)[1] ?? null);
	}

	// a name its lookup does not find back: no hit for it, the next still recorded
	public function testNameNotFound(): void {
		$this->db->answer = fn (string $sql, array $bindings): array => str_contains($sql, 'from `symbol_names`')
			? (in_array('RQWATCH_IP_BL', $bindings, true) ? [['id' => 3, 'name' => 'RQWATCH_IP_BL']] : [])
			: $this->answer($sql, array_values($bindings));
		(new MapHitService())->recordHits(7, self::symbols(['name' => 'LOCAL_NEW', 'score' => 1], ['name' => 'RQWATCH_IP_BL', 'score' => 2]), 'Q1');
		$this->assertSame([[['name' => 'LOCAL_NEW']], [[3, 0, 0, 2.0]]], [$this->db->rows('symbol_names'), $this->hits()]);
	}

	public function testMailGone(): void {
		$this->mail = [];
		(new MapHitService())->recordHits(7, self::symbols(['name' => 'RQWATCH_IP_BL', 'score' => 1]), 'Q1');
		$this->assertSame([[], ['error [MapHitService_recordHits] Q1 mail id 7 not found']], [$this->db->rows('mail_log_symbol_hits'), $this->log->lines]);
	}

	public function testFailureLogged(): void {
		$this->db->answer = fn (): never => throw new RuntimeException('server has gone away');
		(new MapHitService())->recordHits(7, self::symbols(['name' => 'RQWATCH_IP_BL', 'score' => 1]), 'Q1');
		$this->assertSame(['error [MapHitService_recordHits] Q1 could not record map hits: server has gone away'], $this->log->lines);
	}
}
