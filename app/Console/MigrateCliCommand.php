<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Console;

use App\Core\App;

use App\Inventory\Migrations;
use App\Core\Database\Migrations\AbstractMigration;

class MigrateCliCommand extends RqwatchCliCommand
{
	protected function createMigration(string $migration): AbstractMigration {
		return Migrations::create($migration);
	}

}
