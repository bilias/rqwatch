<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Configuration;

/*
 Checks the loaded config once at boot and reports every failing setting at
 once. Values are PHP values, not strings: nothing is converted, so "0644"
 for an int is refused rather than read as decimal 644.
*/
final class ConfigValidator
{
	// every setting config.php ships, by type; null counts as unset
	public const array TYPES = [
		'APP_NAME' => 'string', 'APP_INFO' => 'string', 'APP_LOGO_PATH' => 'string', 'APP_LOGO_ALT' => 'string',
		'APP_VERSION' => 'string', 'FOOTER_NAME' => 'string', 'FOOTER_URL' => 'string', 'FOOTER_VERSION' => 'string',
		'login_username_label' => 'string', 'SYS_CA_PATH' => 'string',
		'API_SERVERS' => 'array',
		'RELEASE_MAIL_API_PATH' => 'string', 'GET_MAIL_API_PATH' => 'string', 'FUZZY_MAIL_API_PATH' => 'string',
		'rspamd_stat_disable' => 'bool', 'rspamd_stat_api_timeout' => 'float', 'rspamd_stat_redis_key' => 'string',
		'rspamd_stat_redis_cache_ttl' => 'int', 'rspamd_stat_redis_failure_ttl' => 'int',
		'fuzzy_learn_enable' => 'bool', 'fuzzy_learn_flags' => 'array', 'fuzzy_learn_expire_days' => 'int',
		'MAP_DIR' => 'string', 'MAP_FILE_PERM' => 'int', 'USER_CAN_SEE_ADMIN_MAP_ENTRIES' => 'bool',
		'USER_CAN_DEL_ADMIN_MAP_ENTRIES' => 'bool', 'USER_MAP_MAX_ENTRIES' => 'int', 'map_symbols' => 'array',
		'local_symbol_patterns' => 'array', 'whitelist_symbol_patterns' => 'array', 'blacklist_symbol_patterns' => 'array',
		'LOG_FILE' => 'string', 'LOG_FILE_LEVEL' => 'string', 'LOG_SYSLOG_LEVEL' => 'string',
		'LOG_SYSLOG_FACILITY' => 'string', 'LOG_SYSLOG_PREFIX' => 'string',
		'store_settings' => 'array', 'mail_fallback_charset' => 'string',
		'release_mail_subject' => 'string', 'notify_mail_subject' => 'string', 'admin_notify_mail_subject' => 'string',
		'mail_signature' => 'string', 'user_notifications_default' => 'bool', 'admin_notification_rcpt' => 'string',
		'notification_score' => 'float', 'notification_skip_virus_map' => 'string', 'notification_days' => 'int',
		'admin_notification_days' => 'int', 'notification_links_one_time' => 'bool',
		'notification_links_idle_minutes' => 'int',
		'refresh_rate' => 'int', 'refresh' => 'bool', 'items_per_page' => 'int', 'q_items_per_page' => 'int',
		'max_items' => 'int', 'top_reports' => 'int', 'subject_privacy' => 'bool',
		'show_mail_stats' => 'bool', 'show_user_mail_stats' => 'bool', 'show_unfiltered_mail_stats' => 'bool',
		'show_unfiltered_user_mail_stats' => 'bool', 'show_mail_reports' => 'bool', 'show_user_mail_reports' => 'bool',
		'show_unfiltered_mail_reports' => 'bool', 'show_unfiltered_user_mail_reports' => 'bool',
		'show_charts' => 'bool', 'show_user_charts' => 'bool',
		'password_hash' => 'string', 'geoip_enable' => 'bool', 'geoip_country_db' => 'string',
		'dns_resolv' => 'bool', 'dns_resolv_timeout' => 'float', 'dns_resolv_redis_key' => 'string',
		'dns_resolv_redis_cache_ttl' => 'int', 'dns_resolv_redis_neg_cache_ttl' => 'int',
		'dns_resolv_redis_fail_cache_ttl' => 'int',
		'import_spool' => 'bool', 'import_spool_redis_key' => 'string', 'import_spool_ttl' => 'int',
		'import_spool_max' => 'int', 'migration_status_redis_key' => 'string',
		'log_to_files' => 'bool', 'log_to_files_dir' => 'string',
	];

	// set and not blank: an empty Redis prefix or path would match or reach the wrong thing
	private const array REQUIRED = [
		'dns_resolv_redis_key', 'import_spool_redis_key', 'migration_status_redis_key', 'rspamd_stat_redis_key',
		'MAP_DIR', 'MAP_FILE_PERM', 'RELEASE_MAIL_API_PATH', 'GET_MAIL_API_PATH', 'FUZZY_MAIL_API_PATH',
	];

	private const array EXPECTED = [
		'int' => 'an integer',
		'float' => 'a number',
		'bool' => 'true or false',
		'string' => 'a string',
		'array' => 'an array',
	];

	/**
	 * @param array<array-key, mixed> $vars the loaded config
	 * @return list<string> one line per failing setting, empty when all pass
	 */
	public static function errors(array $vars): array {
		$errors = [];
		foreach (self::TYPES as $key => $type) {
			$value = $vars[$key] ?? null;
			$required = in_array($key, self::REQUIRED, true);
			if ($value === null) {
				if ($required) {
					$errors[] = "{$key} is not set";
				}
				continue;
			}
			if (!self::matches($value, $type)) {
				$errors[] = self::wrongType($key, $type, $value);
			} elseif ($required && is_string($value) && trim($value) === '') {
				$errors[] = "{$key} is empty";
			}
		}
		return $errors;
	}

	// a flag may be written 0 or 1: its truthiness is the same
	public static function matches(mixed $value, string $type): bool {
		return match ($type) {
			'int' => is_int($value),
			'float' => is_int($value) || is_float($value),
			'bool' => is_bool($value) || $value === 0 || $value === 1,
			'string' => is_string($value),
			'array' => is_array($value),
			default => false,
		};
	}

	public static function wrongType(string $key, string $type, mixed $value): string {
		return "{$key} must be " . (self::EXPECTED[$type] ?? $type) . ', not ' . get_debug_type($value);
	}
}
