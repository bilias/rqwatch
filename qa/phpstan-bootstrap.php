<?php
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

// Larastan defines this only when it can boot a Laravel app; rqwatch has none
if (!defined('LARAVEL_VERSION')) {
	define('LARAVEL_VERSION', ltrim((string) \Composer\InstalledVersions::getPrettyVersion('illuminate/database'), 'v'));
}
