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
use App\Console\CronImportSpool;
use App\Console\CronFuzzyCleanup;

use App\Console\UserAdd;

use App\Console\MigrateMailRecipients;
use App\Console\MigrateCreatedDay;
use App\Console\MigrateMailLogData;
use App\Console\MigrateIdActionIndex;
use App\Console\MigrateMailLogTokens;
use App\Console\MigrateIpCreatedDayIndex;
use App\Console\MigrateDropMailLogColumns;
use App\Console\MigrateDropMailLogIndexes;
use App\Console\MigrateMapUserConstraints;
use App\Console\MigrateUserNotificationsDefault;
use App\Console\MigrateAdminNotified;
use App\Console\MigrateMailLogFuzzy;
use App\Console\MigrateMailLogFuzzyHits;
use App\Console\MigrateEverStored;
use App\Console\MigrateSessionVersion;
use App\Console\MigrateVirusSkipMap;
use App\Console\MigrateMapHits;
use App\Console\MigrateMailLogViruses;
use App\Console\MigrateMailLogFuzzyHitsMails;

use App\Console\MigrateDb;
use App\Console\OptimizeTable;
use App\Console\MapHitsBackfill;

(new Kernel())->boot();

$application = new Application();

// ... register commands
// fileLogger and syslogLogger come from bootstrap
$application->addCommand(new CronNotifications());
$application->addCommand(new CronQuarantine());
$application->addCommand(new CronCleanupDb());
$application->addCommand(new CronUpdateMapFiles());
$application->addCommand(new CronImportSpool());
$application->addCommand(new CronFuzzyCleanup());
$application->addCommand(new UserAdd());
$application->addCommand(new OptimizeTable());
$application->addCommand(new MapHitsBackfill());
$application->addCommand(new MigrateMailRecipients());
$application->addCommand(new MigrateCreatedDay());
$application->addCommand(new MigrateMailLogData());
$application->addCommand(new MigrateIdActionIndex());
$application->addCommand(new MigrateMailLogTokens());
$application->addCommand(new MigrateIpCreatedDayIndex());
$application->addCommand(new MigrateDropMailLogColumns());
$application->addCommand(new MigrateDropMailLogIndexes());
$application->addCommand(new MigrateMapUserConstraints());
$application->addCommand(new MigrateUserNotificationsDefault());
$application->addCommand(new MigrateAdminNotified());
$application->addCommand(new MigrateMailLogFuzzy());
$application->addCommand(new MigrateMailLogFuzzyHits());
$application->addCommand(new MigrateEverStored());
$application->addCommand(new MigrateSessionVersion());
$application->addCommand(new MigrateVirusSkipMap());
$application->addCommand(new MigrateMapHits());
$application->addCommand(new MigrateMailLogViruses());
$application->addCommand(new MigrateMailLogFuzzyHitsMails());
$application->addCommand(new MigrateDb());

$application->run();
