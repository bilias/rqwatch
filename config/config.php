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

# Default configuration variables for Rqwatch.

# If you want to alter default configuration,
# put the variable in config.local.php
# This file is being tracked and updated by git,
# so local changes might be lost on update.

$APP_NAME="Rqwatch";
$APP_INFO="Rspamd Quarantine Watch";
$APP_VERSION="1.6.0";
$FOOTER="{$APP_NAME} v{$APP_VERSION}";

$SYS_CA_PATH = "/etc/pki/tls/certs";

# define all API server aliases and their API urls
$API_SERVERS = array(
	'mx1' => [
		'url' => 'https://mx1.example.com',
		'options' => [
			'verify_peer' => true,
			'verify_host' => true,
			'capath' => '/etc/pki/tls/certs',
			'cafile' => '/etc/pki/tls/certs/mx1.crt',
		],
	],
	'mx2' => [
		'url' => 'https://mx2.example.com',
		'options' => [
			'verify_peer' => true,
			'verify_host' => true,
			'capath' => '/etc/pki/tls/certs',
			'cafile' => '/etc/pki/tls/certs/mx2.crt',
		],
	],
);

# Path to use for remote API mail release
$RM_WEB_API_PATH = "/api/release_mail.php";

# Path to use for remote API get mail
$GM_WEB_API_PATH = "/api/get_mail.php";

# Directory to store and serve maps
$MAP_DIR = __DIR__ . '/../web/maps/';
$MAP_FILE_PERM = 0644;

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

# default subject in release mail
$release_mail_subject="Message released from quarantine";

# default subject in notification mail
$notify_mail_subject="New message stored in quarantine";

# default mail signature
$mail_signature=$APP_NAME;

# auto refresh rate for maillogs
$refresh_rate = 300;

# auto refresh is disabled by default an all pages,
# and explicitly enabled on maillogs pages
$refresh = false;

# items to show in page
$items_per_page = 50;

# certain pages have sql restriction on upper items returned
$max_items = 1000;

# hide subject on web interface
$subject_privacy = false;

# calculate statistics on search page
$show_stats=true;

# default password hash
# https://www.php.net/manual/en/function.password-hash.php
$password_hash = PASSWORD_BCRYPT;

# User can see personal map entries created by admin
# Set to false to only show entries created by user
$USER_CAN_SEE_ADMIN_MAP_ENTRIES=true;

# User can delete personal map entries created by admin
# If you set this to true, USER_CAN_SEE_ADMIN_MAP_ENTRIES
# must also be set to true to allow delete
$USER_CAN_DEL_ADMIN_MAP_ENTRIES=false;

# enable GeoIP
# set $geoip_enable=true; in config.local.php to enable
# https://github.com/maxmind/MaxMind-DB-Reader-php
# https://dev.maxmind.com/geoip/updating-databases/?lang=en
# https://github.com/maxmind/geoipupdate
# https://maxmind.github.io/GeoIP2-php/
$geoip_enable=false;
# GeoIP Country database (requires maxmind account)
$geoip_country_db="/usr/share/GeoIP/GeoLite2-Country.mmdb";

# set to true to store details into files in QUARANTINE_DIR/file_log_debug
# used for debugging to see what type of information
# comes from rspamd to rqwatch api
$log_to_files=false;
$log_to_files_dir="{$_ENV['QUARANTINE_DIR']}/file_log_debug";
