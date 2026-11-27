<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 MapHitService's logic between its queries. customIdentity(): a custom
 entry's key (a regexp up to its /flags, else up to the first blank) and
 its own symbol, when valid and not a score. longestPrefix(): an IP is
 credited to the entries with the longest prefix holding it, ties all,
 malformed and other-family entries never. wantedSymbols(): the symbols
 of a mail worth recording, those a map names or the local rule patterns
 match, not fuzzy ones, with a numeric score and the scalar options. The
 SQL pieces of the map hit reports (a window of days ends today), and
 entryModel(). With map hits off,
 recordHits() looks nothing up.
*/

namespace Tests\Unit\Services;

use App\Configuration\Config;
use App\Core\App;
use App\Core\AppContainer;
use App\Core\Database\MigrationStatus;
use App\Services\MapHitService;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use stdClass;
use Tests\Unit\RecordingDatabase;
use Tests\Unit\UnitTestCase;

final class MapHitLogicTest extends UnitTestCase
{
	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		// without a warning or deprecation: no entry, option or offset may raise one
		set_error_handler(fn (int $no, string $message): never => throw new \ErrorException($message, 0, $no));
	}

	#[\Override]
	protected function tearDown(): void {
		restore_error_handler();
		parent::tearDown();
	}

	private static function call(string $method, mixed ...$arguments): mixed {
		$service = new MapHitService();
		return (new ReflectionMethod($service, $method))->invoke($service, ...$arguments);
	}

	/** @return array<string, array{string, array{string, ?string}}> */
	public static function identities(): array {
		return [
			'a key alone' => ['example.org', ['example.org', null]],
			'blanks around' => ["  example.org \t LOCAL_BL  ", ['example.org', 'LOCAL_BL']],
			'with a score' => ['example.org LOCAL_BL:5.0', ['example.org', 'LOCAL_BL']],
			'with options' => ['example.org LOCAL_BL:5:a,b', ['example.org', 'LOCAL_BL']],
			'a score alone' => ['example.org 5.0', ['example.org', null]],
			'an int score' => ['example.org 1', ['example.org', null]],
			'not a symbol' => ['example.org bad-symbol', ['example.org', null]],
			'a regexp with blanks' => ['/foo bar/i LOCAL_RE', ['/foo bar/i', 'LOCAL_RE']],
			'a regexp up to its last slash' => ['/a/ x /b/ LOCAL_RE', ['/a/ x /b/', 'LOCAL_RE']],
			'a regexp over lines' => ["/a\nb/ LOCAL_RE", ["/a\nb/", 'LOCAL_RE']],
			'a regexp alone' => ['/foo/', ['/foo/', null]],
			'nothing' => ['  ', ['', null]],
		];
	}

	/** @param array{string, ?string} $identity */
	#[DataProvider('identities')]
	public function testCustomIdentity(string $pattern, array $identity): void {
		$this->assertSame($identity, MapHitService::customIdentity($pattern));
	}

	public function testEntryModel(): void {
		// the values stored in entry_model
		$this->assertSame([2, 1, 1], [MapHitService::entryModel('MapCustom'), MapHitService::entryModel('MapCombined'), MapHitService::entryModel('other')]);
	}

	/** @return array<string, array{string, array<int, string>, list<int>}> IP, entry id => CIDR, ids credited */
	public static function prefixes(): array {
		return [
			'the longest' => ['192.0.2.7', [10 => '192.0.0.0/16', 11 => '192.0.2.0/24'], [11]],
			'the longest, given first' => ['192.0.2.7', [11 => '192.0.2.0/24', 10 => '192.0.0.0/16'], [11]],
			'a host' => ['192.0.2.7', [11 => '192.0.2.0/24', 12 => '192.0.2.7/32'], [12]],
			'ties: all' => ['192.0.2.7', [11 => '192.0.2.0/24', 13 => '192.0.2.0/24', 10 => '192.0.0.0/16'], [11, 13]],
			'everything' => ['192.0.2.7', [14 => '0.0.0.0/0'], [14]],
			'within a byte' => ['192.0.2.7', [15 => '192.0.2.0/29', 16 => '192.0.2.8/29'], [15]],
			'the other half' => ['192.0.2.7', [17 => '192.0.2.128/25', 18 => '192.0.2.0/25'], [18]],
			'none holds it' => ['192.0.2.7', [17 => '192.0.2.128/25', 19 => '198.51.100.0/24'], []],
			// past 32 on the address itself: never compared beyond its bytes
			'malformed' => ['192.0.2.7', [20 => 'example.org', 21 => '192.0.2.0', 22 => '192.0.2.7/33', 23 => '192.0.2.0/x', 24 => '192.0.2.0/-1', 25 => '192.0.2.0/'], []],
			'a second slash' => ['192.0.2.7', [31 => '192.0.2.0/24/8'], []],
			'another family' => ['192.0.2.7', [26 => '2001:db8::/32', 27 => '::/0'], []],
			'IPv6' => ['2001:db8::1', [26 => '2001:db8::/32', 28 => '2001:db8::/64', 29 => '2001:db8::1/128', 30 => '2001:db8::1/129'], [29]],
			'not an IP' => ['example.org', [14 => '0.0.0.0/0'], []],
		];
	}

	/**
	 * @param array<int, string> $networks
	 * @param list<int> $ids
	 */
	#[DataProvider('prefixes')]
	public function testLongestPrefix(string $ip, array $networks, array $ids): void {
		$this->assertSame(
			array_map(fn (int $id): array => [MapHitService::ENTRY_CUSTOM, $id], $ids),
			self::call('longestPrefix', $ip, $networks, MapHitService::ENTRY_CUSTOM)
		);
	}

	// the local rule symbols, those a map names; not fuzzy ones
	public function testWantedSymbols(): void {
		Config::set('local_symbol_patterns', ['LOCAL_*', 'RQWATCH_*']);
		// each one left out comes before one taken: the rest are still read
		$symbols = json_encode([
			'LOCAL_NOT_AN_OBJECT' => 'x',
			'LOCAL_X' => ['name' => 'LOCAL_X', 'score' => '1.5', 'options' => [['nested'], 'a', 2, null, true]],
			'OTHER' => ['score' => 1],
			'MAP_SYM' => ['score' => 2],
			'RQWATCH_FUZZY_DENIED' => ['score' => 5],
			'bad-name' => ['score' => 1],
			'K' => ['name' => 'LOCAL_NAMED', 'score' => 'many', 'options' => 'one'],
			'RQWATCH_FUZZY_WHITE' => ['score' => -5],
		], JSON_THROW_ON_ERROR);
		$this->assertSame([
			'LOCAL_X' => ['score' => 1.5, 'options' => ['a', 2, true]],
			'MAP_SYM' => ['score' => 2.0, 'options' => []],
			'LOCAL_NAMED' => ['score' => null, 'options' => ['one']],
		], self::call('wantedSymbols', $symbols, ['MAP_SYM' => ['wl_ip'], 'OTHER_MAP_SYM' => ['bl_ip']]));
		$this->assertSame([], $this->log->lines);
	}

	// rspamd may list the symbols: each names itself
	public function testWantedSymbolsListed(): void {
		Config::set('local_symbol_patterns', ['LOCAL_*']);
		$this->assertSame(
			['LOCAL_Y' => ['score' => null, 'options' => []]],
			self::call('wantedSymbols', '[{"score":3},{"name":"LOCAL_Y"}]', [])
		);
	}

	/** @return array<string, array{string}> */
	public static function noSymbols(): array {
		return ['empty' => [''], 'not JSON' => ['{'], 'null' => ['null'], 'no symbols' => ['[]'], 'text' => ['"LOCAL_X"']];
	}

	#[DataProvider('noSymbols')]
	public function testNoWantedSymbols(string $symbols): void {
		$this->assertSame([], self::call('wantedSymbols', $symbols, ['LOCAL_X' => ['wl_ip']]));
	}

	// map hits off: the mail's symbols are not even looked up
	public function testRecordHitsOff(): void {
		Config::set('local_symbol_patterns', ['LOCAL_*']);
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, null, new MigrationStatus(null, $this->log), null, false));
		(new MapHitService())->recordHits(1, '{"LOCAL_X":{"score":1}}', 'Q1');
		$this->assertSame([], $this->log->lines);
	}

	public function testEscapeLike(): void {
		$this->assertSame('a\\%b\\_c\\\\d', self::call('escapeLike', 'a%b_c\\d'));
	}

	public function testWindowCounts(): void {
		$this->assertSame(
			'COUNT(DISTINCT CASE WHEN h.created_day >= CURDATE() - INTERVAL 0 DAY THEN h.mail_log_id END) AS d1, '
			. 'COUNT(DISTINCT CASE WHEN h.created_day >= CURDATE() - INTERVAL 6 DAY THEN h.mail_log_id END) AS d7, '
			. 'COUNT(DISTINCT CASE WHEN h.created_day >= CURDATE() - INTERVAL 30 DAY THEN h.mail_log_id END) AS d31',
			self::call('windowCounts', 'h')
		);
	}

	public function testWindowValues(): void {
		$row = new stdClass();
		$row->d1 = 2;
		$row->d7 = '5';
		$row->d31 = null;
		$this->assertSame([[1 => 0, 7 => 0, 31 => 0], [1 => 2, 7 => 5, 31 => 0]], [self::call('windowValues', null), self::call('windowValues', $row)]);
	}

	/** @return array<string, array{?int, list<string>, string, list<int>}> days, the column given, where, bindings */
	public static function dayWindows(): array {
		return [
			'no window' => [null, [], '', []],
			'today' => [1, [], ' where created_day >= CURDATE() - INTERVAL ? DAY', [0]],
			'7 days' => [7, [], ' where created_day >= CURDATE() - INTERVAL ? DAY', [6]],
			'on a column' => [31, ['h.created_day'], ' where h.created_day >= CURDATE() - INTERVAL ? DAY', [30]],
		];
	}

	/**
	 * @param list<string> $column
	 * @param list<int> $bindings
	 */
	#[DataProvider('dayWindows')]
	public function testDayWindow(?int $days, array $column, string $where, array $bindings): void {
		$db = new RecordingDatabase($this->createStub(PDO::class));
		try {
			$query = $db->capsule->getConnection()->table('mail_log_symbol_hits');
			(new ReflectionMethod(MapHitService::class, 'dayWindow'))->invoke(null, $query, $days, ...$column);
			$this->assertSame(['select * from `mail_log_symbol_hits`' . $where, $bindings], [$query->toSql(), $query->getBindings()]);
		} finally {
			RecordingDatabase::forget();
		}
	}

	/** @return array<string, array{string, list<int>, ?int, ?string, string, list<int|string>}> model, symbol ids, days, day, SQL, bindings */
	public static function sources(): array {
		$hits = 'SELECT h.created_day, h.mail_log_id FROM mail_log_symbol_hits h';
		return [
			'symbols and entries, last 7 days' => ['MapCustom', [3, 4], 7, null,
				"{$hits} WHERE h.symbol_id IN (?,?) AND h.created_day >= CURDATE() - INTERVAL ? DAY UNION ALL "
				. "{$hits} JOIN maps_custom e ON e.id = h.entry_id WHERE h.entry_model = ? AND e.map_name = ? AND h.created_day >= CURDATE() - INTERVAL ? DAY",
				[3, 4, 6, 2, 'wl', 6]],
			'entries only, no window' => ['MapCombined', [], null, null,
				"{$hits} JOIN maps_combined e ON e.id = h.entry_id WHERE h.entry_model = ? AND e.map_name = ?",
				[1, 'wl']],
			'one day' => ['MapCombined', [5], 7, '2026-11-26',
				"{$hits} WHERE h.symbol_id IN (?) AND h.created_day = ? UNION ALL "
				. "{$hits} JOIN maps_combined e ON e.id = h.entry_id WHERE h.entry_model = ? AND e.map_name = ? AND h.created_day = ?",
				[5, '2026-11-26', 1, 'wl', '2026-11-26']],
		];
	}

	/**
	 * @param list<int> $symbolIds
	 * @param list<int|string> $bindings
	 */
	#[DataProvider('sources')]
	public function testMapHitsSource(string $model, array $symbolIds, ?int $days, ?string $day, string $sql, array $bindings): void {
		$this->assertSame([$sql, $bindings], self::call('mapHitsSource', 'wl', $model, $symbolIds, $days, $day));
	}
}
