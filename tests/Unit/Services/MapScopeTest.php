<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Which map entries a user's MapService reads (getMapCombinedQuery(), the
 user's context as given to the constructor): an admin every entry; a user
 without an email none; a user their own entries for their own address,
 or for any of their aliases, and with USER_CAN_SEE_ADMIN_MAP_ENTRIES also
 the admins' entries, each with its user; the CLI every entry. A page of
 a custom map holds items_per_page entries (default 50), or the one asked
 for, and links to the URL given; idle only, with map hits on, it lists the
 entries without a hit in the longest window. DEBUG_SEARCH_SQL logs each
 query. The queries are only
 built, or answered by RecordingDatabase.
*/

namespace Tests\Unit\Services;

use App\Configuration\Config;
use App\Core\App;
use App\Core\AppContainer;
use App\Core\Database\MigrationStatus;
use App\Inventory\Migrations;
use App\Services\MapService;
use Illuminate\Database\Eloquent\Model;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\Unit\RecordingDatabase;
use Tests\Unit\UnitTestCase;

final class MapScopeTest extends UnitTestCase
{
	private const string ENTRIES = 'select "id", "user_id", "disabled", "created_at", "updated_at", "mail_from", "rcpt_to"'
		. ' from "maps_combined" where "map_name" = ? and "mail_from" is not null and "rcpt_to" is not null';
	private const string ORDER = ' order by "updated_at" desc';

	private const string CUSTOM = 'select "id", "map_name", "pattern", "disabled", "score", "created_at", "updated_at"'
		. ' from "maps_custom" where "map_name" = ?';

	private RecordingDatabase $db;

	private MigrationStatus $status;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->db = new RecordingDatabase($this->createStub(PDO::class));
		$this->db->capsule->bootEloquent();
		$this->status = new MigrationStatus(null, $this->log);
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, $this->db->capsule, $this->status, null, false));
		// without a warning: a context may leave any key out
		set_error_handler(fn (int $no, string $message): never => throw new \ErrorException($message, 0, $no));
	}

	#[\Override]
	protected function tearDown(): void {
		restore_error_handler();
		Model::unsetConnectionResolver();
		RecordingDatabase::forget();
		parent::tearDown();
	}

	/** @return array<string, array{array{is_admin?: bool, username?: string, user_id?: int, email?: string, user_aliases?: array<int, string>}, bool, string, list<int|string>}> context, USER_CAN_SEE_ADMIN_MAP_ENTRIES, where added, its bindings */
	public static function scopes(): array {
		$user = ['is_admin' => false, 'username' => 'alice', 'user_id' => 7, 'email' => 'alice@example.org'];
		return [
			'an admin' => [['is_admin' => true, 'user_id' => 1, 'email' => 'root@example.org'], false, '', []],
			'no email' => [['is_admin' => false, 'user_id' => 7], false, ' and "id" is null', []],
			"'0' as email" => [['is_admin' => false, 'user_id' => 7, 'email' => '0'], false, ' and "id" is null', []],
			'own address' => [$user, false, ' and "user_id" = ? and "rcpt_to" = ?', [7, 'alice@example.org']],
			'no aliases' => [$user + ['user_aliases' => []], false, ' and "user_id" = ? and "rcpt_to" = ?', [7, 'alice@example.org']],
			'aliases' => [$user + ['user_aliases' => ['alias@example.org', '', 'alice@example.org']], false,
				' and "user_id" = ? and "rcpt_to" in (?, ?)', [7, 'alice@example.org', 'alias@example.org']],
			'and the admins\' entries' => [$user, true,
				' and ("user_id" = ? or "user_id" in (select "id" from "users" where "is_admin" = ?)) and "rcpt_to" = ?',
				[7, 1, 'alice@example.org']],
		];
	}

	/**
	 * @param array{is_admin?: bool, username?: string, user_id?: int, email?: string, user_aliases?: array<int, string>} $context
	 * @param list<int|string> $bindings
	 */
	#[DataProvider('scopes')]
	public function testScope(array $context, bool $seeAdmins, string $where, array $bindings): void {
		Config::set('USER_CAN_SEE_ADMIN_MAP_ENTRIES', $seeAdmins);
		$query = (new MapService($context))->getMapCombinedQuery('mail_from_whitelist', ['mail_from', 'rcpt_to']);
		$this->assertSame(
			[self::ENTRIES . $where . self::ORDER, ['mail_from_whitelist', ...$bindings], ['user'], []],
			[$query->toSql(), $query->getBindings(), array_keys($query->getEagerLoads()), $this->log->lines]
		);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testCliScope(): void {
		define('CLI_MODE', true);
		$query = (new MapService(['is_admin' => false, 'user_id' => 7, 'email' => 'alice@example.org']))->getMapCombinedQuery('mail_from_whitelist', ['mail_from', 'rcpt_to']);
		$this->assertSame([self::ENTRIES . self::ORDER, ['mail_from_whitelist']], [$query->toSql(), $query->getBindings()]);
	}

	public function testScopeDebugSql(): void {
		$this->setEnv('DEBUG_SEARCH_SQL', 'true');
		(new MapService(['is_admin' => true]))->getMapCombinedQuery('mail_from_whitelist', ['mail_from']);
		$this->assertSame([
			'info select "id", "user_id", "disabled", "created_at", "updated_at", "mail_from" from "maps_combined"'
			. ' where "map_name" = "mail_from_whitelist" and "mail_from" is not null order by "updated_at" desc',
		], $this->log->lines);
	}

	/** @return array<string, array{?int, ?int, bool, bool, string, list<int|string>, string}> items_per_page, entry, idle only, map hits on, where added, its bindings, the page's limit */
	public static function pages(): array {
		return [
			'default size' => [null, null, false, false, '', [], 'limit 50 offset 50'],
			'configured size' => [20, null, false, false, '', [], 'limit 20 offset 20'],
			'one entry' => [20, 9, false, false, ' and "id" = ?', [9], 'limit 20 offset 20'],
			'map hits on, not idle only' => [20, null, false, true, '', [], 'limit 20 offset 20'],
			// no hits recorded yet: idle is not known, every entry listed
			'idle only, map hits off' => [20, null, true, false, '', [], 'limit 20 offset 20'],
			'idle only' => [20, 9, true, true, ' and "id" = ? and not exists (select 1 from "mail_log_symbol_hits" where "entry_model" = ? and "entry_id" = "maps_custom"."id"'
				. ' and created_day >= CURDATE() - INTERVAL ? DAY)', [9, 2, 30], 'limit 20 offset 20'],
		];
	}

	/** page 2 of 45 entries
	 * @param list<int|string> $bindings
	 */
	#[DataProvider('pages')]
	public function testPage(?int $perPage, ?int $entry, bool $idle, bool $mapHits, string $where, array $bindings, string $limit): void {
		Config::set('items_per_page', $perPage);
		if ($mapHits) {
			$this->status->setMigrationState(Migrations::MAP_HITS, Migrations::STATUS_COMPLETED);
		}
		$this->db->answer = static fn (string $sql): array => str_starts_with($sql, 'select count(*)') ? [['aggregate' => 45]] : [];
		$page = (new MapService())->getPaginatedMapCustom('body_url_host', 2, '/admin/map/body_url_host', $idle, $entry);
		$this->assertSame([
			['select count(*) as aggregate from "maps_custom" where "map_name" = ?' . $where, ['body_url_host', ...$bindings]],
			[self::CUSTOM . $where . ' order by "updated_at" desc ' . $limit, ['body_url_host', ...$bindings]],
		], $this->db->selects);
		$this->assertSame(['/admin/map/body_url_host', 45, []], [$page->path(), $page->total(), $this->log->lines]);
	}

	// by default every entry, also once map hits are recorded
	public function testPageListsEveryEntryByDefault(): void {
		$this->status->setMigrationState(Migrations::MAP_HITS, Migrations::STATUS_COMPLETED);
		(new MapService())->getPaginatedMapCustom('body_url_host', 1, '/admin/map/body_url_host');
		// no entries: the count alone, without an idle filter
		$this->assertSame([['select count(*) as aggregate from "maps_custom" where "map_name" = ?', ['body_url_host']]], $this->db->selects);
	}

	public function testPageDebugSql(): void {
		$this->setEnv('DEBUG_SEARCH_SQL', 'true');
		(new MapService())->getPaginatedMapCustom('body_url_host', 1, '/admin/map/body_url_host');
		$this->assertSame(['info ' . str_replace('?', '"body_url_host"', self::CUSTOM) . ' order by "updated_at" desc'], $this->log->lines);
	}
}
