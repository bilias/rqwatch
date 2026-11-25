<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The boot-time .env check: .env-example is refused as shipped for its
 CHANGE_ME_ placeholders only, every failing key is reported, values that
 (int) or env_bool() would silently misread are refused, the WEB_* keys and
 OPENIDC_TRUSTED_HOSTS are checked by the web entry point only, and a
 published secret is refused in each key where that key is read: a plain
 secret whole, a DSN part by part.
*/

namespace Tests\Unit\Core;

use App\Configuration\EnvValidator;
use Dotenv\Dotenv;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\PublishedSecrets;
use Tests\Unit\UnitTestCase;

final class EnvValidatorTest extends UnitTestCase
{
	/** @return array<string, string> a complete, valid .env */
	private static function valid(): array {
		return [
			'DB_HOST' => '127.0.0.1', 'DB_NAME' => 'rqwatch', 'DB_USER' => 'rqwatch', 'DB_PASS' => '',
			'DB_PORT' => '3306', 'MY_API_SERVER_ALIAS' => 'mx1', 'QUARANTINE_DIR' => '/quarantine',
			'QUARANTINE_DAYS' => '180', 'WEB_HOST' => 'rqwatch.example.com', 'WEB_SCHEME' => 'https', 'WEB_BASE' => '',
		];
	}

	// an unedited copy does not boot; with its placeholders replaced it does
	public function testEnvExampleRefusedForItsPlaceholdersOnly(): void {
		$env = Dotenv::parse((string) file_get_contents(dirname(__DIR__, 3) . '/.env-example'));
		$api = ' (read while API_ENABLE is on)';
		$this->assertSame([
			'DB_PASS is a published example value; set your own',
			'RSPAMD_CONTROLLER_PASS is a published example value; set your own',
			"RSPAMD_API_PASS is a published example value{$api}; set your own",
			"MAIL_API_PASS is a published example value{$api}; set your own",
			'MAILER_DSN is a published example value (read while MAILER_FROM is set); set your own',
		], EnvValidator::errors($env, true));
		$this->assertSame([
			'DB_PASS is a published example value; set your own',
			"RSPAMD_API_PASS is a published example value{$api}; set your own",
			"MAIL_API_PASS is a published example value{$api}; set your own",
			'MAILER_DSN is a published example value (read while MAILER_FROM is set); set your own',
		], EnvValidator::errors($env, false));

		$own = array_map(fn (?string $value): ?string => $value === null ? null : str_replace('CHANGE_ME_', 'own-', $value), $env);
		$this->assertSame([], EnvValidator::errors($own, true));
		$this->assertSame([], EnvValidator::errors($own, false));
	}

	public function testEveryMissingKeyReported(): void {
		$this->assertSame([
			'DB_HOST is missing', 'DB_NAME is missing', 'DB_USER is missing', 'MY_API_SERVER_ALIAS is missing',
			'QUARANTINE_DIR is missing', 'DB_PASS is missing', 'DB_PORT is missing', 'QUARANTINE_DAYS is missing',
		], EnvValidator::errors([], false));
		$this->assertSame([
			'DB_HOST is missing', 'DB_NAME is missing', 'DB_USER is missing', 'MY_API_SERVER_ALIAS is missing',
			'QUARANTINE_DIR is missing', 'WEB_HOST is missing', 'WEB_SCHEME is missing', 'DB_PASS is missing',
			'WEB_BASE is missing', 'DB_PORT is missing', 'QUARANTINE_DAYS is missing',
		], EnvValidator::errors([], true));
	}

	public function testWebKeysOnlyForTheWeb(): void {
		$env = self::valid();
		unset($env['WEB_HOST'], $env['WEB_SCHEME'], $env['WEB_BASE']);
		$this->assertSame([], EnvValidator::errors($env, false));
		$this->assertSame(['WEB_HOST is missing', 'WEB_SCHEME is missing', 'WEB_BASE is missing'], EnvValidator::errors($env, true));
	}

	public function testBlankRequiredRefused(): void {
		$env = ['MY_API_SERVER_ALIAS' => '  ', 'WEB_HOST' => ''] + self::valid();
		$this->assertSame(['MY_API_SERVER_ALIAS is empty', 'WEB_HOST is empty'], EnvValidator::errors($env, true));
	}

	/** @return array<string, array{string, bool}> value, accepted */
	public static function integers(): array {
		return [
			'digits' => ['180', true],
			'zero' => ['0', true],
			'quoted blanks' => [' 180 ', true],
			'unit' => ['180days', false],
			'decimal' => ['1.0', false],
			'negative' => ['-5', false],
			'empty' => ['', false],
		];
	}

	#[DataProvider('integers')]
	public function testIntegers(string $value, bool $accepted): void {
		foreach (['QUARANTINE_DAYS', 'LOGIN_WINDOW', 'DB_WEB_MAX_STATEMENT_TIME'] as $key) {
			$errors = EnvValidator::errors([$key => $value] + self::valid(), false);
			$this->assertSame($accepted ? [] : ["{$key} is not a non-negative integer"], $errors, $key);
		}
	}

	public function testIdleTimeoutMayBeEmpty(): void {
		$this->assertSame([], EnvValidator::errors(['IDLE_TIMEOUT' => ''] + self::valid(), false));
		$this->assertSame(['IDLE_TIMEOUT is not a non-negative integer'],
			EnvValidator::errors(['IDLE_TIMEOUT' => ' '] + self::valid(), false));
	}

	/** @return array<string, array{string, bool}> value, accepted */
	public static function booleans(): array {
		return [
			'true' => ['true', true], 'FALSE' => ['FALSE', true], 'yes' => ['yes', true], 'off' => ['off', true],
			'one' => ['1', true], 'zero' => ['0', true],
			'typo' => ['ture', false], 'empty' => ['', false], 'blank' => [' ', false], 'two' => ['2', false],
		];
	}

	#[DataProvider('booleans')]
	public function testBooleans(string $value, bool $accepted): void {
		$errors = EnvValidator::errors(['OPENIDC_REQUIRE_PKCE' => $value] + self::valid(), false);
		$this->assertSame($accepted ? [] : ['OPENIDC_REQUIRE_PKCE is not a boolean (true/false, yes/no, on/off, 1/0)'], $errors);
	}

	public function testSwitchRequiresItsKeys(): void {
		$this->assertSame(['REDIS_DSN is missing (needed by REDIS_ENABLE)'],
			EnvValidator::errors(['REDIS_ENABLE' => 'true'] + self::valid(), false));
		$this->assertSame(['LDAP_URI is missing (needed by LDAP_AUTH_ENABLED)', 'LDAP_BASE is empty (needed by LDAP_AUTH_ENABLED)'],
			EnvValidator::errors(['LDAP_AUTH_ENABLED' => 'yes', 'LDAP_BASE' => ''] + self::valid(), false));
		$this->assertSame([], EnvValidator::errors(['OPENIDC_AUTH_ENABLED' => 'false'] + self::valid(), false));
	}

	public function testSchemeCheckedOnEveryNode(): void {
		foreach (['htps', 'HTTPS', ''] as $scheme) {
			$this->assertSame(['WEB_SCHEME must be http or https'],
				EnvValidator::errors(['WEB_SCHEME' => $scheme] + self::valid(), false), $scheme);
		}
	}

	public function testTrustedOriginsCheckedForTheWeb(): void {
		foreach (['', 'https://rq', ' https://rq:443, http://rq.internal:8080 ,'] as $value) {
			$this->assertSame([], EnvValidator::errors(['OPENIDC_TRUSTED_HOSTS' => $value] + self::valid(), true), $value);
		}
		$env = ['OPENIDC_TRUSTED_HOSTS' => 'rq, https://ok, rq.internal:8443, https://rq/'] + self::valid();
		$this->assertSame([
			"OPENIDC_TRUSTED_HOSTS takes origins such as https://host or https://host:8443, not 'rq', 'rq.internal:8443', 'https://rq/'",
		], EnvValidator::errors($env, true));
		// other nodes do not read it
		$this->assertSame([], EnvValidator::errors($env, false));
		$this->assertSame(['OPENIDC_TRUSTED_HOSTS is not a string'],
			EnvValidator::errors(['OPENIDC_TRUSTED_HOSTS' => ['x']] + self::valid(), true));
	}

	// each key where it is read: DB_PASS always, the rest while their switch is on or on the web
	public function testPublishedSecretsRefusedWhereRead(): void {
		$old = PublishedSecrets::VALUES;
		$refused = fn (string $key, string $why = ''): string => "{$key} is a published example value{$why}; set your own";

		$this->assertSame([$refused('DB_PASS')], EnvValidator::errors(['DB_PASS' => $old['DB_PASS']] + self::valid(), false));
		// a plain secret is checked whole, a DSN part by part
		$this->assertSame([], EnvValidator::errors(['DB_PASS' => "own-{$old['DB_PASS']}"] + self::valid(), false));

		$env = ['RSPAMD_CONTROLLER_PASS' => $old['RSPAMD_CONTROLLER_PASS'], 'RSPAMD_CONTROLLER_ENABLE_PASS' => 'CHANGE_ME_x'] + self::valid();
		$this->assertSame([], EnvValidator::errors($env, false));
		$this->assertSame([$refused('RSPAMD_CONTROLLER_PASS'), $refused('RSPAMD_CONTROLLER_ENABLE_PASS')], EnvValidator::errors($env, true));

		$switched = [
			'API_ENABLE' => ['RSPAMD_API_PASS' => $old['RSPAMD_API_PASS'], 'MAIL_API_PASS' => $old['MAIL_API_PASS']],
			'LDAP_AUTH_ENABLED' => ['LDAP_BIND_PASS' => 'CHANGE_ME_x'],
			'OPENIDC_AUTH_ENABLED' => ['OPENIDC_CLIENT_SECRET' => $old['OPENIDC_CLIENT_SECRET']],
			'REDIS_ENABLE' => ['REDIS_DSN' => "redis:rqwatch:{$old['REDIS_DSN']}@?host[10.10.10.2:5000]"],
		];
		foreach ($switched as $switch => $secrets) {
			$this->assertSame([], EnvValidator::errors([$switch => 'false'] + $secrets + self::valid(), true), $switch);
			$expected = array_map(fn (string $key): string => $refused($key, " (read while {$switch} is on)"), array_keys($secrets));
			$errors = EnvValidator::errors([$switch => 'true', 'LDAP_URI' => 'ldap://x', 'LDAP_BASE' => 'dc=x',
				'OPENIDC_URL' => 'https://idp', 'OPENIDC_CLIENT_ID' => 'rq'] + $secrets + self::valid(), false);
			$this->assertSame($expected, $errors, $switch);
		}

		$mailer = ['MAILER_DSN' => "smtp://rq@example.com:{$old['MAILER_DSN']}@smtp.example.com:587"] + self::valid();
		$this->assertSame([], EnvValidator::errors(['MAILER_FROM' => ' '] + $mailer, false));
		$this->assertSame([$refused('MAILER_DSN', ' (read while MAILER_FROM is set)')],
			EnvValidator::errors(['MAILER_FROM' => 'rq@example.com'] + $mailer, false));

		// an empty secret is no published one: LDAP binds anonymously, DB_PASS may be empty
		$this->assertSame([], EnvValidator::errors(['LDAP_AUTH_ENABLED' => 'true', 'LDAP_URI' => 'ldap://x',
			'LDAP_BASE' => 'dc=x', 'LDAP_BIND_PASS' => ''] + self::valid(), false));
	}

	public function testNonStringRefused(): void {
		$this->assertSame(['DB_HOST is not a string'], EnvValidator::errors(['DB_HOST' => ['x']] + self::valid(), false));
	}

	// every key the code reads with env_bool() is checked as a boolean
	public function testEveryEnvBoolKeyChecked(): void {
		$keys = [];
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 3) . '/app'));
		/** @var mixed $file */
		foreach ($files as $file) {
			if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
				preg_match_all("/env_bool\('([A-Z0-9_]+)'/", (string) file_get_contents($file->getPathname()), $m);
				$keys = [...$keys, ...$m[1]];
			}
		}
		$this->assertNotSame([], $keys);
		$this->assertSame([], array_values(array_diff(array_unique($keys), EnvValidator::BOOLEANS)));
	}
}
