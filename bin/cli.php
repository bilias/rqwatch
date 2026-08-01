#!/usr/bin/env php
<?php
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

define('CLI_MODE', true);

use App\Core\Kernel;

require_once __DIR__ . '/../app/Core/Kernel.php';

use Symfony\Component\Console\Application;

use App\Console\CronNotifications;
use App\Console\CronQuarantine;
use App\Console\CronCleanupDb;
use App\Console\CronUpdateMapFiles;
use App\Console\UserAdd;
use App\Console\MigrateMailRecipients;
use App\Console\MigrateCreatedDay;
use App\Console\MigrateMailLogData;
use App\Console\MigrateDb;

$services = Kernel::boot();
$fileLogger = $services['fileLogger'];
$syslogLogger = $services['syslogLogger'];
$capsule = $services['capsule'];
$migrationStatus = $services['migrationStatus'];

$application = new Application();

// ... register commands
// fileLogger and syslogLogger come from bootstrap
$application->add(new CronNotifications($migrationStatus));
$application->add(new CronQuarantine($migrationStatus));
$application->add(new CronCleanupDb($migrationStatus));
$application->add(new CronUpdateMapFiles());
$application->add(new UserAdd());
$application->add(new MigrateMailRecipients($capsule));
$application->add(new MigrateCreatedDay($capsule));
$application->add(new MigrateMailLogData($capsule));
$application->add(new MigrateDb($capsule));

$application->run();
