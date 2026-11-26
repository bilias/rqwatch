<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 MapService's static logic around its queries. The virus skip map is
 active once named (trimmed) and migrated; only that map, in any case and
 spacing, is protected. mappedSymbolSet() is every symbol a map names,
 read once per process; a failure to read is logged and gives none.
 prepareSymbols() keeps a custom map's symbols only with map hits on,
 trimmed and deduplicated, case kept, none as null. lines() splits at any
 line end, trimLower() trims and lowers values, keys kept, and
 getSqlFromQuery() shows a query with its bindings.
*/

namespace Tests\Unit\Services;

use App\Configuration\Config;
use App\Core\App;
use App\Core\AppContainer;
use App\Core\Database\MigrationStatus;
use App\Inventory\Migrations;
use App\Models\MapCustom;
use App\Services\MapService;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionProperty;
use Tests\Unit\UnitTestCase;

final class MapServiceLogicTest extends UnitTestCase
{
	private MigrationStatus $status;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->status = new MigrationStatus(null, $this->log);
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, null, $this->status, null, false));
		self::forgetSymbolSet();
	}

	#[\Override]
	protected function tearDown(): void {
		self::forgetSymbolSet();
		Model::unsetConnectionResolver();
		parent::tearDown();
	}

	private static function forgetSymbolSet(): void {
		(new ReflectionProperty(MapService::class, 'mappedSymbolSet'))->setValue(null, null);
	}

	// no migration state to ask, as without a database
	private function withoutMigrationStatus(): void {
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, null, null, null, false));
	}

	private function migrate(string $migration): void {
		$this->status->setMigrationState($migration, Migrations::STATUS_COMPLETED);
	}

	private static function call(string $method, mixed ...$arguments): mixed {
		return (new ReflectionMethod(MapService::class, $method))->invoke(null, ...$arguments);
	}

	// unnamed: the migration state is not even asked
	public function testVirusSkipMapUnnamed(): void {
		$this->withoutMigrationStatus();
		$this->assertNull(MapService::activeVirusSkipMap());
		Config::set('notification_skip_virus_map', "  \t");
		$this->assertNull(MapService::activeVirusSkipMap());
	}

	public function testVirusSkipMapNotMigrated(): void {
		Config::set('notification_skip_virus_map', 'virus_skip');
		$this->assertNull(MapService::activeVirusSkipMap());
	}

	public function testVirusSkipMapActive(): void {
		Config::set('notification_skip_virus_map', ' virus_skip ');
		$this->migrate(Migrations::VIRUS_SKIP_MAP);
		$this->assertSame('virus_skip', MapService::activeVirusSkipMap());
	}

	public function testProtectedMap(): void {
		Config::set('notification_skip_virus_map', 'Virus_Skip');
		$this->migrate(Migrations::VIRUS_SKIP_MAP);
		$this->assertSame(
			[true, true, false],
			[MapService::isProtectedMap('virus_skip'), MapService::isProtectedMap(" VIRUS_SKIP\t"), MapService::isProtectedMap('other')]
		);
	}

	// not yet migrated: no map is protected, the one named either
	public function testNoProtectedMapBeforeTheMigration(): void {
		Config::set('notification_skip_virus_map', 'virus_skip');
		$this->assertFalse(MapService::isProtectedMap('virus_skip'));
	}

	// each symbol once, read once: a later change of the config is not seen
	public function testMappedSymbolSet(): void {
		Config::set('map_symbols', ['ip_blacklist' => ['RQWATCH_A', 'RQWATCH_B'], 'mail_from_blacklist' => ['RQWATCH_B', 'RQWATCH_C']]);
		$set = ['RQWATCH_A' => true, 'RQWATCH_B' => true, 'RQWATCH_C' => true];
		$this->assertSame($set, MapService::mappedSymbolSet());
		Config::set('map_symbols', ['ip_blacklist' => ['RQWATCH_D']]);
		$this->assertSame($set, MapService::mappedSymbolSet());
	}

	// a failure to read: logged once, no symbols, also on the next call
	public function testMappedSymbolSetUnreadable(): void {
		$this->withoutMigrationStatus();
		Config::set('map_symbols', ['ip_blacklist' => ['RQWATCH_A']]);
		$this->assertSame([[], []], [MapService::mappedSymbolSet(), MapService::mappedSymbolSet()]);
		$this->assertSame(['error [MapService] cannot read map symbols: MigrationStatus is not available in this context.'], $this->log->lines);
	}

	// none submitted: none added, map hits on or not
	public function testPrepareSymbolsWithout(): void {
		$this->migrate(Migrations::MAP_HITS);
		$data = ['map_name' => 'm', 'field_name' => 'url'];
		$this->assertSame($data, self::call('prepareSymbols', $data));
	}

	// map hits off: the column may not exist yet
	public function testPrepareSymbolsBeforeMapHits(): void {
		$this->assertSame(
			['map_name' => 'm', 'field_name' => 'url'],
			self::call('prepareSymbols', ['map_name' => 'm', 'symbols' => 'RQWATCH_A', 'field_name' => 'url'])
		);
	}

	/** @return array<string, array{?string, ?string}> */
	public static function symbolLists(): array {
		return [
			'trimmed, deduplicated, case kept' => [' RQWATCH_A , RQWATCH_B,RQWATCH_A,, rqwatch_a ', 'RQWATCH_A,RQWATCH_B,rqwatch_a'],
			'one' => ['RQWATCH_A', 'RQWATCH_A'],
			'empty' => ['', null],
			'commas and blanks' => [' , ,', null],
			'none' => [null, null],
		];
	}

	#[DataProvider('symbolLists')]
	public function testPrepareSymbols(?string $submitted, ?string $stored): void {
		$this->migrate(Migrations::MAP_HITS);
		$this->assertSame(['map_name' => 'm', 'symbols' => $stored], self::call('prepareSymbols', ['map_name' => 'm', 'symbols' => $submitted]));
	}

	public function testLines(): void {
		$this->assertSame(['a', 'b', 'c', '', 'd'], self::call('lines', "a\r\nb\rc\n\nd"));
	}

	public function testTrimLower(): void {
		$this->assertSame(['mail_from' => 'a@example.org', 'rcpt_to' => ''], self::call('trimLower', ['mail_from' => " A@Example.ORG\t", 'rcpt_to' => ' ']));
	}

	public function testSqlFromQuery(): void {
		$capsule = new Capsule();
		$capsule->addConnection(['driver' => 'mysql', 'host' => '192.0.2.1', 'database' => 'rqwatch', 'username' => 'none', 'password' => 'none']);
		$capsule->bootEloquent();
		$this->assertSame(
			'select * from `maps_custom` where `map_name` = "50%" and `disabled` = "0"',
			MapService::getSqlFromQuery(MapCustom::query()->where('map_name', '50%')->where('disabled', 0))
		);
	}
}
