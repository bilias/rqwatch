<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The migration state as its readers see it, without a database: each
 migration is completed only once recorded so, a table written during a
 backfill from RUNNING on, and a name this version does not know is an
 error. The boot refuses while a required migration is not completed.
 Without a capsule (a degraded boot) warming reads nothing. The Redis
 mirror is written as a JSON object under this node's key, no TTL;
 a Redis failure is a warning, never an error of the boot.
*/

namespace Tests\Unit\Core;

use App\Configuration\Config;
use App\Core\Database\MigrationStatus;
use App\Inventory\Migrations;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Tests\Unit\RecordingCache;
use Tests\Unit\UnitTestCase;

final class MigrationStatusTest extends UnitTestCase
{
	private const string HELP = 'https://github.com/bilias/rqwatch/blob/master/docs/DB_MIGRATION.md';

	private function migrations(?RecordingCache $cache = null): MigrationStatus {
		return new MigrationStatus(null, $this->log, $cache);
	}

	/** @return array<string, array{\Closure(MigrationStatus): bool, string}> accessor, migration */
	public static function completed(): array {
		return [
			'mailRecipientsCompleted' => [fn (MigrationStatus $s): bool => $s->mailRecipientsCompleted(), Migrations::MAIL_RECIPIENTS],
			'mailLogDataCompleted' => [fn (MigrationStatus $s): bool => $s->mailLogDataCompleted(), Migrations::MAIL_LOG_DATA],
			'createdDayCompleted' => [fn (MigrationStatus $s): bool => $s->createdDayCompleted(), Migrations::CREATED_DAY],
			'mailLogTokensCompleted' => [fn (MigrationStatus $s): bool => $s->mailLogTokensCompleted(), Migrations::MAIL_LOG_TOKENS],
			'userNotificationsDefaultCompleted' => [fn (MigrationStatus $s): bool => $s->userNotificationsDefaultCompleted(), Migrations::USER_NOTIFICATIONS_DEFAULT],
			'adminNotifiedCompleted' => [fn (MigrationStatus $s): bool => $s->adminNotifiedCompleted(), Migrations::ADMIN_NOTIFIED],
			'mailLogFuzzyCompleted' => [fn (MigrationStatus $s): bool => $s->mailLogFuzzyCompleted(), Migrations::MAIL_LOG_FUZZY],
			'mailLogFuzzyHitsCompleted' => [fn (MigrationStatus $s): bool => $s->mailLogFuzzyHitsCompleted(), Migrations::MAIL_LOG_FUZZY_HITS],
			'everStoredCompleted' => [fn (MigrationStatus $s): bool => $s->everStoredCompleted(), Migrations::EVER_STORED],
			'virusSkipMapCompleted' => [fn (MigrationStatus $s): bool => $s->virusSkipMapCompleted(), Migrations::VIRUS_SKIP_MAP],
			'sessionVersionCompleted' => [fn (MigrationStatus $s): bool => $s->sessionVersionCompleted(), Migrations::SESSION_VERSION],
			'mapHitsCompleted' => [fn (MigrationStatus $s): bool => $s->mapHitsCompleted(), Migrations::MAP_HITS],
			'mailLogVirusesCompleted' => [fn (MigrationStatus $s): bool => $s->mailLogVirusesCompleted(), Migrations::MAIL_LOG_VIRUSES],
			'mailLogFuzzyHitsMailsCompleted' => [fn (MigrationStatus $s): bool => $s->mailLogFuzzyHitsMailsCompleted(), Migrations::MAIL_LOG_FUZZY_HITS_MAILS],
		];
	}

	/** @param \Closure(MigrationStatus): bool $accessor */
	#[DataProvider('completed')]
	public function testCompleted(\Closure $accessor, string $migration): void {
		$status = $this->migrations();
		$read = fn (): bool => $accessor($status);
		$this->assertFalse($read());
		$status->setMigrationState($migration, Migrations::STATUS_RUNNING);
		$this->assertFalse($read());
		// another migration completed is not this one
		foreach (Migrations::MIGRATIONS as $other) {
			if ($other !== $migration) {
				$status->setMigrationState($other, Migrations::STATUS_COMPLETED);
			}
		}
		$this->assertFalse($read());
		$status->setMigrationState($migration, Migrations::STATUS_COMPLETED);
		$this->assertTrue($read());
	}

	/** @return array<string, array{?string, bool}> */
	public static function writable(): array {
		return [
			'not recorded' => [null, false],
			'pending' => [Migrations::STATUS_PENDING, false],
			'running' => [Migrations::STATUS_RUNNING, true],
			'completed' => [Migrations::STATUS_COMPLETED, true],
			'failed' => [Migrations::STATUS_FAILED, false],
		];
	}

	#[DataProvider('writable')]
	public function testWritable(?string $state, bool $writable): void {
		$viruses = $this->migrations();
		$viruses->setMigrationState(Migrations::MAIL_LOG_VIRUSES, $state);
		$viruses->setMigrationState(Migrations::MAIL_LOG_FUZZY_HITS_MAILS, Migrations::STATUS_COMPLETED);
		$this->assertSame($writable, $viruses->mailLogVirusesWritable());

		$hitsMails = $this->migrations();
		$hitsMails->setMigrationState(Migrations::MAIL_LOG_FUZZY_HITS_MAILS, $state);
		$hitsMails->setMigrationState(Migrations::MAIL_LOG_VIRUSES, Migrations::STATUS_COMPLETED);
		$this->assertSame($writable, $hitsMails->mailLogFuzzyHitsMailsWritable());
	}

	public function testAllStates(): void {
		$status = $this->migrations();
		$this->assertSame(array_fill_keys(Migrations::MIGRATIONS, null), $status->getAllMigrationStates());
		$status->setMigrationState(Migrations::MAP_HITS, Migrations::STATUS_FAILED);
		$this->assertSame(
			array_replace(array_fill_keys(Migrations::MIGRATIONS, null), [Migrations::MAP_HITS => Migrations::STATUS_FAILED]),
			$status->getAllMigrationStates()
		);
	}

	public function testRequiredCompleted(): void {
		$status = $this->migrations();
		foreach (Migrations::REQUIRED as $migration) {
			$status->setMigrationState($migration, Migrations::STATUS_COMPLETED);
		}
		$status->verifyRequiredMigrations();
		$this->assertSame([], $this->log->lines);
	}

	// the first one not completed, in order
	public function testRequiredNotCompleted(): void {
		$status = $this->migrations();
		$status->setMigrationState(Migrations::MAIL_RECIPIENTS, Migrations::STATUS_COMPLETED);
		$status->setMigrationState(Migrations::CREATED_DAY, Migrations::STATUS_RUNNING);
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("Required migration '" . Migrations::CREATED_DAY . "' is not completed. See: " . self::HELP);
		$status->verifyRequiredMigrations();
	}

	public function testRequiredLastNotCompleted(): void {
		$status = $this->migrations();
		foreach (Migrations::REQUIRED as $migration) {
			$status->setMigrationState($migration, Migrations::STATUS_COMPLETED);
		}
		$status->setMigrationState(Migrations::IP_CREATED_DAY_INDEX, null);
		$this->expectExceptionMessage("Required migration '" . Migrations::IP_CREATED_DAY_INDEX . "' is not completed");
		$status->verifyRequiredMigrations();
	}

	public function testSetUnknown(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Unknown migration: 20991231_none');
		$this->migrations()->setMigrationState('20991231_none', Migrations::STATUS_COMPLETED);
	}

	public function testGetUnknown(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Unknown migration : 20991231_none');
		$this->migrations()->getMigrationState('20991231_none');
	}

	public function testTableFlag(): void {
		$status = $this->migrations();
		$this->assertFalse($status->hasMigrationsTable());
		$status->setMigrationTableExists(true);
		$this->assertTrue($status->hasMigrationsTable());
		$status->setMigrationTableExists(false);
		$this->assertFalse($status->hasMigrationsTable());
	}

	// a degraded boot: the table is there, the capsule is not
	public function testWarmWithoutCapsule(): void {
		$cache = new RecordingCache();
		$status = $this->migrations($cache);
		$status->setMigrationTableExists(true);
		$status->setMigrationState(Migrations::MAP_HITS, Migrations::STATUS_COMPLETED);
		$status->warmCache();
		$this->assertTrue($status->mapHitsCompleted());
		$this->assertSame([], $cache->values);
		$this->assertSame([], $this->log->lines);
	}

	public function testNoCacheNoMirror(): void {
		$this->assertFalse($this->migrations()->loadPersistedState());
		$this->assertSame([], $this->log->lines);
	}

	private static function persist(MigrationStatus $status): void {
		(new ReflectionMethod(MigrationStatus::class, 'persistState'))->invoke($status);
	}

	private function persistedCache(): RecordingCache {
		$this->setEnv('MY_API_SERVER_ALIAS', 'mx 1');
		Config::set('migration_status_redis_key', 'rqwatch_migration_status');
		return new RecordingCache();
	}

	// an empty state is an object too
	public function testPersistedEmpty(): void {
		$cache = $this->persistedCache();
		self::persist($this->migrations($cache));
		$this->assertSame(['rqwatch_migration_status:mx%201' => '{}'], $cache->values);
	}

	public function testPersisted(): void {
		$cache = $this->persistedCache();
		$status = $this->migrations($cache);
		$status->setMigrationState(Migrations::MAP_HITS, Migrations::STATUS_COMPLETED);
		$status->setMigrationState(Migrations::EVER_STORED, null);
		self::persist($status);
		$this->assertSame(['rqwatch_migration_status:mx%201' => '{"' . Migrations::MAP_HITS . '":"completed","' . Migrations::EVER_STORED . '":null}'], $cache->values);
		$this->assertSame(['rqwatch_migration_status:mx%201' => null], $cache->ttls);
		$this->assertSame([], $this->log->lines);
	}

	public function testPersistNoCache(): void {
		$this->setEnv('MY_API_SERVER_ALIAS', 'mx1');
		self::persist($this->migrations());
		$this->assertSame([], $this->log->lines);
	}

	// a status that is not UTF-8 cannot be encoded: nothing is written
	public function testPersistNotEncodable(): void {
		$this->setEnv('MY_API_SERVER_ALIAS', 'mx1');
		$cache = new RecordingCache();
		$status = $this->migrations($cache);
		$status->setMigrationState(Migrations::MAP_HITS, "\xFF");
		self::persist($status);
		$this->assertSame([], $cache->values);
		$this->assertSame([], $this->log->lines);
	}

	public function testPersistRedisDown(): void {
		$this->setEnv('MY_API_SERVER_ALIAS', 'mx1');
		$cache = new RecordingCache();
		$cache->failing = true;
		self::persist($this->migrations($cache));
		$this->assertSame(['warning Cannot persist migration status to Redis: Redis is down'], $this->log->lines);
	}

	public function testPersistNoAlias(): void {
		$this->setEnv('MY_API_SERVER_ALIAS', null);
		$cache = new RecordingCache();
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('MY_API_SERVER_ALIAS is not set in .env');
		self::persist($this->migrations($cache));
	}
}
