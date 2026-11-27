<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 MailAliasService on RecordingDatabase. The aliases are read joined with
 their users (username, email), newest first, then by username and alias,
 as many as a limit above 0 says; a page holds items_per_page aliases (default 50), each with its user, and
 links to the URL given, a search's also to its text, matched anywhere in
 the username, email or alias. An alias exists for a user by user id and
 alias. Deleting an alias (in a transaction) deletes first the map entries
 its user made for its address (trimmed, lower case) in the maps a user
 may manage, logged with who did it, and marks those maps changed; then
 the alias, and ends its user's sessions (after the SESSION_VERSION
 migration). An alias of no address leaves the maps alone; a failure is
 logged, the alias kept. DEBUG_SEARCH_SQL logs each query.
*/

namespace Tests\Unit\Services;

use App\Configuration\Config;
use App\Core\App;
use App\Core\AppContainer;
use App\Core\Database\MigrationStatus;
use App\Inventory\Migrations;
use App\Models\MailAlias;
use App\Services\MailAliasService;
use Illuminate\Database\Eloquent\Model;
use PDO;
use RuntimeException;
use Tests\Unit\RecordingDatabase;
use Tests\Unit\UnitTestCase;

final class MailAliasServiceTest extends UnitTestCase
{
	private const string ALIASES = 'select `mail_aliases`.*, `users`.`username` as `username`, `users`.`email` as `email`'
		. ' from `mail_aliases` inner join `users` on `mail_aliases`.`user_id` = `users`.`id`';
	private const string ORDER = ' order by `mail_aliases`.`updated_at` desc, `users`.`username` asc, `mail_aliases`.`alias` asc';
	private const string COUNT = 'select count(*) as aggregate'
		. ' from `mail_aliases` inner join `users` on `mail_aliases`.`user_id` = `users`.`id`';
	private const string SEARCH = ' where `username` LIKE ? or `email` LIKE ? or `alias` LIKE ?';

	private const string ENTRIES = ' from `maps_combined` where `user_id` = ? and `map_name` in (?, ?, ?, ?) and `rcpt_to` = ?';
	private const array ENTRIES_BINDINGS = [7, 'mail_from_rcpt_to_whitelist', 'mail_from_rcpt_to_blacklist',
		'mime_from_rcpt_to_whitelist', 'mime_from_rcpt_to_blacklist', 'alias@example.org'];
	private const string END_SESSIONS = 'update `users` set `session_version` = `session_version` + 1, `updated_at` = `updated_at` where `users`.`id` = ?';

	private RecordingDatabase $db;

	private MigrationStatus $status;

	/** @var list<array<string, mixed>> the maps with entries for the address */
	private array $maps = [['map_name' => 'mail_from_rcpt_to_whitelist'], ['map_name' => 'mime_from_rcpt_to_blacklist']];

	/** @var int the map entries a DELETE removes */
	private int $entries = 3;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->db = new RecordingDatabase($this->createStub(PDO::class));
		$this->db->capsule->bootEloquent();
		$this->db->answer = function (string $sql, array $bindings): array {
			if (str_starts_with($sql, 'select count(*)')) {
				return [['aggregate' => 3]];
			}
			if (str_starts_with($sql, 'select exists(')) {
				return [['exists' => $bindings === [7, 'alias@example.org'] ? 1 : 0]];
			}
			if (str_contains($sql, 'from `custom_map_config`') || str_contains($sql, 'from `map_activity_logs`')) {
				return [];
			}
			if (str_contains($sql, 'from `maps_combined`')) {
				return $this->maps;
			}
			if (str_starts_with($sql, self::ALIASES)) {
				return [['id' => 5, 'user_id' => 7, 'alias' => 'alias@example.org', 'username' => 'alice', 'email' => 'alice@example.org']];
			}
			if (str_contains($sql, 'from `users`')) {
				return [['id' => 7, 'username' => 'alice']];
			}
			throw new RuntimeException("Unexpected query: {$sql}");
		};
		$this->db->affect = fn (string $sql): int => str_contains($sql, '`maps_combined`') ? $this->entries : 1;
		$this->status = new MigrationStatus(null, $this->log);
		$this->status->setMigrationState(Migrations::SESSION_VERSION, Migrations::STATUS_COMPLETED);
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, $this->db->capsule, $this->status, null, false));
	}

	#[\Override]
	protected function tearDown(): void {
		Model::unsetConnectionResolver();
		RecordingDatabase::forget();
		parent::tearDown();
	}

	private static function alias(string $address): MailAlias {
		return (new MailAlias())->newFromBuilder(['id' => 5, 'user_id' => 7, 'alias' => $address]);
	}

	// a limit above 0 limits the aliases read
	public function testSearchQueryLimit(): void {
		$service = new MailAliasService();
		$this->assertSame(self::ALIASES . self::ORDER . ' limit 3', $service->getSearchQuery(3)->toSql());
		$this->assertSame(self::ALIASES . self::ORDER . ' limit 1', $service->getSearchQuery(1)->toSql());
		$this->assertSame(self::ALIASES . self::ORDER, $service->getSearchQuery(0)->toSql());
		$this->assertSame(self::ALIASES . self::ORDER, $service->getSearchQuery()->toSql());
	}

	public function testAliasExists(): void {
		$service = new MailAliasService();
		$this->assertTrue($service->aliasExists(7, 'alias@example.org'));
		$this->assertFalse($service->aliasExists(8, 'alias@example.org'));
		$this->assertSame([
			'select exists(' . self::ALIASES . ' where `user_id` = ? and `alias` = ?' . self::ORDER . ') as `exists`',
			[7, 'alias@example.org'],
		], $this->db->selects[0] ?? null);
		$this->assertSame([], $this->log->lines);
	}

	public function testAliasExistsLogged(): void {
		$this->setEnv('DEBUG_SEARCH_SQL', 'true');
		(new MailAliasService())->aliasExists(7, 'alias@example.org');
		$this->assertSame(['info ' . self::ALIASES . ' where `user_id` = "7" and `alias` = "alias@example.org"' . self::ORDER], $this->log->lines);
	}

	public function testAliasDel(): void {
		$this->assertTrue((new MailAliasService())->aliasDel(self::alias(' Alias@Example.ORG '), 'admin'));
		$this->assertSame(['select distinct `map_name`' . self::ENTRIES, self::ENTRIES_BINDINGS], $this->db->selects[1] ?? null);
		$this->assertSame([
			['delete' . self::ENTRIES, self::ENTRIES_BINDINGS],
			['delete from `mail_aliases` where `id` = ?', [5]],
			[self::END_SESSIONS, [7]],
		], $this->db->changes);
		$this->assertSame(["info aliasDel: deleted 3 map entries for alias 'alias@example.org' by 'admin'"], $this->log->lines);
		$changed = $this->db->rows('map_activity_logs');
		$this->assertSame(['mail_from_rcpt_to_whitelist', 'mime_from_rcpt_to_blacklist'], array_column($changed, 'map_name'));
		foreach ($changed as $row) {
			/** @var mixed $at */
			$at = $row['last_changed_at'] ?? null;
			$this->assertIsString($at);
			$this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/D', $at);
		}
	}

	// no entries of the user's for the address: no map changed
	public function testAliasDelNoEntries(): void {
		$this->entries = 0;
		$this->assertTrue((new MailAliasService())->aliasDel(self::alias('alias@example.org'), 'admin'));
		$this->assertSame([
			['delete' . self::ENTRIES, self::ENTRIES_BINDINGS],
			['delete from `mail_aliases` where `id` = ?', [5]],
			[self::END_SESSIONS, [7]],
		], $this->db->changes);
		$this->assertSame([[], []], [$this->db->rows('map_activity_logs'), $this->log->lines]);
	}

	public function testAliasDelOneEntry(): void {
		$this->entries = 1;
		$this->maps = [['map_name' => 'mail_from_rcpt_to_blacklist']];
		$this->assertTrue((new MailAliasService())->aliasDel(self::alias('alias@example.org'), 'bob'));
		$this->assertSame(['mail_from_rcpt_to_blacklist'], array_column($this->db->rows('map_activity_logs'), 'map_name'));
		$this->assertSame(["info aliasDel: deleted 1 map entries for alias 'alias@example.org' by 'bob'"], $this->log->lines);
	}

	// an alias of no address: no map entry asked for or deleted
	public function testAliasDelNoAddress(): void {
		$this->assertTrue((new MailAliasService())->aliasDel(self::alias(' '), 'admin'));
		$this->assertSame([['delete from `mail_aliases` where `id` = ?', [5]], [self::END_SESSIONS, [7]]], $this->db->changes);
		$this->assertCount(1, $this->db->selects);
	}

	public function testAliasDelBeforeSessionVersion(): void {
		$this->status->setMigrationState(Migrations::SESSION_VERSION, Migrations::STATUS_RUNNING);
		$this->assertTrue((new MailAliasService())->aliasDel(self::alias('alias@example.org'), 'admin'));
		$this->assertSame([
			['delete' . self::ENTRIES, self::ENTRIES_BINDINGS],
			['delete from `mail_aliases` where `id` = ?', [5]],
		], $this->db->changes);
	}

	// an alias not stored is not deleted: its user's sessions go on
	public function testAliasDelNotStored(): void {
		$alias = new MailAlias(['user_id' => 7, 'alias' => 'alias@example.org']);
		$this->assertFalse((new MailAliasService())->aliasDel($alias, 'admin'));
		$this->assertSame([['delete' . self::ENTRIES, self::ENTRIES_BINDINGS]], $this->db->changes);
	}

	public function testAliasDelFailure(): void {
		$this->db->affect = fn (string $sql): never => throw new RuntimeException('server has gone away');
		$this->assertFalse((new MailAliasService())->aliasDel(self::alias('Alias@example.org'), 'admin'));
		$this->assertSame(["error aliasDel error: delete of 'alias@example.org' by 'admin' failed: server has gone away"], $this->log->lines);
	}

	public function testPaginatedAll(): void {
		Config::set('items_per_page', 2);
		$aliases = (new MailAliasService())->getPaginatedAll('/aliases', 2);
		$this->assertSame([
			[self::COUNT, []],
			[self::ALIASES . self::ORDER . ' limit 2 offset 2', []],
			['select * from `users` where `users`.`id` in (7)', []],
		], $this->db->selects);
		$this->assertSame([3, 2, '/aliases?page=1'], [$aliases->total(), $aliases->perPage(), $aliases->url(1)]);
		$alias = $aliases->first();
		$this->assertSame(['alias@example.org', 'alice'], [$alias?->alias, $alias?->user?->username]);
		$this->assertSame([], $this->log->lines);
	}

	// 50 a page by default
	public function testPaginatedAllDefault(): void {
		$this->setEnv('DEBUG_SEARCH_SQL', 'true');
		(new MailAliasService())->getPaginatedAll('/aliases');
		$this->assertSame(self::ALIASES . self::ORDER . ' limit 50 offset 0', $this->db->selects[1][0] ?? null);
		$this->assertSame(['info ' . self::ALIASES . self::ORDER], $this->log->lines);
	}

	public function testSearchPaginatedAll(): void {
		Config::set('items_per_page', 2);
		$aliases = (new MailAliasService())->searchPaginatedAll('/aliases/search', 'ali', 2);
		$like = ['%ali%', '%ali%', '%ali%'];
		$this->assertSame([
			[self::COUNT . self::SEARCH, $like],
			[self::ALIASES . self::SEARCH . self::ORDER . ' limit 2 offset 2', $like],
			['select * from `users` where `users`.`id` in (7)', []],
		], $this->db->selects);
		$this->assertSame([3, '/aliases/search?q=ali&page=1'], [$aliases->total(), $aliases->url(1)]);
		$this->assertSame('alice', $aliases->first()?->user?->username);
		$this->assertSame([], $this->log->lines);
	}

	// page 1 by default
	public function testSearchPaginatedAllLogged(): void {
		$this->setEnv('DEBUG_SEARCH_SQL', 'true');
		(new MailAliasService())->searchPaginatedAll('/aliases/search', 'ali');
		$this->assertSame(self::ALIASES . self::SEARCH . self::ORDER . ' limit 50 offset 0', $this->db->selects[1][0] ?? null);
		$this->assertSame(['info ' . self::ALIASES . ' where `username` LIKE "%ali%" or `email` LIKE "%ali%" or `alias` LIKE "%ali%"' . self::ORDER], $this->log->lines);
	}
}
