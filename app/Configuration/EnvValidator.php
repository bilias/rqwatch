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
 Checks .env once at boot and reports every failing key at once. Values
 stay strings: code converts them where it reads them, and these checks
 make that conversion lossless.
*/
final class EnvValidator
{
	// set and not blank on every node
	private const array REQUIRED = [
		'DB_HOST', 'DB_NAME', 'DB_USER', 'MY_API_SERVER_ALIAS', 'QUARANTINE_DIR',
	];

	// set, may be empty
	private const array REQUIRED_MAY_BE_EMPTY = ['DB_PASS'];

	// set, a non-negative integer
	private const array REQUIRED_INT = ['DB_PORT', 'QUARANTINE_DAYS'];

	// a non-negative integer when set
	private const array OPTIONAL_INT = [
		'DATABASE_DAYS', 'FAILED_LOGIN_TIMEOUT', 'LOGIN_MAX_ATTEMPTS_IP', 'LOGIN_WINDOW', 'LOGIN_BLOCK_DURATION',
	];

	// a non-negative integer or empty when set: empty turns the timeout off
	private const array OPTIONAL_INT_MAY_BE_EMPTY = ['IDLE_TIMEOUT'];

	// every key read by Helper::env_bool(): a boolean when set
	public const array BOOLEANS = [
		'API_ENABLE', 'WEB_ENABLE', 'DEBUG_SEARCH_SQL', 'LOGIN_THROTTLE_ENABLE', 'NOTIFICATION_RELEASE_LINKS',
		'REDIS_ENABLE', 'LDAP_AUTH_ENABLED', 'LDAP_MAIL_ATTR_MULTIVALUE', 'LDAP_UPDATE_NAME_ON_LOGIN',
		'OPENIDC_AUTH_ENABLED', 'OPENIDC_DEBUG_CLAIMS', 'OPENIDC_REQUIRE_PKCE', 'OPENIDC_REQUIRE_VERIFIED_EMAIL',
		'OPENIDC_RP_INITIATED_LOGOUT', 'OPENIDC_UPDATE_NAME_ON_LOGIN',
	];

	// not blank when their switch is on
	private const array REQUIRED_WHEN = [
		'REDIS_ENABLE' => ['REDIS_DSN'],
		'LDAP_AUTH_ENABLED' => ['LDAP_URI', 'LDAP_BASE'],
		'OPENIDC_AUTH_ENABLED' => ['OPENIDC_URL', 'OPENIDC_CLIENT_ID', 'OPENIDC_CLIENT_SECRET'],
	];

	// the web entry point only: set and not blank
	private const array WEB_REQUIRED = ['WEB_HOST', 'WEB_SCHEME'];

	// the web entry point only: set, may be empty
	private const array WEB_REQUIRED_MAY_BE_EMPTY = ['WEB_BASE'];

	/**
	 * @param array<array-key, mixed> $env normally $_ENV
	 * @return list<string> one line per failing key, empty when all pass
	 */
	public static function errors(array $env, bool $web): array {
		$errors = [];

		$required = $web ? [...self::REQUIRED, ...self::WEB_REQUIRED] : self::REQUIRED;
		foreach ($required as $key) {
			$errors[] = self::checkNotBlank($env, $key);
		}

		$mayBeEmpty = $web ? [...self::REQUIRED_MAY_BE_EMPTY, ...self::WEB_REQUIRED_MAY_BE_EMPTY] : self::REQUIRED_MAY_BE_EMPTY;
		foreach ($mayBeEmpty as $key) {
			$errors[] = self::checkSet($env, $key);
		}

		foreach (self::REQUIRED_INT as $key) {
			$errors[] = self::checkSet($env, $key) ?? self::checkInt($env, $key, false);
		}

		foreach (self::OPTIONAL_INT as $key) {
			$errors[] = self::checkInt($env, $key, false);
		}

		foreach (self::OPTIONAL_INT_MAY_BE_EMPTY as $key) {
			$errors[] = self::checkInt($env, $key, true);
		}

		foreach (self::BOOLEANS as $key) {
			$errors[] = self::checkBool($env, $key);
		}

		foreach (self::REQUIRED_WHEN as $switch => $keys) {
			if (self::isTrue($env, $switch)) {
				foreach ($keys as $key) {
					$errors[] = self::checkNotBlank($env, $key, " (needed by {$switch})");
				}
			}
		}

		// on every node: cron:notifications puts it in the links it mails
		$scheme = $env['WEB_SCHEME'] ?? null;
		if ($scheme !== null && $scheme !== 'http' && $scheme !== 'https') {
			$errors[] = 'WEB_SCHEME must be http or https';
		}

		return array_values(array_filter($errors, fn(?string $error): bool => $error !== null));
	}

	/** @param array<array-key, mixed> $env */
	private static function checkSet(array $env, string $key): ?string {
		if (!array_key_exists($key, $env)) {
			return "{$key} is missing";
		}
		return is_string($env[$key]) ? null : "{$key} is not a string";
	}

	/** @param array<array-key, mixed> $env */
	private static function checkNotBlank(array $env, string $key, string $why = ''): ?string {
		if (!array_key_exists($key, $env)) {
			return "{$key} is missing{$why}";
		}
		$value = $env[$key];
		if (!is_string($value)) {
			return "{$key} is not a string{$why}";
		}
		return trim($value) === '' ? "{$key} is empty{$why}" : null;
	}

	// digits only, surrounding blanks allowed; unset passes
	/** @param array<array-key, mixed> $env */
	private static function checkInt(array $env, string $key, bool $mayBeEmpty): ?string {
		if (!array_key_exists($key, $env)) {
			return null;
		}
		$value = $env[$key];
		if (!is_string($value)) {
			return "{$key} is not a string";
		}
		if ($mayBeEmpty && $value === '') {
			return null;
		}
		return ctype_digit(trim($value)) ? null : "{$key} is not a non-negative integer";
	}

	// what Helper::env_bool() reads as a boolean, empty refused; unset passes
	/** @param array<array-key, mixed> $env */
	private static function checkBool(array $env, string $key): ?string {
		if (!array_key_exists($key, $env)) {
			return null;
		}
		$value = $env[$key];
		if (!is_string($value) || trim($value) === ''
			|| filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === null) {
			return "{$key} is not a boolean (true/false, yes/no, on/off, 1/0)";
		}
		return null;
	}

	/** @param array<array-key, mixed> $env */
	private static function isTrue(array $env, string $key): bool {
		$value = $env[$key] ?? null;
		return is_string($value) && filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
	}
}
