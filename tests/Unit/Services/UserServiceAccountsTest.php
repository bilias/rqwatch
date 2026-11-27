<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 UserService's account queries on RecordingDatabase. A user is read by id
 or username, a profile by username with its fields. The users are listed
 by last login, newest first, as many as a limit above 0 says; a page
 holds items_per_page users (default 50) and links to the URL given, a
 search's also to its text, matched anywhere in the username, email, first
 or last name. Deleting a user (in a transaction): not one not found,
 'admin', or the acting user, each logged. The map entries the user made
 for their own addresses (email and aliases, trimmed, lower case, each
 once) in the maps a user may manage are deleted, logged, those maps
 marked changed; the rest of their entries move to the acting user,
 logged; then the user goes. A failure is logged, the user kept. A user
 added is stored with its username trimmed and lower case; a failure is
 logged. DEBUG_SEARCH_SQL logs each query.
*/

namespace Tests\Unit\Services;

use App\Configuration\Config;
use App\Core\App;
use App\Core\AppContainer;
use App\Core\Database\MigrationStatus;
use App\Services\UserService;
use Illuminate\Database\Eloquent\Model;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Unit\RecordingDatabase;
use Tests\Unit\UnitTestCase;

final class UserServiceAccountsTest extends UnitTestCase
{
	private const string FIELDS = 'select `id`, `username`, `email`, `firstname`, `lastname`, `disable_notifications`, `is_admin`, `last_login`, `auth_provider` from `users`';
	private const string SEARCH = ' where `username` LIKE ? or `email` LIKE ? or `firstname` LIKE ? or `lastname` LIKE ?';
	private const string ORDER = ' order by `last_login` desc';

	private const string PERSONAL = ' from `maps_combined` where `user_id` = ? and `map_name` in (?, ?, ?, ?) and `rcpt_to` in ';
	private const array USER_MAPS = ['mail_from_rcpt_to_whitelist', 'mail_from_rcpt_to_blacklist', 'mime_from_rcpt_to_whitelist', 'mime_from_rcpt_to_blacklist'];
	private const string MOVE = 'update `maps_combined` set `user_id` = ?, `maps_combined`.`updated_at` = ? where `user_id` = ?';

	private RecordingDatabase $db;

	/** @var list<array<string, mixed>> the users any users lookup finds */
	private array $users = [['id' => 7, 'username' => 'alice', 'email' => ' Alice@Example.org']];

	/** @var list<array<string, mixed>> the user's aliases */
	private array $aliases = [
		['id' => 1, 'user_id' => 7, 'alias' => ' Other@Example.org'],
		['id' => 2, 'user_id' => 7, 'alias' => 'alice@example.org'],
		['id' => 3, 'user_id' => 7, 'alias' => ' '],
	];

	/** @var list<array<string, mixed>> the maps with personal entries */
	private array $maps = [['map_name' => 'mail_from_rcpt_to_whitelist']];

	private int $personal = 2;

	private int $moved = 4;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->db = new RecordingDatabase($this->createStub(PDO::class));
		$this->db->capsule->bootEloquent();
		$this->db->answer = function (string $sql): array {
			if (str_starts_with($sql, 'select count(*)')) {
				return [['aggregate' => 3]];
			}
			if (str_contains($sql, 'from `users`')) {
				return $this->users;
			}
			if (str_contains($sql, 'from `mail_aliases`')) {
				return $this->aliases;
			}
			if (str_contains($sql, 'from `maps_combined`')) {
				return $this->maps;
			}
			if (str_contains($sql, 'from `custom_map_config`') || str_contains($sql, 'from `map_activity_logs`')) {
				return [];
			}
			throw new RuntimeException("Unexpected query: {$sql}");
		};
		$this->db->affect = fn (string $sql): int => match (true) {
			str_starts_with($sql, 'delete from `maps_combined`') => $this->personal,
			str_starts_with($sql, 'update `maps_combined`') => $this->moved,
			default => 1,
		};
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, $this->db->capsule, new MigrationStatus(null, $this->log), null, false));
	}

	#[\Override]
	protected function tearDown(): void {
		Model::unsetConnectionResolver();
		RecordingDatabase::forget();
		parent::tearDown();
	}

	public function testGetUser(): void {
		$service = new UserService();
		$this->assertSame(7, $service->getUser(7)?->id);
		$this->assertSame('alice', $service->getUserByUsername('alice')?->username);
		$this->assertSame(' Alice@Example.org', $service->profile('alice')?->email);
		$this->assertSame([
			['select * from `users` where `id` = ? limit 1', [7]],
			['select * from `users` where `username` = ? limit 1', ['alice']],
			['select `id`, `username`, `email`, `firstname`, `lastname`, `last_login`, `auth_provider`, `disable_notifications`, `is_admin`, `created_at`, `updated_at`'
				. ' from `users` where `username` = ? limit 1', ['alice']],
		], $this->db->selects);
		$this->assertSame([], $this->log->lines);
	}

	public function testNoUser(): void {
		$this->users = [];
		$service = new UserService();
		$this->assertSame([null, null, null], [$service->getUser(7), $service->getUserByUsername('alice'), $service->profile('alice')]);
	}

	public function testGetUserLogged(): void {
		$this->setEnv('DEBUG_SEARCH_SQL', 'true');
		$service = new UserService();
		$service->getUser(7);
		$service->getUserByUsername('alice');
		$service->profile('alice');
		$this->assertSame([
			'info select * from `users` where `id` = "7"',
			'info select * from `users` where `username` = "alice"',
			'info select `id`, `username`, `email`, `firstname`, `lastname`, `last_login`, `auth_provider`, `disable_notifications`, `is_admin`, `created_at`, `updated_at`'
				. ' from `users` where `username` = "alice"',
		], $this->log->lines);
	}

	// a limit above 0 limits the users read
	public function testSearchQueryLimit(): void {
		$service = new UserService();
		$this->assertSame('select `id` from `users`' . self::ORDER . ' limit 3', $service->getSearchQuery(['id'], 3)->toSql());
		$this->assertSame('select `id` from `users`' . self::ORDER . ' limit 1', $service->getSearchQuery(['id'], 1)->toSql());
		$this->assertSame('select `id` from `users`' . self::ORDER, $service->getSearchQuery(['id'], 0)->toSql());
		$this->assertSame('select `id` from `users`' . self::ORDER, $service->getSearchQuery(['id'])->toSql());
	}

	public function testPaginatedAll(): void {
		Config::set('items_per_page', 2);
		$users = (new UserService())->getPaginatedAll('/users', 2);
		$this->assertSame([
			['select count(*) as aggregate from `users`', []],
			[self::FIELDS . self::ORDER . ' limit 2 offset 2', []],
		], $this->db->selects);
		$this->assertSame([3, 2, '/users?page=1', 'alice'], [$users->total(), $users->perPage(), $users->url(1), $users->first()?->username]);
		$this->assertSame([], $this->log->lines);
	}

	// page 1 of 50 by default
	public function testPaginatedAllDefault(): void {
		$this->setEnv('DEBUG_SEARCH_SQL', 'true');
		(new UserService())->getPaginatedAll('/users');
		$this->assertSame(self::FIELDS . self::ORDER . ' limit 50 offset 0', $this->db->selects[1][0] ?? null);
		$this->assertSame(['info ' . self::FIELDS . self::ORDER], $this->log->lines);
	}

	public function testSearchPaginatedAll(): void {
		Config::set('items_per_page', 2);
		$users = (new UserService())->searchPaginatedAll(2, '/users/search', 'ali');
		$like = ['%ali%', '%ali%', '%ali%', '%ali%'];
		$this->assertSame([
			['select count(*) as aggregate from `users`' . self::SEARCH, $like],
			[self::FIELDS . self::SEARCH . self::ORDER . ' limit 2 offset 2', $like],
		], $this->db->selects);
		$this->assertSame([3, '/users/search?q=ali&page=1'], [$users->total(), $users->url(1)]);
		$this->assertSame([], $this->log->lines);
	}

	public function testSearchPaginatedAllLogged(): void {
		$this->setEnv('DEBUG_SEARCH_SQL', 'true');
		(new UserService())->searchPaginatedAll(1, '/users/search', 'ali');
		$this->assertSame(self::FIELDS . self::SEARCH . self::ORDER . ' limit 50 offset 0', $this->db->selects[1][0] ?? null);
		$this->assertSame(['info ' . self::FIELDS
			. ' where `username` LIKE "%ali%" or `email` LIKE "%ali%" or `firstname` LIKE "%ali%" or `lastname` LIKE "%ali%"'
			. self::ORDER], $this->log->lines);
	}

	public function testUserDel(): void {
		$this->assertTrue((new UserService())->userDel(7, 1, 'admin'));
		$personal = [7, ...self::USER_MAPS, 'alice@example.org', 'other@example.org'];
		$this->assertContains(['select distinct `map_name`' . self::PERSONAL . '(?, ?)', $personal], $this->db->selects);
		$this->assertSame(['delete' . self::PERSONAL . '(?, ?)', $personal], $this->db->changes[0] ?? null);
		$this->assertSame([self::MOVE, 7], [$this->db->changes[1][0] ?? null, $this->db->changes[1][1][2] ?? null]);
		$this->assertSame(1, $this->db->changes[1][1][0] ?? null);
		$this->assertSame(['delete from `users` where `id` = ?', [7]], $this->db->changes[2] ?? null);
		$this->assertCount(3, $this->db->changes);
		$this->assertSame(['mail_from_rcpt_to_whitelist'], array_column($this->db->rows('map_activity_logs'), 'map_name'));
		$this->assertSame([
			"info userDel: deleted 2 personal map entries of user 'alice' by 'admin'",
			"info userDel: moved 4 map entries of 'alice' to 'admin' (id 1)",
		], $this->log->lines);
	}

	// one entry each way: still logged
	public function testUserDelOneEach(): void {
		$this->personal = 1;
		$this->moved = 1;
		$this->assertTrue((new UserService())->userDel(7, 1, 'root'));
		$this->assertSame([
			"info userDel: deleted 1 personal map entries of user 'alice' by 'root'",
			"info userDel: moved 1 map entries of 'alice' to 'root' (id 1)",
		], $this->log->lines);
	}

	// nothing personal, nothing else: no map changed, nothing logged
	public function testUserDelNoEntries(): void {
		$this->personal = 0;
		$this->moved = 0;
		$this->assertTrue((new UserService())->userDel(7, 1, 'admin'));
		$this->assertCount(3, $this->db->changes);
		$this->assertSame([[], []], [$this->db->rows('map_activity_logs'), $this->log->lines]);
	}

	/** @return array<string, array{?string, list<array<string, mixed>>, list<string>}> email, aliases, the addresses */
	public static function addresses(): array {
		return [
			'no email' => [null, [['id' => 1, 'user_id' => 7, 'alias' => 'b@example.org']], ['b@example.org']],
			'no aliases' => ['A@example.org', [], ['a@example.org']],
		];
	}

	/**
	 * @param list<array<string, mixed>> $aliases
	 * @param list<string> $addresses
	 */
	#[DataProvider('addresses')]
	public function testUserDelAddresses(?string $email, array $aliases, array $addresses): void {
		$this->users = [['id' => 7, 'username' => 'alice', 'email' => $email]];
		$this->aliases = $aliases;
		(new UserService())->userDel(7, 1, 'admin');
		$this->assertSame(['delete' . self::PERSONAL . '(?)', [7, ...self::USER_MAPS, ...$addresses]], $this->db->changes[0] ?? null);
	}

	// no address of their own: no personal entry asked for or deleted, the rest moved
	public function testUserDelNoAddresses(): void {
		$this->users = [['id' => 7, 'username' => 'alice', 'email' => null]];
		$this->aliases = [];
		$this->assertTrue((new UserService())->userDel(7, 1, 'admin'));
		$this->assertSame([self::MOVE, 'delete from `users` where `id` = ?'], array_column($this->db->changes, 0));
		$this->assertSame([], array_filter($this->db->selects, fn (array $select): bool => str_contains($select[0], 'maps_combined')));
	}

	/** @return array<string, array{list<array<string, mixed>>, int, string}> the user found, the acting user, the error */
	public static function refused(): array {
		return [
			'not found' => [[], 1, 'error userDel error: User not found'],
			'admin' => [[['id' => 2, 'username' => 'admin']], 1, "error userDel error: User 'admin' cannot be deleted"],
			'themselves' => [[['id' => 7, 'username' => 'alice']], 7, "error userDel error: User 'alice' cannot delete themselves"],
		];
	}

	/** @param list<array<string, mixed>> $users */
	#[DataProvider('refused')]
	public function testUserDelRefused(array $users, int $acting, string $error): void {
		$this->users = $users;
		$this->assertFalse((new UserService())->userDel(7, $acting, 'admin'));
		$this->assertSame([[], [$error]], [$this->db->changes, $this->log->lines]);
	}

	public function testUserDelFailure(): void {
		$this->db->affect = fn (): never => throw new RuntimeException('server has gone away');
		$this->assertFalse((new UserService())->userDel(7, 1, 'admin'));
		$this->assertSame(["error userDel error: delete of 'alice' by 'admin' failed: server has gone away"], $this->log->lines);
	}

	// stored with its username trimmed and lower case, the password as given
	public function testUserAdd(): void {
		$this->assertTrue((new UserService())->userAdd(['username' => ' Bob ', 'password' => 'EXTERNAL_AUTH', 'email' => 'bob@example.org', 'disable_notifications' => null]));
		$row = $this->db->rows('users')[0] ?? [];
		$this->assertSame(['bob', 'EXTERNAL_AUTH', 'bob@example.org', 0], [$row['username'] ?? null, $row['password'] ?? null, $row['email'] ?? null, $row['disable_notifications'] ?? null]);
		$this->assertSame([], $this->log->lines);
	}

	public function testUserAddFails(): void {
		$this->db->failInsertInto['users'] = new RuntimeException('Duplicate entry');
		$this->assertFalse((new UserService())->userAdd(['username' => 'bob', 'password' => 'EXTERNAL_AUTH']));
		$this->assertSame(["error userAdd error: Duplicate entry\n"], $this->log->lines);
	}
}
