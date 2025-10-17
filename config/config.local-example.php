<?php
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU Affero General Public License version 3
as published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU Affero General Public License for more details.

You should have received a copy of the GNU Affero General Public License
along with this program.  If not, see <https://www.gnu.org/licenses/>.
*/

/**************************************************
 This file is not tracked by git.
 Override config.php default variables here.

 If Redis is enabled, then there is Config caching.
 REDIS_CONFIG_CACHE_TTL (default 300 seconds)
 in .env controls refresh period.
**************************************************/

# define all API server aliases and their API urls
$API_SERVERS = array(
	'mx1' => array(
		'url' => 'https://mx1.example.com',
	),
	'mx2' => array(
		'url' => 'https://mx2.example.com',
	),
);

#$geoip_enable=true;
