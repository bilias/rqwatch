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
public const string VERSION = '1.7.0';

public const string APP_NAME = 'Rqwatch';

// Prefix to use in syslog
public const string SYSLOG_PREFIX = 'rqwatch';

public const string LOG_FILE = APP_ROOT . '/logs/rqwatch.log';

public const string APP_INFO = 'Rspamd Quarantine Watch';

public const string APP_LOGO_PATH = '/images/logo.png';

public const string APP_LOGO_ALT = self::APP_NAME . ' logo';

// Paths to configuration files
public const string CONFIG_DEFAULT_PATH = APP_ROOT . '/config/config.php';
public const string CONFIG_LOCAL_PATH   = APP_ROOT . '/config/config.local.php';

// Composer autoload
public const string VENDOR_PATH = APP_ROOT . '/vendor/autoload.php';

// DB Config
public const string DB_CONFIG_PATH = APP_ROOT . '/config/db.php';

// Router Path
public const string ROUTER_PATH = APP_ROOT . '/app/Router.php';

// Routes Path
public const string ROUTES_PATH = APP_ROOT . '/config/routes.php';

// Environment file
public const string ENV_PATH = APP_ROOT . '/.env';

// Views directory
public const string VIEWS_PATH = APP_ROOT . '/app/Views';

// Directory for storing and serving map files

public const string MAP_DIR = APP_ROOT . '/web/maps/';

public const string GITHUB = 'https://github.com/bilias/rqwatch/';

// Default password hash for local users
// https://www.php.net/manual/en/function.password-hash.php
public const string DEFAULT_PASSWORD_HASH = PASSWORD_BCRYPT;

// Path to use for remote API mail release
public const string RELEASE_MAIL_API_PATH = '/api/release_mail.php';

// Path to use for remote API get mail
public const string GET_MAIL_API_PATH = '/api/get_mail.php';

// default REDIS
public const string REDIS_CONFIG_KEY = 'rqwatch_config';
public const int REDIS_CONFIG_CACHE_TTL = 300;

}
