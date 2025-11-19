<?php
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

define('WEB_MODE', true);

use App\Kernel;

require_once __DIR__ . '/../app/Kernel.php';

$services = Kernel::boot();
Kernel::runRouter($services);
