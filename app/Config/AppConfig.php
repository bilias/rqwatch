<?php
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Config;

// Root path of the project (without trailing slash)
define('APP_ROOT', realpath(__DIR__ . '/../..'));

class AppConfig {

// Application version
public const VERSION = '1.6.9-dev';

// Paths to configuration files
public const CONFIG_DEFAULT_PATH = APP_ROOT . '/config/config.php';
public const CONFIG_LOCAL_PATH   = APP_ROOT . '/config/config.local.php';

// Composer autoload
public const VENDOR_PATH = APP_ROOT . '/vendor/autoload.php';

// Environment file
public const ENV_PATH = APP_ROOT . '/.env';

// Views directory
public const VIEWS_PATH = APP_ROOT . '/app/Views';

}
