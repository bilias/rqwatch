<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 MapService's map entries on RecordingDatabase. An entry is found in its
 map, a user's within their scope (their own, for their addresses), a
 custom map's by an admin only. A user's quota (USER_MAP_MAX_ENTRIES, 0
 none) counts their entries in a map; an admin, or a failing count (logged),
 is never over it. An entry exists by its fields' values, a custom map's by
 any of its lines. Adding, changing, deleting or toggling an entry marks
 the map changed and writes its file; any failure is false. A combined
 entry's values are trimmed and lower case; a user's must name one of
 their addresses as rcpt_to (in any case or spacing, as stored), and a
 user changes their own entries only.
 Changing an entry's key detaches its hits. A custom map is given one entry
 per line, '#' first for a disabled one. A user deletes or toggles an
 admin's entry only with USER_CAN_DEL_ADMIN_MAP_ENTRIES; deleting all of a
 map's entries that are theirs fails when there are none. DEBUG_SEARCH_SQL
 logs the lookups.
*/

namespace Tests\Unit\Services;

use App\Configuration\Config;
use App\Inventory\Migrations;
use App\Models\MapCombined;
use App\Models\MapCustom;
use App\Services\MapService;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

final class MapEntriesTest extends MapDatabaseTestCase
{
	private const string MAP = 'mail_from_rcpt_to_whitelist';
	private const array FIELDS = ['mail_from', 'rcpt_to'];
	private const string COMBINED = 'select `id`, `user_id`, `disabled`, `created_at`, `updated_at`, `mail_from`, `rcpt_to` from `maps_combined`'
		. ' where `map_name` = ? and `mail_from` is not null and `rcpt_to` is not null';
	private const string USER_SCOPE = ' and `user_id` = ? and `rcpt_to` in (?, ?)';
	private const array USER_BINDINGS = [7, 'alice@example.org', 'alias@example.org'];
	private const string CUSTOM = 'select `id`, `map_name`, `pattern`, `disabled`, `score`, `created_at`, `updated_at` from `maps_custom` where `map_name` = ?';
	private const string ORDER = ' order by `updated_at` desc';

	/** @var list<array<string, mixed>> the enabled entries a map file is written from */
	private array $enabled = [['id' => 11, 'mail_from' => 'a@example.net', 'rcpt_to' => 'alice@example.org']];

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->status->setMigrationState(Migrations::MAP_HITS, Migrations::STATUS_COMPLETED);
		$this->answers = [['`disabled` = ?', fn (): array => $this->enabled]];
	}

	private static function entry(int $userId = 7, string $map = self::MAP): MapCombined {
		return (new MapCombined())->newFromBuilder(['id' => 11, 'user_id' => $userId, 'map_name' => $map]);
	}

	private static function customEntry(string $map = 'phish', int $disabled = 0): MapCustom {
		return (new MapCustom())->newFromBuilder(['id' => 21, 'map_name' => $map, 'disabled' => $disabled]);
	}

	/** @return list<string> the first words of each change made */
	private function changes(): array {
		return array_map(fn (array $change): string => implode(' ', array_slice(explode(' ', $change[0]), 0, 3)), $this->db->changes);
	}

	public function testFindMapEntry(): void {
		$this->answers[] = ['limit 1', [['id' => 11, 'map_name' => self::MAP]]];
		$this->assertSame(11, self::user()->findMapEntry('MapCombined', self::MAP, 11)?->id);
		$this->assertSame(11, self::admin()->findMapEntry('MapCustom', 'phish', 11)?->id);
		$this->assertSame([
			['select * from `maps_combined` where `map_name` = ? and `maps_combined`.`id` = ?' . self::USER_SCOPE . ' limit 1', [self::MAP, 11, ...self::USER_BINDINGS]],
			['select * from `maps_custom` where `map_name` = ? and `maps_custom`.`id` = ? limit 1', ['phish', 11]],
		], $this->db->selects);
	}

	// a custom map's entries are an admin's only; an unknown model has none
	public function testFindMapEntryNone(): void {
		$this->assertNull(self::user()->findMapEntry('MapCustom', 'phish', 11));
		$this->assertNull(self::admin()->findMapEntry('MapOther', 'phish', 11));
		$this->assertSame([], $this->db->selects);
		$this->assertNull(self::admin()->findMapEntry('MapCombined', self::MAP, 11));
	}

	/** @return array<string, array{int, int, bool}> USER_MAP_MAX_ENTRIES, the user's entries, reached */
	public static function quotas(): array {
		return ['below' => [3, 2, false], 'at' => [3, 3, true], 'over' => [1, 2, true]];
	}

	#[DataProvider('quotas')]
	public function testUserMapQuota(int $max, int $entries, bool $reached): void {
		Config::set('USER_MAP_MAX_ENTRIES', $max);
		$this->answers = [['count(*)', [['aggregate' => $entries]]]];
		$this->assertSame($reached, self::user()->userMapQuotaReached(self::MAP));
		$this->assertSame([['select count(*) as aggregate from `maps_combined` where `map_name` = ? and `user_id` = ?', [self::MAP, 7]]], $this->db->selects);
	}

	// no quota, or an admin's: nothing counted
	public function testUserMapQuotaNone(): void {
		Config::set('USER_MAP_MAX_ENTRIES', 0);
		$this->assertFalse(self::user()->userMapQuotaReached(self::MAP));
		Config::set('USER_MAP_MAX_ENTRIES', null);
		$this->assertFalse(self::user()->userMapQuotaReached(self::MAP));
		Config::set('USER_MAP_MAX_ENTRIES', 1);
		$this->assertFalse(self::admin()->userMapQuotaReached(self::MAP));
		$this->assertSame([], $this->db->selects);
	}

	public function testUserMapQuotaFailure(): void {
		Config::set('USER_MAP_MAX_ENTRIES', 1);
		$this->db->answer = fn (): never => throw new RuntimeException('server has gone away');
		$this->assertFalse(self::user()->userMapQuotaReached(self::MAP));
		$this->assertSame(['error [userMapQuotaReached] server has gone away'], $this->log->lines);
	}

	public function testMapEntryExists(): void {
		$this->answers = [['exists', [['exists' => 1]]]];
		$this->assertTrue(self::user()->mapEntryExists('MapCombined', self::MAP, self::FIELDS, ['mail_from' => 'a@example.net', 'rcpt_to' => 'alice@example.org']));
		$this->assertTrue(self::admin()->mapEntryExists('MapCustom', 'phish', ['pattern'], ['pattern' => " a.org\r\nb.org \r\r a.org\n"]));
		$this->assertSame([
			['select exists(' . self::COMBINED . ' and `mail_from` = ? and `rcpt_to` = ?' . self::USER_SCOPE . self::ORDER . ') as `exists`',
				[self::MAP, 'a@example.net', 'alice@example.org', ...self::USER_BINDINGS]],
			['select exists(' . self::CUSTOM . ' and `pattern` in (?, ?)' . self::ORDER . ') as `exists`', ['phish', 'a.org', 'b.org']],
		], $this->db->selects);
		$this->assertSame([], $this->log->lines);
	}

	public function testMapEntryNotExists(): void {
		$this->answers = [['exists', [['exists' => 0]]]];
		$this->setEnv('DEBUG_SEARCH_SQL', 'true');
		$this->assertFalse(self::admin()->mapEntryExists('MapCustom', 'phish', ['pattern'], ['pattern' => 'a.org']));
		$this->assertSame(['info ' . str_replace('?', '"phish"', self::CUSTOM) . ' and `pattern` in ("a.org")' . self::ORDER], $this->log->lines);
	}

	public function testMapEntryExistsUnknownModel(): void {
		$this->expectExceptionObject(new RuntimeException('Unknown map model'));
		self::admin()->mapEntryExists('MapOther', 'phish', ['pattern'], ['pattern' => 'a.org']);
	}

	// trimmed and lower case, as the user's, the map marked changed and written
	public function testAddMapCombinedEntry(): void {
		$this->assertTrue(self::user()->addMapCombinedEntry(self::MAP, self::FIELDS, ['mail_from' => ' A@Example.NET ', 'rcpt_to' => 'Alias@example.org']));
		$row = $this->db->rows('maps_combined')[0] ?? [];
		$this->assertSame(['a@example.net', 'alias@example.org', self::MAP, 7], [$row['mail_from'] ?? null, $row['rcpt_to'] ?? null, $row['map_name'] ?? null, $row['user_id'] ?? null]);
		$this->assertSame([self::MAP], $this->changedMaps());
		$this->assertSame(['a@example.net|alice@example.org'], $this->mapFile(self::MAP));
		$this->assertSame([], $this->log->lines);
	}

	// the user's own address too
	public function testAddMapCombinedEntryOwnAddress(): void {
		$this->assertTrue(self::user()->addMapCombinedEntry(self::MAP, self::FIELDS, ['mail_from' => 'a@example.net', 'rcpt_to' => 'alice@example.org']));
		$this->assertSame('alice@example.org', $this->db->rows('maps_combined')[0]['rcpt_to'] ?? null);
	}

	/** @return array<string, array{?string, list<string>, string}> email, aliases, the rcpt_to given */
	public static function storedAddresses(): array {
		return [
			'email with capitals' => [' Alice@Example.ORG', [], 'Alice@example.org'],
			'alias with capitals' => ['alice@example.org', ['Alias@Example.org '], 'alias@example.org '],
			'no email, an alias' => [null, ['alias@example.org'], 'alias@example.org'],
		];
	}

	/** @param list<string> $aliases */
	#[DataProvider('storedAddresses')]
	public function testAddMapCombinedEntryStoredAddress(?string $email, array $aliases, string $rcptTo): void {
		$service = new MapService(['is_admin' => false, 'username' => 'alice', 'user_id' => 7, 'email' => $email, 'user_aliases' => $aliases]);
		$this->assertTrue($service->addMapCombinedEntry(self::MAP, self::FIELDS, ['mail_from' => 'a@example.net', 'rcpt_to' => $rcptTo]));
		$this->assertSame([], $this->log->lines);
	}

	/** @param list<string> $aliases */
	#[DataProvider('storedAddresses')]
	public function testUpdateMapCombinedEntryStoredAddress(?string $email, array $aliases, string $rcptTo): void {
		$this->answers[] = ['where `id` = ? limit 1', [['mail_from' => 'a@example.net', 'rcpt_to' => 'x@example.org']]];
		$service = new MapService(['is_admin' => false, 'username' => 'alice', 'user_id' => 7, 'email' => $email, 'user_aliases' => $aliases]);
		$this->assertTrue($service->updateMapCombinedEntry(self::MAP, self::FIELDS, self::entry(), ['mail_from' => 'a@example.net', 'rcpt_to' => $rcptTo]));
		$this->assertSame(strtolower(trim($rcptTo)), $this->db->changes[0][1][1] ?? null);
	}

	// an admin's for any rcpt_to
	public function testAddMapCombinedEntryAdmin(): void {
		$this->assertTrue(self::admin()->addMapCombinedEntry(self::MAP, self::FIELDS, ['mail_from' => 'a@example.net', 'rcpt_to' => 'bob@example.org']));
		$this->assertSame(1, $this->db->rows('maps_combined')[0]['user_id'] ?? null);
	}

	/** @return array<string, array{array<string, string>, list<string>}> data, the log */
	public static function addRefused(): array {
		return [
			'no data' => [[], ['error Empty map data']],
			'no rcpt_to' => [['mail_from' => 'a@example.net'], ['warning [addMapCombinedEntry] Missing rcpt_to in data for user alice']],
			'rcpt_to empty' => [['mail_from' => 'a@example.net', 'rcpt_to' => ' '], ['warning [addMapCombinedEntry] Missing rcpt_to in data for user alice']],
			"rcpt_to '0'" => [['mail_from' => 'a@example.net', 'rcpt_to' => '0'], ['warning [addMapCombinedEntry] Missing rcpt_to in data for user alice']],
			'not theirs' => [['mail_from' => 'a@example.net', 'rcpt_to' => 'bob@example.org'], ["warning rcpt_to value 'bob@example.org' is not allowed for user alice"]],
		];
	}

	/**
	 * @param array<string, string> $data
	 * @param list<string> $log
	 */
	#[DataProvider('addRefused')]
	public function testAddMapCombinedEntryRefused(array $data, array $log): void {
		$this->assertFalse(self::user()->addMapCombinedEntry(self::MAP, self::FIELDS, $data));
		$this->assertSame([[], [], $log], [$this->db->rows('maps_combined'), $this->changedMaps(), $this->log->lines]);
	}

	/** @return array<string, array{?int}> */
	public static function noUserIds(): array {
		return ['none' => [null], 'zero' => [0]];
	}

	#[DataProvider('noUserIds')]
	public function testAddMapCombinedEntryNoUser(?int $userId): void {
		$service = new MapService(['is_admin' => true, 'user_id' => $userId]);
		$this->assertFalse($service->addMapCombinedEntry(self::MAP, self::FIELDS, ['mail_from' => 'a@example.net', 'rcpt_to' => 'b@example.org']));
		$this->assertSame(['warning [addMapCombinedEntry] empty user_id'], $this->log->lines);
	}

	// a map without rcpt_to: a user's entry is not limited to their addresses
	public function testAddMapCombinedEntryNoRcptTo(): void {
		$this->assertTrue(self::user()->addMapCombinedEntry('mail_from_whitelist', ['mail_from'], ['mail_from' => 'a@example.net']));
	}

	public function testAddMapCombinedEntryFails(): void {
		$this->db->failInsertInto['maps_combined'] = new RuntimeException('Duplicate entry');
		$this->assertFalse(self::admin()->addMapCombinedEntry(self::MAP, self::FIELDS, ['mail_from' => 'a@example.net', 'rcpt_to' => 'b@example.org']));
		$this->assertSame(["error [addMapCombinedEntry] Query save error: Duplicate entry\n"], $this->log->lines);
		$this->assertSame([], $this->changedMaps());
	}

	// the entry saved, its file not written: false
	public function testAddMapCombinedEntryNoFile(): void {
		Config::set('MAP_DIR', "{$this->dir}/none");
		$this->assertFalse(self::admin()->addMapCombinedEntry(self::MAP, self::FIELDS, ['mail_from' => 'a@example.net', 'rcpt_to' => 'b@example.org']));
		$this->assertSame([self::MAP], $this->changedMaps());
		$this->assertSame(["error MAP_DIR not writable: {$this->dir}/none"], $this->log->lines);
	}

	// a changed key detaches the entry's hits, once
	public function testUpdateMapCombinedEntry(): void {
		$this->answers[] = ['where `id` = ? limit 1', [['mail_from' => 'A@example.net ', 'rcpt_to' => 'alias@example.org']]];
		$this->db->affect = fn (): int => 1;
		$this->assertTrue(self::user()->updateMapCombinedEntry(self::MAP, self::FIELDS, self::entry(), ['mail_from' => ' B@example.net', 'rcpt_to' => 'Alice@example.org']));
		$this->assertSame(['select `mail_from`, `rcpt_to` from `maps_combined` where `id` = ? limit 1', [11]], $this->db->selects[0] ?? null);
		$update = $this->db->changes[0] ?? ['', []];
		$this->assertSame('update `maps_combined` set `mail_from` = ?, `rcpt_to` = ?, `maps_combined`.`updated_at` = ? where `id` = ?', $update[0]);
		$this->assertSame(['b@example.net', 'alice@example.org', 11], [$update[1][0] ?? null, $update[1][1] ?? null, $update[1][3] ?? null]);
		$this->assertSame(['update `maps_combined` set', 'UPDATE IGNORE `mail_log_symbol_hits`', 'DELETE FROM `mail_log_symbol_hits`'], $this->changes());
		$this->assertSame([self::MAP], $this->changedMaps());
		$this->assertSame(['a@example.net|alice@example.org'], $this->mapFile(self::MAP));
		$this->assertSame(['info [MapHitService] entry 1:11 key changed, detached 2 hit(s)'], $this->log->lines);
	}

	// to the user's alias
	public function testUpdateMapCombinedEntryToAlias(): void {
		$this->answers[] = ['where `id` = ? limit 1', [['mail_from' => 'a@example.net', 'rcpt_to' => 'alice@example.org']]];
		$this->assertTrue(self::user()->updateMapCombinedEntry(self::MAP, self::FIELDS, self::entry(), ['mail_from' => 'a@example.net', 'rcpt_to' => 'alias@example.org']));
		$this->assertSame('alias@example.org', $this->db->changes[0][1][1] ?? null);
	}

	// the same key, but for case and spaces: its hits kept; an admin's change of another's entry
	public function testUpdateMapCombinedEntrySameKey(): void {
		$this->answers[] = ['where `id` = ? limit 1', [['mail_from' => ' A@example.net', 'rcpt_to' => 'Alice@example.org ']]];
		$this->assertTrue(self::admin()->updateMapCombinedEntry(self::MAP, self::FIELDS, self::entry(), ['mail_from' => 'a@example.net', 'rcpt_to' => 'alice@example.org']));
		$this->assertSame(['update `maps_combined` set'], $this->changes());
	}

	/** @return array<string, array{MapCombined, array<string, string>, list<string>, list<string>}> entry, data, fields, the log */
	public static function updateRefused(): array {
		$data = ['mail_from' => 'a@example.net', 'rcpt_to' => 'alice@example.org'];
		return [
			'no data' => [self::entry(), [], self::FIELDS, ['error Empty or invalid map data']],
			'another map\'s entry' => [self::entry(map: 'mail_from_rcpt_to_blacklist'), $data, self::FIELDS, ['error Empty or invalid map data']],
			'no rcpt_to' => [self::entry(), ['mail_from' => 'a@example.net'], self::FIELDS, ['warning [updateMapCombinedEntry] Missing rcpt_to in data for user alice']],
			"rcpt_to '0'" => [self::entry(), ['mail_from' => 'a@example.net', 'rcpt_to' => '0'], self::FIELDS, ['warning [updateMapCombinedEntry] Missing rcpt_to in data for user alice']],
			'not theirs' => [self::entry(), ['mail_from' => 'a', 'rcpt_to' => 'bob@example.org'], self::FIELDS, ["warning rcpt_to value 'bob@example.org' is not allowed for user alice"]],
			'another user\'s entry' => [self::entry(8), $data, self::FIELDS,
				['warning [updateMapCombinedEntry] alice tried to update map entry 11 for map mail_from_rcpt_to_whitelist without permission']],
			'no fields' => [self::entry(), $data, [], ['warning [updateMapCombinedEntry] Empty new data']],
		];
	}

	/**
	 * @param array<string, string> $data
	 * @param list<string> $fields
	 * @param list<string> $log
	 */
	#[DataProvider('updateRefused')]
	public function testUpdateMapCombinedEntryRefused(MapCombined $entry, array $data, array $fields, array $log): void {
		$this->assertFalse(self::user()->updateMapCombinedEntry(self::MAP, $fields, $entry, $data));
		$this->assertSame([[], [], $log], [$this->db->changes, $this->changedMaps(), $this->log->lines]);
	}

	#[DataProvider('noUserIds')]
	public function testUpdateMapCombinedEntryNoUser(?int $userId): void {
		$service = new MapService(['is_admin' => true, 'user_id' => $userId]);
		$this->assertFalse($service->updateMapCombinedEntry(self::MAP, self::FIELDS, self::entry(), ['mail_from' => 'a', 'rcpt_to' => 'b']));
		$this->assertSame(['warning [updateMapCombinedEntry] empty user_id'], $this->log->lines);
	}

	// a map without rcpt_to: a user's entry is not limited to their addresses
	public function testUpdateMapCombinedEntryNoRcptTo(): void {
		$this->answers[] = ['where `id` = ? limit 1', [['mail_from' => 'a@example.net']]];
		$this->assertTrue(self::user()->updateMapCombinedEntry('mail_from_whitelist', ['mail_from'], self::entry(map: 'mail_from_whitelist'), ['mail_from' => 'b@example.net']));
		$this->assertSame([], $this->log->lines);
	}

	public function testUpdateMapCombinedEntryGone(): void {
		$this->assertFalse(self::admin()->updateMapCombinedEntry(self::MAP, self::FIELDS, self::entry(), ['mail_from' => 'a', 'rcpt_to' => 'b']));
		$this->assertSame([[], ['warning [updateMapCombinedEntry] map entry 11 not found']], [$this->db->changes, $this->log->lines]);
	}

	public function testUpdateMapCombinedEntryFails(): void {
		$this->db->answer = fn (): never => throw new RuntimeException('server has gone away');
		$this->assertFalse(self::admin()->updateMapCombinedEntry(self::MAP, self::FIELDS, self::entry(), ['mail_from' => 'a', 'rcpt_to' => 'b']));
		$this->assertSame(["error [updateMapCombinedEntry] Query save error: server has gone away\n"], $this->log->lines);
	}

	public function testUpdateMapCombinedEntryNoFile(): void {
		$this->answers[] = ['where `id` = ? limit 1', [['mail_from' => 'a', 'rcpt_to' => 'b']]];
		Config::set('MAP_DIR', "{$this->dir}/none");
		$this->assertFalse(self::admin()->updateMapCombinedEntry(self::MAP, self::FIELDS, self::entry(), ['mail_from' => 'a', 'rcpt_to' => 'b']));
		$this->assertSame([self::MAP], $this->changedMaps());
	}

	// one entry a line, trimmed, blank lines left out, '#' first for a disabled one
	public function testAddMapCustomEntry(): void {
		$this->enabled = [['id' => 21, 'pattern' => 'a.org', 'score' => null]];
		$this->assertTrue(self::admin()->addMapCustomEntry('phish', " a.org \r\n\n#b.org \n"));
		$this->assertSame([
			['map_name' => 'phish', 'pattern' => 'a.org', 'disabled' => 0],
			['map_name' => 'phish', 'pattern' => 'b.org', 'disabled' => 1],
		], array_map(fn (array $row): array => ['map_name' => $row['map_name'] ?? null, 'pattern' => $row['pattern'] ?? null, 'disabled' => $row['disabled'] ?? null], $this->db->rows('maps_custom')));
		$this->assertSame(['phish'], $this->changedMaps());
		$this->assertSame(['a.org'], $this->mapFile('phish'));
	}

	public function testAddMapCustomEntryNothing(): void {
		$this->assertFalse(self::admin()->addMapCustomEntry('phish', ''));
		$this->assertSame(['error Empty map pattern'], $this->log->lines);
		$this->assertFalse(self::admin()->addMapCustomEntry('phish', " \n \r\n"));
		$this->assertSame([[], [], ['error Empty map pattern']], [$this->db->rows('maps_custom'), $this->changedMaps(), $this->log->lines]);
	}

	public function testAddMapCustomEntryFails(): void {
		$this->db->failInsertInto['maps_custom'] = new RuntimeException('Duplicate entry');
		$this->assertFalse(self::admin()->addMapCustomEntry('phish', 'a.org'));
		$this->assertSame([[], ["error [addMapCustomEntry] Query insert error: Duplicate entry\n"]], [$this->changedMaps(), $this->log->lines]);
	}

	public function testAddMapCustomEntryNoFile(): void {
		Config::set('MAP_DIR', "{$this->dir}/none");
		$this->assertFalse(self::admin()->addMapCustomEntry('phish', 'a.org'));
		$this->assertSame(['phish'], $this->changedMaps());
	}

	/** @return array<string, array{string, int, string, int|bool}> new pattern, the entry's disabled, the pattern and disabled stored (the entry's own, as cast) */
	public static function customUpdates(): array {
		return [
			'disabled by #' => ['#b.org', 0, 'b.org', 1],
			'enabled again' => ['b.org', 1, 'b.org', 0],
			'empty: as it was' => ['', 1, '', true],
			'only #' => ['#', 0, '', 1],
		];
	}

	#[DataProvider('customUpdates')]
	public function testUpdateMapCustomEntry(string $pattern, int $disabled, string $stored, int|bool $disabledStored): void {
		$this->answers[] = ['where `id` = ? limit 1', [['pattern' => 'a.org']]];
		$this->assertTrue(self::admin()->updateMapCustomEntry('phish', self::customEntry(disabled: $disabled), $pattern));
		$this->assertSame(['select `pattern` from `maps_custom` where `id` = ? limit 1', [21]], $this->db->selects[0] ?? null);
		$update = $this->db->changes[0] ?? ['', []];
		$this->assertSame('update `maps_custom` set `pattern` = ?, `disabled` = ?, `maps_custom`.`updated_at` = ? where `id` = ?', $update[0]);
		$this->assertSame([$stored, $disabledStored, 21], [$update[1][0] ?? null, $update[1][1] ?? null, $update[1][3] ?? null]);
		$this->assertSame(['update `maps_custom` set', 'UPDATE IGNORE `mail_log_symbol_hits`', 'DELETE FROM `mail_log_symbol_hits`'], $this->changes());
		$this->assertSame(['phish'], $this->changedMaps());
		$this->assertNotNull($this->mapFile('phish'));
	}

	// the same key, a score added: its hits kept
	public function testUpdateMapCustomEntrySameKey(): void {
		$this->answers[] = ['where `id` = ? limit 1', [['pattern' => 'a.org']]];
		$this->assertTrue(self::admin()->updateMapCustomEntry('phish', self::customEntry(), ' a.org 5'));
		$this->assertSame(['update `maps_custom` set'], $this->changes());
	}

	// another map's entry; one gone
	public function testUpdateMapCustomEntryRefused(): void {
		$this->assertFalse(self::admin()->updateMapCustomEntry('phish', self::customEntry('other'), 'a.org'));
		$this->assertFalse(self::admin()->updateMapCustomEntry('phish', self::customEntry(), 'a.org'));
		$this->assertSame([[], ['error Empty or invalid map data', 'warning [updateMapCustomEntry] map entry 21 not found']], [$this->db->changes, $this->log->lines]);
	}

	public function testUpdateMapCustomEntryFails(): void {
		$this->answers[] = ['where `id` = ? limit 1', [['pattern' => 'a.org']]];
		$this->db->affect = fn (): never => throw new RuntimeException('server has gone away');
		$this->assertFalse(self::admin()->updateMapCustomEntry('phish', self::customEntry(), 'b.org'));
		$this->assertSame(["error [updateMapCustomEntry] Query update error: server has gone away\n"], $this->log->lines);
	}

	public function testUpdateMapCustomEntryNoFile(): void {
		$this->answers[] = ['where `id` = ? limit 1', [['pattern' => 'a.org']]];
		Config::set('MAP_DIR', "{$this->dir}/none");
		$this->assertFalse(self::admin()->updateMapCustomEntry('phish', self::customEntry(), 'a.org'));
		$this->assertSame(['phish'], $this->changedMaps());
	}

	private const string ONE_COMBINED = self::COMBINED . self::USER_SCOPE . ' and `id` = ? and `user_id` = ?' . self::ORDER . ' limit 1';

	// a user's own entry only, unless USER_CAN_DEL_ADMIN_MAP_ENTRIES
	public function testDelMapEntry(): void {
		$this->answers[] = ['`id` = ?', [['id' => 11, 'disabled' => 0]]];
		$this->setEnv('DEBUG_SEARCH_SQL', 'true');
		$this->assertTrue(self::user()->delMapEntry('MapCombined', self::MAP, self::FIELDS, 11));
		$this->assertSame([self::ONE_COMBINED, [self::MAP, ...self::USER_BINDINGS, 11, 7]], $this->db->selects[0] ?? null);
		$this->assertSame([['delete from `maps_combined` where `id` = ?', [11]]], $this->db->changes);
		$this->assertSame([self::MAP], $this->changedMaps());
		$this->assertSame(['a@example.net|alice@example.org'], $this->mapFile(self::MAP));
		$this->assertSame('info ' . self::COMBINED_LOGGED . self::USER_LOGGED . ' and `id` = "11" and `user_id` = "7" order by `updated_at` desc', $this->log->lines[0] ?? null);
	}

	private const string USER_LOGGED = ' and `mail_from` is not null and `rcpt_to` is not null and `user_id` = "7" and `rcpt_to` in ("alice@example.org", "alias@example.org")';

	private const string COMBINED_LOGGED = 'select `id`, `user_id`, `disabled`, `created_at`, `updated_at`, `mail_from`, `rcpt_to` from `maps_combined` where `map_name` = "mail_from_rcpt_to_whitelist"';

	/** @return array<string, array{bool, ?bool, string, string, list<mixed>}> an admin's, USER_CAN_DEL_ADMIN_MAP_ENTRIES, model, the lookup, its bindings */
	public static function entryLookups(): array {
		return [
			'a user may delete an admin\'s' => [false, true, 'MapCombined',
				self::COMBINED . self::USER_SCOPE . ' and `id` = ?' . self::ORDER . ' limit 1', [self::MAP, ...self::USER_BINDINGS, 11]],
			'an admin' => [true, false, 'MapCombined', self::COMBINED . ' and `id` = ?' . self::ORDER . ' limit 1', [self::MAP, 11]],
			'a custom map' => [true, false, 'MapCustom', self::CUSTOM . ' and `id` = ?' . self::ORDER . ' limit 1', [self::MAP, 11]],
		];
	}

	/** @param list<mixed> $bindings */
	#[DataProvider('entryLookups')]
	public function testDelMapEntryLookup(bool $admin, ?bool $userCanDel, string $model, string $sql, array $bindings): void {
		Config::set('USER_CAN_DEL_ADMIN_MAP_ENTRIES', $userCanDel);
		$service = $admin ? self::admin() : self::user();
		$this->answers[] = ['`id` = ?', [['id' => 11]]];
		$this->assertTrue($service->delMapEntry($model, self::MAP, self::FIELDS, 11));
		$this->assertSame([$sql, $bindings], $this->db->selects[0] ?? null);
		$this->assertSame([], $this->log->lines);
	}

	// not found, a user's custom map entry, an unknown model: nothing deleted
	public function testDelMapEntryNone(): void {
		$this->assertFalse(self::admin()->delMapEntry('MapCombined', self::MAP, self::FIELDS, 11));
		$this->assertFalse(self::user()->delMapEntry('MapCustom', 'phish', ['pattern'], 11));
		$this->assertFalse(self::admin()->delMapEntry('MapOther', 'phish', ['pattern'], 11));
		$this->assertSame([[], []], [$this->db->changes, $this->changedMaps()]);
		$this->assertCount(1, $this->db->selects);
	}

	public function testDelMapEntryNoFile(): void {
		$this->answers[] = ['`id` = ?', [['id' => 11]]];
		Config::set('MAP_DIR', "{$this->dir}/none");
		$this->assertFalse(self::admin()->delMapEntry('MapCombined', self::MAP, self::FIELDS, 11));
		$this->assertSame([self::MAP], $this->changedMaps());
	}

	public function testDelMapAllEntries(): void {
		$this->db->affect = fn (): int => 2;
		$this->setEnv('DEBUG_SEARCH_SQL', 'true');
		$this->assertTrue(self::user()->delMapAllEntries('MapCombined', self::MAP, self::FIELDS));
		$this->assertSame([[
			'delete from `maps_combined` where `map_name` = ? and `mail_from` is not null and `rcpt_to` is not null' . self::USER_SCOPE . ' and `user_id` = ?' . self::ORDER,
			[self::MAP, ...self::USER_BINDINGS, 7],
		]], $this->db->changes);
		$this->assertSame([self::MAP], $this->changedMaps());
		$this->assertNotNull($this->mapFile(self::MAP));
		$this->assertSame('info ' . self::COMBINED_LOGGED . self::USER_LOGGED . ' and `user_id` = "7" order by `updated_at` desc', $this->log->lines[0] ?? null);
	}

	/** @return array<string, array{bool, ?bool, string, string, list<mixed>}> */
	public static function allEntries(): array {
		return [
			'a user may delete an admin\'s' => [false, true, 'MapCombined',
				'delete from `maps_combined` where `map_name` = ? and `mail_from` is not null and `rcpt_to` is not null' . self::USER_SCOPE . self::ORDER, [self::MAP, ...self::USER_BINDINGS]],
			'an admin' => [true, false, 'MapCombined',
				'delete from `maps_combined` where `map_name` = ? and `mail_from` is not null and `rcpt_to` is not null' . self::ORDER, [self::MAP]],
			'a custom map' => [true, false, 'MapCustom', 'delete from `maps_custom` where `map_name` = ?' . self::ORDER, [self::MAP]],
		];
	}

	/** @param list<mixed> $bindings */
	#[DataProvider('allEntries')]
	public function testDelMapAllEntriesOf(bool $admin, ?bool $userCanDel, string $model, string $sql, array $bindings): void {
		Config::set('USER_CAN_DEL_ADMIN_MAP_ENTRIES', $userCanDel);
		$service = $admin ? self::admin() : self::user();
		$this->db->affect = fn (): int => 1;
		$this->assertTrue($service->delMapAllEntries($model, self::MAP, self::FIELDS));
		$this->assertSame([[$sql, $bindings]], $this->db->changes);
		$this->assertSame([], $this->log->lines);
	}

	// none deleted, a user's custom map, an unknown model: false, no map changed
	public function testDelMapAllEntriesNone(): void {
		$this->assertFalse(self::admin()->delMapAllEntries('MapCombined', self::MAP, self::FIELDS));
		$this->assertFalse(self::user()->delMapAllEntries('MapCustom', 'phish', ['pattern']));
		$this->assertFalse(self::admin()->delMapAllEntries('MapOther', 'phish', ['pattern']));
		$this->assertSame([1, []], [count($this->db->changes), $this->changedMaps()]);
	}

	public function testDelMapAllEntriesNoFile(): void {
		$this->db->affect = fn (): int => 1;
		Config::set('MAP_DIR', "{$this->dir}/none");
		$this->assertFalse(self::admin()->delMapAllEntries('MapCombined', self::MAP, self::FIELDS));
		$this->assertSame([self::MAP], $this->changedMaps());
	}

	/** @return array<string, array{int, bool}> disabled before, after */
	public static function toggles(): array {
		return ['disabled' => [0, true], 'enabled' => [1, false]];
	}

	#[DataProvider('toggles')]
	public function testToggleMapEntry(int $before, bool $after): void {
		$this->answers[] = ['`id` = ?', [['id' => 11, 'disabled' => $before]]];
		$this->setEnv('DEBUG_SEARCH_SQL', 'true');
		$this->assertTrue(self::user()->toggleMapEntry('MapCombined', self::MAP, self::FIELDS, 11));
		$this->assertSame([self::ONE_COMBINED, [self::MAP, ...self::USER_BINDINGS, 11, 7]], $this->db->selects[0] ?? null);
		$update = $this->db->changes[0] ?? ['', []];
		$this->assertSame(['update `maps_combined` set `disabled` = ?, `maps_combined`.`updated_at` = ? where `id` = ?', $after, 11], [$update[0], $update[1][0] ?? null, $update[1][2] ?? null]);
		$this->assertSame([self::MAP], $this->changedMaps());
		$this->assertNotNull($this->mapFile(self::MAP));
		$this->assertSame('info ' . self::COMBINED_LOGGED . self::USER_LOGGED . ' and `id` = "11" and `user_id` = "7" order by `updated_at` desc', $this->log->lines[0] ?? null);
	}

	/** @param list<mixed> $bindings */
	#[DataProvider('entryLookups')]
	public function testToggleMapEntryLookup(bool $admin, ?bool $userCanDel, string $model, string $sql, array $bindings): void {
		Config::set('USER_CAN_DEL_ADMIN_MAP_ENTRIES', $userCanDel);
		$service = $admin ? self::admin() : self::user();
		$this->answers[] = ['`id` = ?', [['id' => 11, 'disabled' => 0]]];
		$this->assertTrue($service->toggleMapEntry($model, self::MAP, self::FIELDS, 11));
		$this->assertSame([$sql, $bindings], $this->db->selects[0] ?? null);
		$this->assertSame([], $this->log->lines);
	}

	public function testToggleMapEntryNone(): void {
		$this->assertFalse(self::admin()->toggleMapEntry('MapCombined', self::MAP, self::FIELDS, 11));
		$this->assertFalse(self::user()->toggleMapEntry('MapCustom', 'phish', ['pattern'], 11));
		$this->assertFalse(self::admin()->toggleMapEntry('MapOther', 'phish', ['pattern'], 11));
		$this->assertSame([[], [], 1], [$this->db->changes, $this->changedMaps(), count($this->db->selects)]);
	}

	public function testToggleMapEntryNoFile(): void {
		$this->answers[] = ['`id` = ?', [['id' => 11, 'disabled' => 0]]];
		Config::set('MAP_DIR', "{$this->dir}/none");
		$this->assertFalse(self::admin()->toggleMapEntry('MapCombined', self::MAP, self::FIELDS, 11));
		$this->assertSame([self::MAP], $this->changedMaps());
	}
}
