<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 MapService's maps on RecordingDatabase. A custom map's field (name and
 label), empty when it has no config, logged. A map exists by name, any
 case or spacing, built-in or custom; manage_custom_maps is taken. Adding a
 custom map's config, or changing it, marks the map changed and writes its
 file, its symbols kept (trimmed, each once) once map hits are recorded,
 dropped before; a rename moves its entries and drops the old activity
 log. The virus skip map's config is neither changed nor deleted; deleting
 a custom map deletes its entries, activity log and config. syncMaps()
 gives each configured map an activity log, drops the others' and the
 files of maps not configured. The pages: a combined map's entries (a
 user's in the maps given), searched in their fields, a custom map's,
 searched in the pattern, the custom maps' configs, newest first;
 items_per_page each, with the URL given. A map's page is one entry's, or
 the idle ones with map hits on. The active virus skip map's enabled
 patterns, or null when they cannot be read. DEBUG_SEARCH_SQL logs the
 queries.
*/

namespace Tests\Unit\Services;

use App\Configuration\Config;
use App\Inventory\Migrations;
use App\Services\MapService;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

final class MapConfigTest extends MapDatabaseTestCase
{
	private const string CONFIGS = 'select `id`, `map_name`, `map_description`, `field_name`, `field_label`, `created_at` from `custom_map_config`';
	private const array CONFIG = ['map_name' => 'phish', 'field_name' => 'url', 'map_description' => 'Phishing', 'field_label' => 'URL'];
	private const string USERS = 'select `id`, `username`, `email` from `users` where `users`.`id` in (7)';

	public function testGetCustomField(): void {
		$this->answers = [['custom_map_config', [['field_name' => 'url', 'field_label' => 'URL']]]];
		$this->assertSame(['field_name' => 'url', 'field_label' => 'URL'], MapService::getCustomField('phish'));
		$this->assertSame([['select `field_name`, `field_label` from `custom_map_config` where `map_name` = ? limit 1', ['phish']]], $this->db->selects);
	}

	public function testGetCustomFieldNone(): void {
		$this->assertSame(['field_name' => '', 'field_label' => ''], MapService::getCustomField('phish'));
		$this->assertSame(["error getCustomField: no custom_map_config row for map 'phish'"], $this->log->lines);
	}

	/** @return array<string, array{string, bool}> */
	public static function mapNames(): array {
		return [
			'built-in' => ['ip_blacklist', true],
			'any case and spacing' => [' IP_Blacklist ', true],
			'custom' => ['Phish', true],
			'the management page' => [' Manage_Custom_Maps', true],
			'unknown' => ['ip_greylist', false],
		];
	}

	// a custom map's name compared trimmed and lower case too
	#[DataProvider('mapNames')]
	public function testMapExists(string $name, bool $exists): void {
		$this->answers = [[self::CONFIGS, [['map_name' => ' Phish ', 'map_description' => 'Phishing', 'field_name' => 'url']]]];
		$this->assertSame($exists, self::admin()->mapExists($name));
	}

	// stamped with the time given: a new map's log added, a known one's changed
	public function testUpdateMapActivityLog(): void {
		$this->assertTrue(self::admin()->updateMapActivityLog('phish', '2026-11-20 10:00:00'));
		$this->assertSame([['map_name' => 'phish', 'last_changed_at' => '2026-11-20 10:00:00']], $this->db->rows('map_activity_logs'));
		$this->answers = [['map_activity_logs', [['map_name' => 'phish', 'last_changed_at' => '2026-11-01 10:00:00']]]];
		$this->assertTrue(self::admin()->updateMapActivityLog('phish', '2026-11-21 10:00:00'));
		$this->assertSame([['update `map_activity_logs` set `map_activity_logs`.`last_changed_at` = ? where `map_name` = ?', ['2026-11-21 10:00:00', 'phish']]], $this->db->changes);
	}

	// each map once
	public function testUpdateMapActivityLogs(): void {
		self::admin()->updateMapActivityLogs(['phish', 'ips', 'phish']);
		$this->assertSame(['phish', 'ips'], $this->changedMaps());
	}

	public function testDelMapActivityLog(): void {
		$this->assertTrue(MapService::delMapActivityLog('phish'));
		$this->assertSame([[['select * from `map_activity_logs` where `map_activity_logs`.`map_name` = ? limit 1', ['phish']]], []], [$this->db->selects, $this->db->changes]);
		$this->answers = [['map_activity_logs', [['map_name' => 'phish']]]];
		$this->assertTrue(MapService::delMapActivityLog('phish'));
		$this->assertSame([['delete from `map_activity_logs` where `map_name` = ?', ['phish']]], $this->db->changes);
	}

	// the configured maps get an activity log, the others lose theirs and their files
	// (a file's name equal to a map's only as a number is not that map's)
	public function testSyncMaps(): void {
		$this->answers = [
			[self::CONFIGS, [['map_name' => 'phish', 'map_description' => 'Phishing', 'field_name' => 'url'], ['map_name' => '1e1', 'map_description' => 'Ten', 'field_name' => 'url']]],
			['select `map_name` from `map_activity_logs`', [['map_name' => 'ip_blacklist'], ['map_name' => 'old'], ['map_name' => 'older']]],
		];
		foreach (['ip_blacklist', 'phish', 'old', 'notes', '1e1', '1e01'] as $file) {
			touch("{$this->dir}/{$file}.txt");
		}
		touch("{$this->dir}/old.conf");
		self::admin()->syncMaps();
		$this->assertSame([
			'mail_from_rcpt_to_whitelist', 'mail_from_rcpt_to_blacklist', 'mime_from_rcpt_to_whitelist', 'mime_from_rcpt_to_blacklist',
			'mail_from_whitelist', 'mail_from_blacklist', 'mime_from_whitelist', 'mime_from_blacklist', 'ip_whitelist', 'phish', '1e1',
		], $this->changedMaps());
		$this->assertSame([['delete from `map_activity_logs` where `map_name` in (?, ?)', ['old', 'older']]], $this->db->changes);
		$files = scandir($this->dir);
		$this->assertSame(['.', '..', '1e1.txt', 'ip_blacklist.txt', 'old.conf', 'phish.txt'], $files);
	}

	// every map logged already: nothing added or dropped
	public function testSyncMapsInSync(): void {
		$names = ['mail_from_rcpt_to_whitelist', 'mail_from_rcpt_to_blacklist', 'mime_from_rcpt_to_whitelist', 'mime_from_rcpt_to_blacklist',
			'mail_from_whitelist', 'mail_from_blacklist', 'mime_from_whitelist', 'mime_from_blacklist', 'ip_whitelist', 'ip_blacklist'];
		$this->answers = [['select `map_name` from `map_activity_logs`', array_map(fn (string $name): array => ['map_name' => $name], $names)]];
		self::admin()->syncMaps();
		$this->assertSame([[], []], [$this->changedMaps(), $this->db->changes]);
	}

	// named without its trailing slash
	public function testSyncMapsNoDirectory(): void {
		Config::set('MAP_DIR', "{$this->dir}/none/");
		try {
			self::admin()->syncMaps();
			$this->fail('No RuntimeException');
		} catch (RuntimeException $e) {
			$this->assertSame("Map directory not found: {$this->dir}/none", $e->getMessage());
		}
	}

	public function testAddCustomMapConfig(): void {
		$this->status->setMigrationState(Migrations::MAP_HITS, Migrations::STATUS_COMPLETED);
		$this->assertTrue(self::admin()->addCustomMapConfig(self::CONFIG + ['symbols' => ' RQWATCH_A, ,RQWATCH_B,RQWATCH_A ']));
		$row = $this->db->rows('custom_map_config')[0] ?? [];
		$this->assertSame(['phish', 'url', 'RQWATCH_A,RQWATCH_B'], [$row['map_name'] ?? null, $row['field_name'] ?? null, $row['symbols'] ?? null]);
		$this->assertSame(['phish'], $this->changedMaps());
		$this->assertSame([], $this->mapFile('phish'));
	}

	// before map hits, no symbols column to fill
	public function testAddCustomMapConfigBeforeMapHits(): void {
		$this->assertTrue(self::admin()->addCustomMapConfig(self::CONFIG + ['symbols' => 'RQWATCH_A']));
		$this->assertArrayNotHasKey('symbols', $this->db->rows('custom_map_config')[0] ?? []);
	}

	public function testAddCustomMapConfigNoFile(): void {
		Config::set('MAP_DIR', "{$this->dir}/none");
		$this->assertFalse(self::admin()->addCustomMapConfig(self::CONFIG));
		$this->assertSame(['phish'], $this->changedMaps());
		$this->assertSame("error Error updating map file for 'phish' in addCustomMapConfig", $this->log->lines[1] ?? null);
	}

	public function testAddCustomMapConfigFails(): void {
		$this->db->failInsertInto['custom_map_config'] = new RuntimeException('server has gone away');
		$this->assertFalse(self::admin()->addCustomMapConfig(self::CONFIG));
		$this->assertSame([[], ["error [addCustomMapConfig] Query insert error: server has gone away\n"]], [$this->changedMaps(), $this->log->lines]);
	}

	private function storedConfig(string $name = 'phish'): void {
		$this->answers = [['`custom_map_config`.`id` = ?', [['id' => 5, 'map_name' => $name, 'map_description' => 'Phishing', 'field_name' => 'url', 'field_label' => 'URL']]]];
	}

	public function testUpdateCustomMapConfig(): void {
		$this->storedConfig();
		$this->db->affect = fn (): int => 1;
		$this->assertTrue(self::admin()->updateCustomMapConfig(5, ['map_description' => 'Phishing URLs'] + self::CONFIG));
		$this->assertSame(['select * from `custom_map_config` where `custom_map_config`.`id` = ? limit 1', [5]], $this->db->selects[0] ?? null);
		$update = $this->db->changes[0] ?? ['', []];
		$this->assertSame(['update `custom_map_config` set `map_description` = ?, `custom_map_config`.`updated_at` = ? where `id` = ?', 'Phishing URLs', 5],
			[$update[0], $update[1][0] ?? null, $update[1][2] ?? null]);
		$this->assertCount(1, $this->db->changes);
		$this->assertSame(['phish'], $this->changedMaps());
		$this->assertSame([], $this->mapFile('phish'));
		$this->assertSame([], $this->log->lines);
	}

	// renamed: its entries move, the old activity log goes
	public function testUpdateCustomMapConfigRename(): void {
		$this->storedConfig();
		$this->db->affect = fn (): int => 1;
		$this->assertTrue(self::admin()->updateCustomMapConfig(5, ['map_name' => 'phishing'] + self::CONFIG));
		$this->assertSame([
			'update `custom_map_config` set `map_name` = ?, `custom_map_config`.`updated_at` = ? where `id` = ?',
			'update `maps_custom` set `map_name` = ?, `maps_custom`.`updated_at` = ? where `map_name` = ?',
			'delete from `map_activity_logs` where `map_name` = ?',
		], array_column($this->db->changes, 0));
		$this->assertSame(['phishing', 'phish'], [$this->db->changes[1][1][0] ?? null, $this->db->changes[1][1][2] ?? null]);
		$this->assertSame(['phish'], $this->db->changes[2][1] ?? null);
		$this->assertSame(['phishing'], $this->changedMaps());
		$this->assertSame(["info Map 'phish' rename to 'phishing' requested. Updating MapCustom entries and MapActivityLog"], $this->log->lines);
	}

	// not found; the virus skip map's
	public function testUpdateCustomMapConfigRefused(): void {
		$this->assertFalse(self::admin()->updateCustomMapConfig(5, self::CONFIG));
		Config::set('notification_skip_virus_map', 'Phish');
		$this->status->setMigrationState(Migrations::VIRUS_SKIP_MAP, Migrations::STATUS_COMPLETED);
		$this->storedConfig();
		$this->assertFalse(self::admin()->updateCustomMapConfig(5, self::CONFIG));
		$this->assertSame([
			"error [updateCustomMapConfig] CustomMapConfig with id '5' not found",
			"error [updateCustomMapConfig] 'phish' is the virus skip map, refusing edit",
		], $this->log->lines);
		$this->assertSame([], $this->db->changes);
	}

	public function testUpdateCustomMapConfigFails(): void {
		$this->storedConfig();
		$this->db->affect = fn (): never => throw new RuntimeException('server has gone away');
		$this->assertFalse(self::admin()->updateCustomMapConfig(5, ['map_description' => 'x'] + self::CONFIG));
		$this->assertSame([[], ["error [updateCustomMapConfig] Query update error: server has gone away\n"]], [$this->changedMaps(), $this->log->lines]);
	}

	public function testUpdateCustomMapConfigNoFile(): void {
		$this->storedConfig();
		Config::set('MAP_DIR', "{$this->dir}/none");
		$this->assertFalse(self::admin()->updateCustomMapConfig(5, self::CONFIG));
		$this->assertSame(['phish'], $this->changedMaps());
		$this->assertSame("error Error updating map file for 'phish' in updateCustomMapConfig", $this->log->lines[1] ?? null);
	}

	public function testDelCustomMap(): void {
		$this->storedConfig();
		$this->answers[] = ['from `maps_custom`', [['id' => 21, 'map_name' => 'phish'], ['id' => 22, 'map_name' => 'phish']]];
		$this->answers[] = ['from `map_activity_logs`', [['map_name' => 'phish']]];
		$this->assertTrue(self::admin()->delCustomMap(5));
		$this->assertSame([
			['delete from `maps_custom` where `id` = ?', [21]],
			['delete from `maps_custom` where `id` = ?', [22]],
			['delete from `map_activity_logs` where `map_name` = ?', ['phish']],
			['delete from `custom_map_config` where `id` = ?', [5]],
		], $this->db->changes);
	}

	public function testDelCustomMapRefused(): void {
		$this->assertFalse(self::admin()->delCustomMap(5));
		Config::set('notification_skip_virus_map', 'phish');
		$this->status->setMigrationState(Migrations::VIRUS_SKIP_MAP, Migrations::STATUS_COMPLETED);
		$this->storedConfig();
		$this->assertFalse(self::admin()->delCustomMap(5));
		$this->assertSame([[], ["error [delCustomMap] 'phish' is the virus skip map, refusing delete"]], [$this->db->changes, $this->log->lines]);
	}

	private const string COMBINED_COUNT = 'select count(*) as aggregate from `maps_combined`';
	private const string COMBINED_PAGE = 'select * from `maps_combined`';
	private const string SEARCH = ' and (`mail_from` LIKE ? or `rcpt_to` LIKE ? or `mime_from` LIKE ? or `mime_to` LIKE ? or `ip` LIKE ?)';
	private const string SCOPE = ' where `map_name` in (?) and `user_id` = ? and `rcpt_to` in (?, ?)';

	private function entries(): void {
		$this->answers = [['count(*)', [['aggregate' => 3]]], ['from `maps_', [['id' => 11, 'user_id' => 7]]], ['from `users`', [['id' => 7, 'username' => 'alice']]]];
	}

	public function testSearchPaginatedMapCombined(): void {
		$this->entries();
		Config::set('items_per_page', 2);
		$page = self::user()->searchPaginatedMapCombined(2, '/maps', ['mail_from_rcpt_to_whitelist'], ' x ', 'mail_from_rcpt_to_whitelist');
		$bindings = ['mail_from_rcpt_to_whitelist', 7, 'alice@example.org', 'alias@example.org', 'mail_from_rcpt_to_whitelist', ...array_fill(0, 5, '%x%')];
		$this->assertSame([
			[self::COMBINED_COUNT . self::SCOPE . ' and `map_name` = ?' . self::SEARCH, $bindings],
			[self::COMBINED_PAGE . self::SCOPE . ' and `map_name` = ?' . self::SEARCH . ' order by `updated_at` desc limit 2 offset 2', $bindings],
			[self::USERS, []],
		], $this->db->selects);
		$this->assertSame([3, '/maps?page=1', 'alice'], [$page->total(), $page->url(1), $page->first()?->user?->username]);
		$this->assertSame([], $this->log->lines);
	}

	// an admin's: every map; no map, no search: all entries
	public function testSearchPaginatedMapCombinedAll(): void {
		$this->entries();
		$this->setEnv('DEBUG_SEARCH_SQL', 'true');
		self::admin()->searchPaginatedMapCombined(1, '/maps', ['mail_from_rcpt_to_whitelist'], ' ', '');
		self::admin()->searchPaginatedMapCombined(1, '/maps', [], '', null);
		$this->assertSame([self::COMBINED_COUNT, []], $this->db->selects[0] ?? null);
		$this->assertSame([self::COMBINED_COUNT, []], $this->db->selects[3] ?? null);
		$this->assertSame(['info ' . self::COMBINED_PAGE . ' order by `updated_at` desc', 'info ' . self::COMBINED_PAGE . ' order by `updated_at` desc'], $this->log->lines);
	}

	// a user without maps given: not limited to any
	public function testSearchPaginatedMapCombinedNoMaps(): void {
		$this->entries();
		self::user()->searchPaginatedMapCombined(1, '/maps', [], '', null);
		$this->assertSame([self::COMBINED_COUNT . ' where `user_id` = ? and `rcpt_to` in (?, ?)', [7, 'alice@example.org', 'alias@example.org']], $this->db->selects[0] ?? null);
	}

	public function testGetPaginatedAllMapCombined(): void {
		$this->entries();
		$this->setEnv('DEBUG_SEARCH_SQL', 'true');
		$page = self::user()->getPaginatedAllMapCombined(1, '/maps', ['mail_from_rcpt_to_whitelist']);
		$bindings = ['mail_from_rcpt_to_whitelist', 7, 'alice@example.org', 'alias@example.org'];
		$this->assertSame([
			[self::COMBINED_COUNT . self::SCOPE, $bindings],
			[self::COMBINED_PAGE . self::SCOPE . ' order by `updated_at` desc limit 50 offset 0', $bindings],
			[self::USERS, []],
		], $this->db->selects);
		$this->assertSame(['/maps?page=1', 1], [$page->url(1), count($this->log->lines)]);
	}

	// an admin's: every map; a user without maps given: not limited to any
	public function testGetPaginatedAllMapCombinedUnlimited(): void {
		$this->entries();
		self::admin()->getPaginatedAllMapCombined(1, '/maps', ['mail_from_rcpt_to_whitelist']);
		self::user()->getPaginatedAllMapCombined(1, '/maps', []);
		$this->assertSame([
			[self::COMBINED_COUNT, []],
			[self::COMBINED_COUNT . ' where `user_id` = ? and `rcpt_to` in (?, ?)', [7, 'alice@example.org', 'alias@example.org']],
		], [$this->db->selects[0] ?? null, $this->db->selects[3] ?? null]);
	}

	private const string CUSTOM_FIELDS = 'select `id`, `map_name`, `pattern`, `disabled`, `score`, `created_at`, `updated_at` from `maps_custom`';

	public function testSearchPaginatedMapCustom(): void {
		$this->entries();
		Config::set('items_per_page', 2);
		$page = self::admin()->searchPaginatedMapCustom(2, '/maps', ' x ', 'phish');
		$this->assertSame([
			['select count(*) as aggregate from `maps_custom` where `map_name` = ? and `pattern` LIKE ?', ['phish', '%x%']],
			[self::CUSTOM_FIELDS . ' where `map_name` = ? and `pattern` LIKE ? order by `updated_at` desc, `updated_at` desc limit 2 offset 2', ['phish', '%x%']],
		], $this->db->selects);
		$this->assertSame('/maps?page=1', $page->url(1));
	}

	// no map: every custom map's; no search: unsorted
	public function testSearchPaginatedMapCustomAll(): void {
		$this->entries();
		$this->setEnv('DEBUG_SEARCH_SQL', 'true');
		self::admin()->searchPaginatedMapCustom(1, '/maps', 'x', '');
		self::admin()->searchPaginatedMapCustom(1, '/maps', ' ', null);
		$this->assertSame([
			'select count(*) as aggregate from `maps_custom` where `pattern` LIKE ?',
			self::CUSTOM_FIELDS . ' where `pattern` LIKE ? order by `updated_at` desc limit 50 offset 0',
			'select count(*) as aggregate from `maps_custom`',
			self::CUSTOM_FIELDS . ' limit 50 offset 0',
		], array_column($this->db->selects, 0));
		$this->assertSame(['info ' . self::CUSTOM_FIELDS . ' where `pattern` LIKE "%x%" order by `updated_at` desc', 'info ' . self::CUSTOM_FIELDS], $this->log->lines);
	}

	public function testGetPaginatedAllMapCustom(): void {
		$this->entries();
		$this->setEnv('DEBUG_SEARCH_SQL', 'true');
		$this->assertSame('/maps?page=1', self::admin()->getPaginatedAllMapCustom(1, '/maps')->url(1));
		$this->assertSame(['select * from `maps_custom` order by `updated_at` desc limit 50 offset 0', []], $this->db->selects[1] ?? null);
		$this->assertSame(['info select * from `maps_custom` order by `updated_at` desc'], $this->log->lines);
	}

	public function testGetPaginatedCustomMapConfigs(): void {
		$this->answers = [['count(*)', [['aggregate' => 1]]], ['custom_map_config', [['id' => 5, 'map_name' => 'phish']]]];
		$this->setEnv('DEBUG_SEARCH_SQL', 'true');
		$configs = self::admin()->getPaginatedCustomMapConfigs(2, '/configs');
		$this->assertSame(['select * from `custom_map_config` order by `updated_at` desc limit 50 offset 50', []], $this->db->selects[1] ?? null);
		$this->assertSame(['/configs?page=1', 1], [$configs->url(1), $configs->total()]);
		$this->assertSame(['info select * from `custom_map_config` order by `updated_at` desc'], $this->log->lines);
	}

	private const string MAP_ENTRIES = 'select `id`, `user_id`, `disabled`, `created_at`, `updated_at`, `mail_from`, `rcpt_to` from `maps_combined`'
		. ' where `map_name` = ? and `mail_from` is not null and `rcpt_to` is not null and `user_id` = ? and `rcpt_to` in (?, ?)';
	private const string IDLE = ' and not exists (select 1 from `mail_log_symbol_hits` where `entry_model` = ? and `entry_id` = `maps_combined`.`id`'
		. ' and created_day >= CURDATE() - INTERVAL ? DAY)';

	public function testGetPaginatedMapCombined(): void {
		$this->entries();
		$this->status->setMigrationState(Migrations::MAP_HITS, Migrations::STATUS_COMPLETED);
		$page = self::user()->getPaginatedMapCombined('mail_from_rcpt_to_whitelist', ['mail_from', 'rcpt_to'], 2, '/map', true, 11);
		$this->assertSame([
			self::MAP_ENTRIES . ' and `id` = ?' . self::IDLE . ' order by `updated_at` desc limit 50 offset 50',
			['mail_from_rcpt_to_whitelist', 7, 'alice@example.org', 'alias@example.org', 11, 1, 30],
		], $this->db->selects[1] ?? null);
		$this->assertSame([self::USERS, []], $this->db->selects[2] ?? null);
		$this->assertSame('/map?page=1', $page->url(1));
	}

	// idle asked for before map hits: every entry
	public function testGetPaginatedMapCombinedAll(): void {
		$this->entries();
		self::user()->getPaginatedMapCombined('mail_from_rcpt_to_whitelist', ['mail_from', 'rcpt_to'], 1, '/map', true);
		$this->assertSame(self::MAP_ENTRIES . ' order by `updated_at` desc limit 50 offset 0', $this->db->selects[1][0] ?? null);
		$this->status->setMigrationState(Migrations::MAP_HITS, Migrations::STATUS_COMPLETED);
		self::user()->getPaginatedMapCombined('mail_from_rcpt_to_whitelist', ['mail_from', 'rcpt_to'], 1, '/map');
		$this->assertSame(self::MAP_ENTRIES . ' order by `updated_at` desc limit 50 offset 0', $this->db->selects[4][0] ?? null);
	}

	// the active virus skip map's enabled patterns; null on a query error, logged
	public function testVirusSkipPatterns(): void {
		Config::set('notification_skip_virus_map', ' virus_skip ');
		$this->status->setMigrationState(Migrations::VIRUS_SKIP_MAP, Migrations::STATUS_COMPLETED);
		$this->answers = [['maps_custom', [['pattern' => 'eicar-*'], ['pattern' => 'Other*']]]];
		$this->assertSame(['eicar-*', 'Other*'], MapService::getVirusSkipPatterns());
		$this->assertSame([['select `pattern` from `maps_custom` where `map_name` = ? and `disabled` = ?', ['virus_skip', 0]]], $this->db->selects);
		$this->db->answer = fn (): never => throw new RuntimeException('server has gone away');
		$this->assertNull(MapService::getVirusSkipPatterns());
		$this->assertSame(['error [getVirusSkipPatterns] Query error: server has gone away'], $this->log->lines);
	}
}
