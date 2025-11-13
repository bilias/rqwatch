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
public const VERSION = '1.6.9-dev2';

public const APP_NAME = 'Rqwatch';

// Prefix to use in syslog
public const SYSLOG_PREFIX = 'rqwatch';

public const LOG_FILE = APP_ROOT . '/logs/rqwatch.log';

public const APP_INFO = 'Rspamd Quarantine Watch';

public const APP_LOGO_PATH = '/images/logo.png';

public const APP_LOGO_ALT = self::APP_NAME . ' logo';

// Paths to configuration files
public const CONFIG_DEFAULT_PATH = APP_ROOT . '/config/config.php';
public const CONFIG_LOCAL_PATH   = APP_ROOT . '/config/config.local.php';

// Composer autoload
public const VENDOR_PATH = APP_ROOT . '/vendor/autoload.php';

// Environment file
public const ENV_PATH = APP_ROOT . '/.env';

// Views directory
public const VIEWS_PATH = APP_ROOT . '/app/Views';

// Directory for storing and serving map files

public const MAP_DIR = APP_ROOT . '/web/maps/';

public const GITHUB = 'https://github.com/bilias/rqwatch/';

// Default password hash for local users
// https://www.php.net/manual/en/function.password-hash.php
public const DEFAULT_PASSWORD_HASH = PASSWORD_BCRYPT;

// Path to use for remote API mail release
public const RELEASE_MAIL_API_PATH = '/api/release_mail.php';

// Path to use for remote API get mail
public const GET_MAIL_API_PATH = '/api/get_mail.php';

}
