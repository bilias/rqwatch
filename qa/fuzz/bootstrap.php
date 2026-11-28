<?php
// Rqwatch
// Copyright (C) 2026 Giannis Kapetanakis
// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at http://mozilla.org/MPL/2.0/.

declare(strict_types=1);

// Shared set-up of the fuzz targets (qa/fuzz.sh), once per run: what
// Tests\Unit\UnitTestCase::setUp() does. NullLogger, not RecordingLogger,
// which would grow for the whole run. DNS and GeoIP off: no network.

use App\Configuration\Config;
use App\Core\App;
use App\Core\AppContainer;
use Psr\Log\NullLogger;

require __DIR__ . '/../../vendor/autoload.php';

$log = new NullLogger();
App::swap(new AppContainer(0.0, 0, $log, $log, null, null, null, false));
Config::init(['dns_resolv' => false, 'geoip_enable' => false]);
