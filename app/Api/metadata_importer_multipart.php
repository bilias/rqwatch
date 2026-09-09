<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/
define('API_MODE', true);

/*
 Boot without a database if it is unreachable, so the metadata can still
 be spooled to Redis and imported later by cron:import_spool. Only this
 entry point: every other API and the web UI must answer 503 instead.
 The Kernel still refuses unless Redis holds a completed migration status.
*/
define('ALLOW_DEGRADED_DB', true);

use App\Core\Kernel;

require_once __DIR__ . '/../Core/Kernel.php';

use Symfony\Component\HttpFoundation\Request;
use App\Api\MetadataImporterMultipartApi;

(new Kernel())->boot();

// Create request from globals
$request = Request::createFromGlobals();

// Instantiate and execute the API handler
$api = new MetadataImporterMultipartApi($request);
$api->handle();
