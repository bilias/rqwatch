<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The API scripts under web/api/: each answers only its own credentials from
 an address in its ACL, and only while the API is enabled. Served by the
 harness's second php -S (no router). Settings changed through .env are
 restored after each test.
*/

namespace Tests\Scoping;

use PHPUnit\Framework\Attributes\TestDox;
use RuntimeException;

final class ApiAccessTest extends WebTestCase
{
	// script => the .env prefix of its credentials and ACL
	private const SCRIPTS = [
		'metadata_importer_multipart.php' => 'RSPAMD',
		'get_mail.php' => 'MAIL',
		'release_mail.php' => 'MAIL',
		'fuzzy_mail.php' => 'MAIL',
	];

	#[TestDox('Positive control: its own credentials pass authentication')]
	public function testOwnCredentialsPass(): void {
		foreach (self::SCRIPTS as $script => $kind) {
			$status = self::api($script, self::credentials($kind))[0];
			$this->assertNotContains($status, [401, 403, 500], "{$script}: HTTP {$status}");
		}
	}

	#[TestDox('No credentials, a wrong password, or another API\'s credentials: 401')]
	public function testWrongCredentialsAreRefused(): void {
		foreach (self::SCRIPTS as $script => $kind) {
			[$user, $pass] = self::credentials($kind);
			$other = self::credentials($kind === 'MAIL' ? 'RSPAMD' : 'MAIL');
			$this->assertSame(401, self::api($script, null)[0], "{$script}: no credentials");
			$this->assertSame(401, self::api($script, [$user, $pass . 'x'])[0], "{$script}: wrong password");
			$this->assertSame(401, self::api($script, $other)[0], "{$script}: other API's credentials");
		}
	}

	#[TestDox('A caller outside the ACL: 403 with valid credentials, X-Forwarded-For does not help')]
	public function testCallerOutsideAclIsRefused(): void {
		self::withEnv(['RSPAMD_API_ACL' => '"192.0.2.10"', 'MAIL_API_ACL' => '"192.0.2.10"'], function (): void {
			foreach (self::SCRIPTS as $script => $kind) {
				$this->assertSame([403, 'Permission denied'], self::api($script, self::credentials($kind)), $script);
				$this->assertSame([403, 'Permission denied'],
					self::api($script, self::credentials($kind), ['X-Forwarded-For: 192.0.2.10']), "{$script} with X-Forwarded-For");
			}
		});
	}

	#[TestDox('API disabled: 403 with valid credentials')]
	public function testDisabledApiIsRefused(): void {
		self::withEnv(['API_ENABLE' => 'false'], function (): void {
			foreach (self::SCRIPTS as $script => $kind) {
				$this->assertSame([403, 'API is disabled'], self::api($script, self::credentials($kind)), $script);
			}
		});
	}

	#[TestDox('A CHANGE_ME_ placeholder password is never accepted, even when sent')]
	public function testPlaceholderPasswordIsRefused(): void {
		$placeholder = 'CHANGE_ME_scoping-test';
		self::withEnv(['RSPAMD_API_PASS' => $placeholder, 'MAIL_API_PASS' => $placeholder], function () use ($placeholder): void {
			foreach (self::SCRIPTS as $script => $kind) {
				[$user] = self::credentials($kind);
				$status = self::api($script, [$user, $placeholder])[0];
				$this->assertSame(500, $status, "{$script}: placeholder password");
			}
		});
	}

	/** @return array{0: string, 1: string} user and password from the harness */
	private static function credentials(string $kind): array {
		return [(string) getenv("SCOPING_{$kind}_API_USER"), (string) getenv("SCOPING_{$kind}_API_PASS")];
	}

	/**
	 * POST without fields to a script.
	 * @param array{0: string, 1: string}|null $auth
	 * @param list<string> $headers
	 * @return array{0: int, 1: string} status and body
	 */
	private static function api(string $script, ?array $auth, array $headers = []): array {
		$ch = curl_init(rtrim((string) getenv('SCOPING_API_URL'), '/') . "/api/{$script}");
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => '',
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_TIMEOUT => 30,
		]);
		if ($auth !== null) {
			curl_setopt($ch, CURLOPT_USERPWD, "{$auth[0]}:{$auth[1]}");
		}
		$body = curl_exec($ch);
		if (!is_string($body)) {
			throw new RuntimeException("{$script}: " . curl_error($ch));
		}
		return [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $body];
	}

	/**
	 * Runs $test with .env settings replaced (php -S rereads .env per request),
	 * then puts the file back.
	 * @param array<string, string> $settings
	 */
	private static function withEnv(array $settings, callable $test): void {
		$env = getenv('SCOPING_ROOT') . '/.env';
		$original = (string) file_get_contents($env);
		$changed = $original;
		foreach ($settings as $key => $value) {
			$changed = (string) preg_replace('/^' . preg_quote($key, '/') . '=.*$/m', "{$key}={$value}", $changed, -1, $count);
			if ($count !== 1) {
				throw new RuntimeException(".env has no single {$key} line");
			}
		}
		file_put_contents($env, $changed);
		try {
			$test();
		} finally {
			file_put_contents($env, $original);
		}
	}
}
