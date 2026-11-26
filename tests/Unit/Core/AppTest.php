<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 App's accessors: a migration status the context does not have is an
 exception that says so, never a value of another type. App is
 initialized once; a second init() is refused.
*/

namespace Tests\Unit\Core;

use App\Core\App;
use App\Core\AppContainer;
use Tests\Unit\RecordingLogger;
use RuntimeException;
use Tests\Unit\UnitTestCase;

final class AppTest extends UnitTestCase
{
	// UnitTestCase's container has no migration status
	public function testNoMigrationStatus(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('MigrationStatus is not available in this context.');
		App::migrationStatus();
	}

	public function testInitOnce(): void {
		App::reset();
		$logger = new RecordingLogger();
		App::init(new AppContainer(0.0, 0, $logger, $logger, null, null, null, false));
		$this->assertSame($logger, App::fileLogger());
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('App has already been initialized.');
		App::init(new AppContainer(0.0, 0, $this->log, $this->log, null, null, null, false));
	}

	public function testNotInitialized(): void {
		App::reset();
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('App::init() has not been called.');
		App::fileLogger();
	}
}
