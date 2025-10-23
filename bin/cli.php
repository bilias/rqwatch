#!/usr/bin/env php
<?php
define('CLI_MODE', true);

require_once __DIR__ . '/../bootstrap.php';

use Symfony\Component\Console\Application;

use App\Console\CronNotifications;
use App\Console\CronQuarantine;
use App\Console\CronUpdateMapFiles;
use App\Console\UserAdd;

$application = new Application();

// ... register commands
// fileLogger and syslogLogger come from bootstrap
$application->add(new CronNotifications($fileLogger, $syslogLogger));
$application->add(new CronQuarantine($fileLogger, $syslogLogger));
$application->add(new CronUpdateMapFiles($fileLogger, $syslogLogger));
$application->add(new UserAdd($fileLogger, $syslogLogger));

$application->run();
