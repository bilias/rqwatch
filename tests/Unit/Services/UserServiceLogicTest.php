<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 UserService beside its account queries. Notifications for users who have
 not chosen follow user_notifications_default (on when unset), but off only
 once its migration has run, else on, logged; read once per service. An
 address is silenced when every user holding it (by email or alias) is off,
 an undecided one following the default, and an address no one holds too;
 asked once per address, any case or spacing. A list of addresses is
 silenced when every one is; no addresses are not. A user exists by
 username, trimmed and lower case. The login throttle's blocks and counters
 are listed, an IP's cleared, only with Redis; an IP that is not one is
 refused, logged. RecordingDatabase answers the queries, a stub client
 stands in for Redis.
*/

namespace Tests\Unit\Services;

use App\Configuration\Config;
use App\Core\App;
use App\Core\AppContainer;
use App\Core\Cache\RedisCache;
use App\Core\Database\MigrationStatus;
use App\Inventory\Migrations;
use App\Services\UserService;
use Illuminate\Database\Eloquent\Model;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Redis;
use ReflectionProperty;
use Tests\Unit\RecordingDatabase;
use Tests\Unit\UnitTestCase;

final class UserServiceLogicTest extends UnitTestCase
{
	private const string HOLDERS = 'select "id", "disable_notifications" from "users" where "email" = ?'
		. ' or "id" in (select "user_id" from "mail_aliases" where "alias" = ?)';
	private const string NOT_MIGRATED = 'warning user_notifications_default is off but migration'
		. ' 20261005_user_notifications_default is not completed, notifying by default';

	private RecordingDatabase $db;

	private MigrationStatus $status;

	/** @var array<string, list<array<string, mixed>>> address => the users holding it */
	private array $holders = [];

	/** @var list<string> keys deleted from the stub Redis */
	private array $deleted = [];

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->db = new RecordingDatabase($this->createStub(PDO::class));
		$this->db->capsule->bootEloquent();
		$this->db->answer = function (string $sql, array $bindings): array {
			/** @var mixed $address */
			$address = $bindings[0] ?? null;
			return is_string($address) ? $this->holders[$address] ?? [] : [];
		};
		$this->status = new MigrationStatus(null, $this->log);
		$this->swap(null);
		// without a warning: no key or offset may be missing
		set_error_handler(fn (int $no, string $message): never => throw new \ErrorException($message, 0, $no));
	}

	#[\Override]
	protected function tearDown(): void {
		restore_error_handler();
		Model::unsetConnectionResolver();
		RecordingDatabase::forget();
		parent::tearDown();
	}

	private function swap(?RedisCache $cache, bool $withStatus = true): void {
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, $this->db->capsule, $withStatus ? $this->status : null, $cache, false));
	}

	private function defaultOff(bool $migrated): void {
		Config::set('user_notifications_default', false);
		if ($migrated) {
			$this->status->setMigrationState(Migrations::USER_NOTIFICATIONS_DEFAULT, Migrations::STATUS_COMPLETED);
		}
	}

	// a RedisCache on a stub client holding one block and one counter
	private function redis(): RedisCache {
		$keys = ['rqwatch_throttle:block:192.0.2.9' => 300, 'rqwatch_throttle:count:198.51.100.4' => 60, 'rqwatch_throttle:count:192.0.2.9' => 45];
		$redis = $this->createStub(Redis::class);
		$redis->method('scan')->willReturnCallback(function (mixed &$iterator, string $pattern) use ($keys): array {
			$iterator = 0;
			return array_values(array_filter(array_keys($keys), fn (string $key): bool => str_starts_with($key, substr($pattern, 0, -1))));
		});
		$redis->method('ttl')->willReturnCallback(fn (string $key): int => $keys[$key] ?? -2);
		$redis->method('get')->willReturnCallback(fn (string $key): string|false => match ($key) {
			'rqwatch_throttle:count:192.0.2.9' => '4',
			'rqwatch_throttle:count:198.51.100.4' => '2',
			default => false,
		});
		$redis->method('del')->willReturnCallback(function (string $key): int {
			$this->deleted[] = $key;
			return 1;
		});
		$cache = new RedisCache($this->log);
		(new ReflectionProperty(RedisCache::class, 'client'))->setValue($cache, $redis);
		return $cache;
	}

	public function testNotificationsDefaultUnset(): void {
		$this->assertTrue((new UserService())->userNotificationsDefault());
		$this->assertSame([], $this->log->lines);
	}

	// on: the migration state is not even asked
	public function testNotificationsDefaultOn(): void {
		Config::set('user_notifications_default', true);
		$this->swap(null, false);
		$this->assertTrue((new UserService())->userNotificationsDefault());
	}

	public function testNotificationsDefaultOff(): void {
		$this->defaultOff(true);
		$this->assertFalse((new UserService())->userNotificationsDefault());
		$this->assertSame([], $this->log->lines);
	}

	// off before the migration: a stored 0 still means on
	public function testNotificationsDefaultOffNotMigrated(): void {
		$this->defaultOff(false);
		$this->assertTrue((new UserService())->userNotificationsDefault());
		$this->assertSame([self::NOT_MIGRATED], $this->log->lines);
	}

	// read once: a later change is not seen, the warning not repeated
	public function testNotificationsDefaultReadOnce(): void {
		$this->defaultOff(false);
		$service = new UserService();
		$service->userNotificationsDefault();
		// migrated now: read again, the default would be off
		$this->status->setMigrationState(Migrations::USER_NOTIFICATIONS_DEFAULT, Migrations::STATUS_COMPLETED);
		$this->assertTrue($service->userNotificationsDefault());
		$this->assertSame([self::NOT_MIGRATED], $this->log->lines);
	}

	public function testHoldersAsked(): void {
		(new UserService())->notificationsDisabledFor(" Alice@Example.ORG\t");
		$this->assertSame([[self::HOLDERS, ['alice@example.org', 'alice@example.org']]], $this->db->selects);
	}

	/** @return array<string, array{list<?int>, bool, bool}> each holder's disable_notifications, default off, silenced */
	public static function holders(): array {
		return [
			'no one, default on' => [[], false, false],
			'no one, default off' => [[], true, true],
			'off' => [[1], false, true],
			'on' => [[0], true, false],
			'every holder off' => [[1, 1], false, true],
			'one holder on' => [[1, 0], true, false],
			'undecided, default on' => [[null], false, false],
			'undecided, default off' => [[null], true, true],
			'off and undecided, default on' => [[1, null], false, false],
			'off and undecided, default off' => [[1, null], true, true],
		];
	}

	/** @param list<?int> $disabled */
	#[DataProvider('holders')]
	public function testNotificationsDisabledFor(array $disabled, bool $defaultOff, bool $silenced): void {
		if ($defaultOff) {
			$this->defaultOff(true);
		}
		$this->holders['alice@example.org'] = array_map(
			fn (int $id, ?int $off): array => ['id' => $id, 'disable_notifications' => $off],
			array_keys($disabled), $disabled
		);
		$this->assertSame($silenced, (new UserService())->notificationsDisabledFor('alice@example.org'));
	}

	// asked once per address, whatever its case or spacing
	public function testNotificationsDisabledForAskedOnce(): void {
		$this->holders['alice@example.org'] = [['id' => 1, 'disable_notifications' => 1]];
		$service = new UserService();
		$this->assertSame([true, true], [$service->notificationsDisabledFor('alice@example.org'), $service->notificationsDisabledFor(' ALICE@example.org')]);
		$this->assertCount(1, $this->db->selects);
	}

	/** @return array<string, array{list<string>}> */
	public static function noAddresses(): array {
		return ['none' => [[]], 'blanks' => [['', " \t"]]];
	}

	/** no addresses: not silenced, nothing asked
	 * @param list<string> $emails
	 */
	#[DataProvider('noAddresses')]
	public function testNotificationsDisabledForAllWithout(array $emails): void {
		$this->assertSame([false, []], [(new UserService())->notificationsDisabledForAll($emails), $this->db->selects]);
	}

	public function testNotificationsDisabledForAll(): void {
		$this->holders['alice@example.org'] = [['id' => 1, 'disable_notifications' => 1]];
		$this->holders['bob@example.org'] = [['id' => 2, 'disable_notifications' => 1]];
		$this->assertTrue((new UserService())->notificationsDisabledForAll([' alice@example.org ', '', 'bob@example.org']));
		$this->assertSame(['alice@example.org', 'bob@example.org'], array_map(fn (array $select): mixed => $select[1][0], $this->db->selects));
	}

	// one address to notify is enough: the rest are not asked
	public function testNotificationsDisabledForAllButOne(): void {
		$this->holders['alice@example.org'] = [['id' => 1, 'disable_notifications' => 1]];
		$this->holders['bob@example.org'] = [['id' => 2, 'disable_notifications' => 0]];
		$this->assertFalse((new UserService())->notificationsDisabledForAll(['alice@example.org', 'bob@example.org', 'carol@example.org']));
		$this->assertCount(2, $this->db->selects);
	}

	/** @return array<string, array{string}> */
	public static function noUsername(): array {
		return ['empty' => [''], "'0'" => ['0']];
	}

	#[DataProvider('noUsername')]
	public function testNoUsername(string $username): void {
		$this->assertSame([false, []], [(new UserService())->userExists($username), $this->db->selects]);
	}

	/** @return array<string, array{int, bool}> */
	public static function existence(): array {
		return ['exists' => [1, true], 'does not' => [0, false]];
	}

	#[DataProvider('existence')]
	public function testUserExists(int $exists, bool $expected): void {
		$this->db->answer = static fn (): array => [['exists' => $exists]];
		$this->assertSame($expected, (new UserService())->userExists(' Alice '));
		$this->assertSame([['select exists(select * from "users" where "username" = ?) as "exists"', ['alice']]], $this->db->selects);
	}

	public function testThrottlesWithoutRedis(): void {
		$this->assertSame(['blocked' => [], 'counters' => []], (new UserService())->getLoginThrottles());
	}

	public function testThrottles(): void {
		$this->swap($this->redis());
		$this->assertSame([
			'blocked' => ['192.0.2.9' => 300],
			'counters' => ['192.0.2.9' => ['count' => 4, 'ttl' => 45], '198.51.100.4' => ['count' => 2, 'ttl' => 60]],
		], (new UserService())->getLoginThrottles());
	}

	public function testClearThrottle(): void {
		$this->swap($this->redis());
		$this->assertTrue((new UserService())->clearLoginThrottle(" 2001:db8::1\t"));
		$this->assertSame(['rqwatch_throttle:count:2001:db8::1', 'rqwatch_throttle:block:2001:db8::1'], $this->deleted);
		$this->assertSame([], $this->log->lines);
	}

	public function testClearThrottleWithoutRedis(): void {
		$this->assertFalse((new UserService())->clearLoginThrottle('192.0.2.9'));
		$this->assertSame([], $this->log->lines);
	}

	/** @return array<string, array{string, string}> given, logged */
	public static function notIps(): array {
		return [
			'empty' => ['', ''],
			'blanks' => [" \t", ''],
			'a name' => ['host.example.org', 'host.example.org'],
			'out of range' => ['192.0.2.256', '192.0.2.256'],
		];
	}

	#[DataProvider('notIps')]
	public function testClearThrottleNotAnIp(string $ip, string $logged): void {
		$this->swap($this->redis());
		$this->assertFalse((new UserService())->clearLoginThrottle($ip));
		$this->assertSame([["warning [clearLoginThrottle] invalid IP: '{$logged}'"], []], [$this->log->lines, $this->deleted]);
	}

	/** @return array<string, array{string, string, bool, bool}> REDIS_ENABLE, LOGIN_THROTTLE_ENABLE, Redis, available */
	public static function availability(): array {
		return [
			'on, with Redis' => ['true', 'true', true, true],
			'on, no Redis' => ['true', 'true', false, false],
			'throttle off' => ['true', 'false', true, false],
			'Redis off' => ['false', 'true', true, false],
		];
	}

	#[DataProvider('availability')]
	public function testThrottleAvailable(string $redisEnabled, string $throttleEnabled, bool $redis, bool $available): void {
		$this->setEnv('REDIS_ENABLE', $redisEnabled);
		$this->setEnv('LOGIN_THROTTLE_ENABLE', $throttleEnabled);
		$this->swap($redis ? $this->redis() : null);
		$this->assertSame($available, (new UserService())->loginThrottleAvailable());
	}
}
