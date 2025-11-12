<?php
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

define('APP_VERSION', '1.6.9-dev');

// without trailing slash
define('APP_ROOT', realpath(__DIR__ . '/..'));

define('CONFIG_DEFAULT_PATH', APP_ROOT . '/config/config.php');
define('CONFIG_LOCAL_PATH', APP_ROOT . '/config/config.local.php');

define('APP_VIEWS_PATH', APP_ROOT . '/app/Views');
