<?php
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/**************************************************
 This file is not tracked by git.
 Override config.php default variables here.

 If Redis is enabled, then there is Config caching.
 REDIS_CONFIG_CACHE_TTL (default 300 seconds)
 in .env controls refresh period.
**************************************************/

# Define all API server aliases and their API urls.
# See config.php for all available options
$API_SERVERS = array(
	'mx1' => array(
		'url' => 'https://mx1.example.com',
	),
	'mx2' => array(
		'url' => 'https://mx2.example.com',
	),
);

#$geoip_enable=true;
