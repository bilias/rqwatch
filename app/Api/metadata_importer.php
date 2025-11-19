<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/
define('API_MODE', true);

use App\Kernel;

require_once __DIR__ . '/../Kernel.php';

use Symfony\Component\HttpFoundation\Request;
use App\Api\MetadataImporterApi;

$services = Kernel::boot();
$fileLogger = $services['fileLogger'];
$syslogLogger = $services['syslogLogger'];
$startTime = $services['startTime'];
$startMemory = $services['startMemory'];
$capsule = $services['capsule'];

// Create request from globals
$request = Request::createFromGlobals();

// Instantiate and execute the API handler
$api = new MetadataImporterApi($request, $fileLogger, $syslogLogger, $startTime, $startMemory, $capsule);
$api->handle();
