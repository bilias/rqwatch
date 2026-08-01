<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

define('WEB_MODE', true);

use App\Core\Kernel;
use App\Core\Routing\Router;

require_once __DIR__ . '/../app/Core/Kernel.php';
require_once __DIR__ . '/../app/Core/Routing/Router.php';

$services = Kernel::boot();
Router::run();
