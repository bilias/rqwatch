<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Every migration this version knows has its class, built by create() on
 the app's capsule (no connection is opened); any other name is refused.
*/

namespace Tests\Unit\Inventory;

use App\Core\App;
use App\Core\AppContainer;
use App\Inventory\Migrations;
use Illuminate\Database\Capsule\Manager as Capsule;
use InvalidArgumentException;
use Tests\Unit\UnitTestCase;

final class MigrationsTest extends UnitTestCase
{
	public function testEachCreated(): void {
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, new Capsule(), null, null, false));
		foreach (Migrations::MIGRATION_CLASSES as $migration => $class) {
			$this->assertSame($class, Migrations::create($migration)::class);
		}
	}

	public function testUnknownRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Unknown migration: 20991231_none');
		Migrations::create('20991231_none');
	}
}
