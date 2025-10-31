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
		'url' => 'http://127.0.0.1',
	),
/*
	'mx2' => array(
		'url' => 'https://mx2.example.com',
		'stat_url' => 'http://mx2.example.com:11334/stat',
		'options' => [
			'verify_peer' => true,
			'verify_host' => true,
			'capath' => '/etc/pki/tls/certs',
			'cafile' => '/etc/pki/tls/certs/mx2.crt',
		],
	),
*/
);

#$geoip_enable=true;
