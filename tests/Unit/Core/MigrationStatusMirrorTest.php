<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The Redis mirror of the migration status is keyed by this node's alias.
 Without an alias a degraded boot finds nothing usable: it never falls
 back to a key another node may have written. A copy holding a status
 that is not text is not used either, and names this version does not
 know are left out.
*/

namespace Tests\Unit\Core;

use App\Configuration\Config;
use App\Core\Database\MigrationStatus;
use App\Inventory\Migrations;
use App\Utils\Helper;
use Tests\Unit\RecordingCache;
use Tests\Unit\UnitTestCase;

final class MigrationStatusMirrorTest extends UnitTestCase
{
	private const string BASE = 'rqwatch_migration_status';

	private ?string $savedAlias;
	private RecordingCache $cache;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->savedAlias = Helper::env_string('MY_API_SERVER_ALIAS');
		Config::set('migration_status_redis_key', self::BASE);
		$this->cache = new RecordingCache();
		$state = (string) json_encode([Migrations::MIGRATIONS[0] => Migrations::STATUS_COMPLETED]);
		$this->cache->values[self::BASE . ':mx1'] = $state;
		$this->cache->values[self::BASE . ':unknown'] = $state;
	}

	#[\Override]
	protected function tearDown(): void {
		unset($_ENV['MY_API_SERVER_ALIAS']);
		if ($this->savedAlias !== null) {
			$_ENV['MY_API_SERVER_ALIAS'] = $this->savedAlias;
		}
		parent::tearDown();
	}

	private function migrationStatus(): MigrationStatus {
		return new MigrationStatus(null, $this->log, $this->cache);
	}

	public function testOwnKeyIsRead(): void {
		$_ENV['MY_API_SERVER_ALIAS'] = 'mx1';
		$status = $this->migrationStatus();
		$this->assertTrue($status->loadPersistedState());
		$this->assertTrue($status->isMigrationCompleted(Migrations::MIGRATIONS[0]));
		$this->assertSame([self::BASE . ':mx1'], $this->cache->reads);
	}

	public function testNoAliasNoMirror(): void {
		unset($_ENV['MY_API_SERVER_ALIAS']);
		$this->assertFalse($this->migrationStatus()->loadPersistedState());
		$this->assertSame([], $this->cache->reads);
		$this->assertLogged('MY_API_SERVER_ALIAS is not set in .env');
	}

	public function testBlankAliasNoMirror(): void {
		$_ENV['MY_API_SERVER_ALIAS'] = '';
		$this->assertFalse($this->migrationStatus()->loadPersistedState());
		$this->assertSame([], $this->cache->reads);
		$this->assertLogged('MY_API_SERVER_ALIAS is empty in .env');
	}

	public function testStatusNotTextNotUsed(): void {
		$_ENV['MY_API_SERVER_ALIAS'] = 'mx1';
		$this->cache->values[self::BASE . ':mx1'] = (string) json_encode([
			Migrations::MIGRATIONS[0] => Migrations::STATUS_COMPLETED,
			Migrations::MIGRATIONS[1] => 1,
		]);
		$this->assertFalse($this->migrationStatus()->loadPersistedState());
		$this->assertLogged('Migration status in Redis holds a status that is not text; not used');
	}

	public function testUnknownNamesLeftOut(): void {
		$_ENV['MY_API_SERVER_ALIAS'] = 'mx1';
		$this->cache->values[self::BASE . ':mx1'] = (string) json_encode([
			Migrations::MIGRATIONS[0] => Migrations::STATUS_COMPLETED,
			'20991231_not_a_migration' => 5,
			Migrations::MIGRATIONS[1] => null,
		]);
		$status = $this->migrationStatus();
		$this->assertTrue($status->loadPersistedState());
		$this->assertTrue($status->isMigrationCompleted(Migrations::MIGRATIONS[0]));
		$this->assertNull($status->getMigrationState(Migrations::MIGRATIONS[1]));
		$this->assertSame([], $this->log->lines);
	}
}
