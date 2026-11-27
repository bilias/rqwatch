<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 MapService::mapSymbols() from $map_symbols, with map hits off (no
 custom maps read): a map name keeps its valid symbols, a single symbol
 counts as a list of one, and an entry that is not a map name => list of
 valid symbol names is logged, whole as configured, and skipped when
 nothing valid is left. With map hits on, each custom map with symbols
 adds its valid ones (comma-separated), in place of a configured map of
 the same name; one without a valid symbol is left out. RecordingDatabase
 answers the custom maps.
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
use Tests\Unit\RecordingDatabase;
use Tests\Unit\UnitTestCase;

final class MapSymbolsTest extends UnitTestCase
{
	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, null, new MigrationStatus(null, $this->log), null, false));
	}

	public function testNoConfig(): void {
		$this->assertSame([], MapService::mapSymbols());
		$this->assertSame([], $this->log->lines);
	}

	public function testValidEntries(): void {
		Config::set('map_symbols', [
			'ip_blacklist' => ['RQWATCH_IP_BLACKLIST'],
			'mail_from_whitelist' => ['RQWATCH_SMTP_FROM_WHITELIST', 'RQWATCH_WHITE_SMTP_FROM_DKIM_ALLOW'],
			'single' => 'RQWATCH_SINGLE',
		]);
		$this->assertSame([
			'ip_blacklist' => ['RQWATCH_IP_BLACKLIST'],
			'mail_from_whitelist' => ['RQWATCH_SMTP_FROM_WHITELIST', 'RQWATCH_WHITE_SMTP_FROM_DKIM_ALLOW'],
			'single' => ['RQWATCH_SINGLE'],
		], MapService::mapSymbols());
		$this->assertSame([], $this->log->lines);
	}

	public function testCustomMaps(): void {
		$db = new RecordingDatabase($this->createStub(PDO::class));
		$db->capsule->bootEloquent();
		$db->answer = fn (): array => [
			['map_name' => 'url_hosts', 'symbols' => 'RQWATCH_URL,bad symbol,RQWATCH_URL2'],
			['map_name' => 'nothing_valid', 'symbols' => 'bad!, RQWATCH_X'],
			['map_name' => 'ip_blacklist', 'symbols' => 'RQWATCH_CUSTOM_IP'],
		];
		$status = new MigrationStatus(null, $this->log);
		$status->setMigrationState(Migrations::MAP_HITS, Migrations::STATUS_COMPLETED);
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, $db->capsule, $status, null, false));
		Config::set('map_symbols', ['ip_blacklist' => ['RQWATCH_IP_BLACKLIST'], 'mail_from_whitelist' => ['RQWATCH_FROM']]);
		try {
			$this->assertSame([
				'ip_blacklist' => ['RQWATCH_CUSTOM_IP'],
				'mail_from_whitelist' => ['RQWATCH_FROM'],
				'url_hosts' => ['RQWATCH_URL', 'RQWATCH_URL2'],
			], MapService::mapSymbols());
			$this->assertSame([['select `symbols`, `map_name` from `custom_map_config` where `symbols` is not null', []]], $db->selects);
			$this->assertSame([], $this->log->lines);
		} finally {
			Model::unsetConnectionResolver();
			RecordingDatabase::forget();
		}
	}

	public function testInvalidEntries(): void {
		Config::set('map_symbols', [
			'123' => ['RQWATCH_DIGITS'],
			'empty' => [],
			'some invalid' => ['bad symbol!', 'RQWATCH_OK', 7],
			'bad utf8' => ["RQWATCH_\xff"],
			'not json' => [INF],
		]);
		$this->assertSame(['some invalid' => ['RQWATCH_OK']], MapService::mapSymbols());
		$line = fn (string $json): string => "error [MapService] invalid map_symbols entry {$json} in config: map name => list of symbol names";
		$this->assertSame([
			$line('{"123":["RQWATCH_DIGITS"]}'),
			$line('{"empty":[]}'),
			$line('{"some invalid":["bad symbol!","RQWATCH_OK",7]}'),
			$line('{"bad utf8":["RQWATCH_\ufffd"]}'),
			$line('{"not json":[0]}'),
		], $this->log->lines);
	}
}
