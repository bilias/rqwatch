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

use App\Kernel;

require_once __DIR__ . '/../app/Kernel.php';

use Symfony\Component\Console\Application;

use App\Console\CronNotifications;
use App\Console\CronQuarantine;
use App\Console\CronUpdateMapFiles;
use App\Console\UserAdd;

$services = Kernel::boot();
$fileLogger = $services['fileLogger'];
$syslogLogger = $services['syslogLogger'];

$application = new Application();

// ... register commands
// fileLogger and syslogLogger come from bootstrap
$application->add(new CronNotifications($fileLogger, $syslogLogger));
$application->add(new CronQuarantine($fileLogger, $syslogLogger));
$application->add(new CronUpdateMapFiles($fileLogger, $syslogLogger));
$application->add(new UserAdd($fileLogger, $syslogLogger));

$application->run();
