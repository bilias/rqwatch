<?php
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

# Default configuration variables for Rqwatch.

# If you want to alter default configuration,
# override the variable in config.local.php
# This file is being tracked and updated by git,
# so local changes might be lost on update.

$APP_NAME="Rqwatch";
$APP_INFO="Rspamd Quarantine Watch";
$APP_VERSION = APP_VERSION;
$FOOTER="{$APP_NAME} v{$APP_VERSION}";

# Default CA path dir (capath) to verify remote API Servers SSL/TLS
$SYS_CA_PATH = "/etc/pki/tls/certs";

# Define all API server aliases and their API urls
# See https://symfony.com/doc/current/reference/configuration/framework.html#http-client
# for available entries in options
$API_SERVERS = array(
	'mx1' => [
		'url' => 'https://mx1.example.com',
		'stat_url' => 'http://mx1.example.com:11334/stat',
		'options' => [
			'verify_peer' => true,
			'verify_host' => true,
			'capath' => '/etc/pki/tls/certs',
			'cafile' => '/etc/pki/tls/certs/mx1.crt',
		],
	],
	'mx2' => [
		'url' => 'https://mx2.example.com',
		'stat_url' => 'http://127.0.0.1:11334/stat',
		'options' => [
			'verify_peer' => true,
			'verify_host' => true,
			'capath' => '/etc/pki/tls/certs',
			'cafile' => '/etc/pki/tls/certs/mx2.crt',
		],
	],
);

# Disable rspamd stats
$rspamd_stat_disable=false;

# Timeout for getting rspamd statistics
$rspamd_stat_api_timeout = 1.0;

# Redis key for caching rspamd stats
$rspamd_stat_redis_key = "rqwatch_rspamd_stats";

# How many seconds to cache rspamd stats in redis
$rspamd_stat_redis_cache_ttl = 300;

# Path to use for remote API mail release
$RM_WEB_API_PATH = "/api/release_mail.php";

# Path to use for remote API get mail
$GM_WEB_API_PATH = "/api/get_mail.php";

# Directory to store and serve maps
$MAP_DIR = __DIR__ . '/../web/maps/';
$MAP_FILE_PERM = 0644;

# If user can see personal map entries created by admin
# Set to false to only show entries created by user
$USER_CAN_SEE_ADMIN_MAP_ENTRIES=true;

# If user can delete personal map entries created by admin
# If you set this to true, USER_CAN_SEE_ADMIN_MAP_ENTRIES
# must also be set to true to allow delete
# This also applies to Enable/Disable of entry
$USER_CAN_DEL_ADMIN_MAP_ENTRIES=false;

# Logging file
$LOG_FILE = __DIR__ . '/../logs/rqwatch.log';
# Log levels
# see LoggerFactory.php for all available options
$LOG_FILE_LEVEL="INFO";
$LOG_SYSLOG_LEVEL="DEBUG";
$LOG_SYSLOG_FACILITY="LOG_MAIL";
$LOG_SYSLOG_PREFIX="rqwatch";

# store raw mails based on action
$store_settings = array(
	'no action'       => false,   // change to true to also store clean mails
	'add header'      => true,
	'rewrite subject' => true,
	'greylist'        => false,
	'discard'         => true,
	'reject'          => true,
);

# Default subject in release mail
$release_mail_subject="Message released from quarantine";

# Default subject in notification mail
$notify_mail_subject="New message stored in quarantine";

# Mails with score more than this don't get notifications
$notification_score = 50.1;

# Default mail signature
$mail_signature=$APP_NAME;

# Auto refresh rate for maillogs
$refresh_rate = 60;

# Auto refresh is disabled by default an all pages,
# and explicitly enabled on maillogs pages.
# Set to true to enable globally.
$refresh = false;

# Pager items to show in page
$items_per_page = 50;
# Pager quarantine days to show
$q_items_per_page = 31;

# Certain pages have sql restriction on upper items returned
$max_items = 10000;

# How many items to show in reports
$top_reports = 30;

# Hide subject on web interface
$subject_privacy = false;

# Calculate statistics on mail search page
$show_mail_stats=true;

# Default password hash
# https://www.php.net/manual/en/function.password-hash.php
$password_hash = PASSWORD_BCRYPT;

# Enable GeoIP
# Set $geoip_enable=true; in config.local.php to enable
# https://github.com/maxmind/MaxMind-DB-Reader-php
# https://dev.maxmind.com/geoip/updating-databases/?lang=en
# https://github.com/maxmind/geoipupdate
# https://maxmind.github.io/GeoIP2-php/
$geoip_enable=false;
# GeoIP Country database (requires maxmind account)
$geoip_country_db="/usr/share/GeoIP/GeoLite2-Country.mmdb";

# Set to true to store details into files
# Used for debugging to see what type of information
# comes from rspamd to rqwatch api
$log_to_files=false;
$log_to_files_dir="{$_ENV['QUARANTINE_DIR']}/file_log_debug";
