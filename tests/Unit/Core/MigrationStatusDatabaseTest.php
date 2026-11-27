<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The migration state warmed from the migrations table, on
 RecordingDatabase: read once, on the first status asked for, only the
 migrations this version knows, and mirrored to Redis. Nothing is read
 without the table. A status that is not text is an error, as is any
 database error but the table having gone (42S02), which leaves the state
 empty and the table flagged gone, logged, the mirror untouched.
*/

namespace Tests\Unit\Core;

use App\Configuration\Config;
use App\Core\Database\MigrationStatus;
use App\Inventory\Migrations;
use Illuminate\Database\QueryException;
use PDO;
use PDOException;
use RuntimeException;
use Tests\Unit\RecordingCache;
use Tests\Unit\RecordingDatabase;
use Tests\Unit\UnitTestCase;

final class MigrationStatusDatabaseTest extends UnitTestCase
{
	private const string READ = 'select `status`, `migration` from `migrations`';

	private RecordingDatabase $db;

	private RecordingCache $cache;

	/** @var list<array<string, mixed>> */
	private array $table = [];

	private ?\Throwable $failure = null;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->db = new RecordingDatabase($this->createStub(PDO::class));
		$this->db->answer = function (string $sql, array $bindings): array {
			if ($this->failure !== null) {
				throw $this->failure;
			}
			return $this->table;
		};
		$this->setEnv('MY_API_SERVER_ALIAS', 'mx1');
		Config::set('migration_status_redis_key', 'rqwatch_migration_status');
		$this->cache = new RecordingCache();
	}

	#[\Override]
	protected function tearDown(): void {
		RecordingDatabase::forget();
		parent::tearDown();
	}

	private function migrations(bool $table = true): MigrationStatus {
		$status = new MigrationStatus($this->db->capsule, $this->log, $this->cache);
		$status->setMigrationTableExists($table);
		return $status;
	}

	private static function failure(string $code): QueryException {
		$previous = new PDOException("SQLSTATE[{$code}]");
		(new \ReflectionProperty(\Exception::class, 'code'))->setValue($previous, $code);
		return new QueryException('default', self::READ, [], $previous);
	}

	public function testWarmedOnce(): void {
		$this->table = [
			['migration' => Migrations::MAP_HITS, 'status' => Migrations::STATUS_COMPLETED],
			['migration' => Migrations::EVER_STORED, 'status' => null],
			['migration' => '20990101_future', 'status' => Migrations::STATUS_COMPLETED],
		];
		$status = $this->migrations();
		$this->assertTrue($status->mapHitsCompleted());
		$this->assertSame(Migrations::STATUS_COMPLETED, $status->getMigrationState(Migrations::MAP_HITS));
		$this->assertNull($status->getMigrationState(Migrations::EVER_STORED));
		$status->warmCache();
		$this->assertSame([[self::READ, []]], $this->db->selects);
		$this->assertSame(['rqwatch_migration_status:mx1' => '{"' . Migrations::EVER_STORED . '":null,"' . Migrations::MAP_HITS . '":"completed"}'], $this->cache->values);
		$this->assertSame([], $this->log->lines);
	}

	// empty, as the table is read: recorded as nothing completed
	public function testEmptyTableMirrored(): void {
		$status = $this->migrations();
		$this->assertFalse($status->mapHitsCompleted());
		$this->assertSame(['rqwatch_migration_status:mx1' => '{}'], $this->cache->values);
	}

	public function testNoTableNotRead(): void {
		$this->table = [['migration' => Migrations::MAP_HITS, 'status' => Migrations::STATUS_COMPLETED]];
		$status = $this->migrations(false);
		$this->assertFalse($status->mapHitsCompleted());
		$this->assertSame([[], []], [$this->db->selects, $this->cache->values]);
	}

	public function testStatusNotText(): void {
		$this->table = [['migration' => Migrations::MAP_HITS, 'status' => 1]];
		$this->expectExceptionObject(new RuntimeException('migrations holds a status that is not text'));
		$this->migrations()->warmCache();
	}

	public function testTableGone(): void {
		$this->failure = self::failure('42S02');
		$status = $this->migrations();
		$status->setMigrationState(Migrations::MAP_HITS, Migrations::STATUS_COMPLETED);
		$status->warmCache();
		$this->assertFalse($status->mapHitsCompleted());
		$this->assertFalse($status->hasMigrationsTable());
		$this->assertSame(['error migrations vanished while warming the migration state cache'], $this->log->lines);
		$this->assertSame([], $this->cache->values);
		// not read again
		$status->warmCache();
		$this->assertCount(1, $this->db->selects);
	}

	public function testOtherErrorThrown(): void {
		$failure = self::failure('HY000');
		$this->failure = $failure;
		$status = $this->migrations();
		try {
			$status->warmCache();
			$this->fail('not thrown');
		} catch (QueryException $e) {
			$this->assertSame($failure, $e);
		}
		$this->assertTrue($status->hasMigrationsTable());
		$this->assertSame([[], []], [$this->log->lines, $this->cache->values]);
	}
}
