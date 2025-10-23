<?php
/*
 * This file is not tracked by git.
 *
 * Override config.php default variables here.
 *
 * If Redis is enabled, then there is Config caching.
 * REDIS_CONFIG_CACHE_TTL (default 300 seconds)
 * in .env controls refresh period.
*/

# Define all API server aliases, their urls and TLS options
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
