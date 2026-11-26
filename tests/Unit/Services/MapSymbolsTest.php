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
 nothing valid is left.
*/

namespace Tests\Unit\Services;

use App\Configuration\Config;
use App\Core\App;
use App\Core\AppContainer;
use App\Core\Database\MigrationStatus;
use App\Services\MapService;
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
