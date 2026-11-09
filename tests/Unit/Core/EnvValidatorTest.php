<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The boot-time .env check: .env-example passes as shipped, every failing
 key is reported, values that (int) or env_bool() would silently misread are
 refused, and the WEB_* keys are required by the web entry point only.
*/

namespace Tests\Unit\Core;

use App\Configuration\EnvValidator;
use Dotenv\Dotenv;
use PHPUnit\Framework\Attributes\DataProvider;
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

	public function testEnvExamplePasses(): void {
		$env = Dotenv::parse((string) file_get_contents(dirname(__DIR__, 3) . '/.env-example'));
		$this->assertSame([], EnvValidator::errors($env, true));
		$this->assertSame([], EnvValidator::errors($env, false));
	}

	public function testEveryMissingKeyReported(): void {
		$this->assertSame([
			'DB_HOST is missing', 'DB_NAME is missing', 'DB_USER is missing', 'MY_API_SERVER_ALIAS is missing',
			'QUARANTINE_DIR is missing', 'DB_PASS is missing', 'DB_PORT is missing', 'QUARANTINE_DAYS is missing',
		], EnvValidator::errors([], false));
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
		foreach (['QUARANTINE_DAYS', 'LOGIN_WINDOW'] as $key) {
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

	public function testNonStringRefused(): void {
		$this->assertSame(['DB_HOST is not a string'], EnvValidator::errors(['DB_HOST' => ['x']] + self::valid(), false));
	}

	// every key the code reads with env_bool() is checked as a boolean
	public function testEveryEnvBoolKeyChecked(): void {
		$keys = [];
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 3) . '/app'));
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
