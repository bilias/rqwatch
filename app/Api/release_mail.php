<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/
define('API_MODE', true);

require_once __DIR__ . '/../../bootstrap.php';

use Symfony\Component\HttpFoundation\Request;
use App\Api\ReleaseMailApi;

// Create request from globals
$request = Request::createFromGlobals();

// Instantiate and execute the API handler
$api = new ReleaseMailApi($request, $fileLogger, $syslogLogger, $startTime, $startMemory);
$api->handle();
