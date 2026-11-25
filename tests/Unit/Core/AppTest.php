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
 exception that says so, never a value of another type.
*/

namespace Tests\Unit\Core;

use App\Core\App;
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
}
