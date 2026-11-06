<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis
 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace Tests;

use CurlHandle;
use RuntimeException;

// Minimal browser for the integration and end-to-end tests: one cookie jar
// per user, no redirect following, so tests see exactly what each request
// returns.
final class WebClient
{
	private string $jar;

	public function __construct(private string $baseUrl) {
		$this->jar = (string) tempnam(sys_get_temp_dir(), 'rqwatch-jar-');
	}

	public function __destruct() {
		@unlink($this->jar);
	}

	/** @return array{status: int, location: string, body: string, headers: array<string, string>} */
	public function get(string $path): array {
		return $this->request($path, null);
	}

	/** @param array<string, string> $fields
	 *  @return array{status: int, location: string, body: string, headers: array<string, string>} */
	public function post(string $path, array $fields): array {
		return $this->request($path, $fields);
	}

	public function login(string $username, string $password): void {
		$token = self::match('/name="login_form\[_token\]"[^>]*value="([^"]+)"/', $this->get('/login')['body']);
		$response = $this->post('/login', [
			'login_form[username]' => $username,
			'login_form[password]' => $password,
			'login_form[_token]' => $token,
		]);
		if ($response['status'] !== 302 || str_ends_with($response['location'], '/login')) {
			throw new RuntimeException("Login as '{$username}' failed (HTTP {$response['status']})");
		}
	}

	/** @param non-empty-string $pattern */
	public static function match(string $pattern, string $subject): string {
		if (!preg_match($pattern, $subject, $m)) {
			throw new RuntimeException("Pattern {$pattern} not found in page");
		}
		return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
	}

	/*
	 A path on the app under test, often taken from one of its pages, with
	 its query string. It must start with '/' so baseUrl keeps the scheme
	 and host: '@evil/x' would turn the base into userinfo and send the
	 request elsewhere.
	*/
	/**
	 * @psalm-taint-escape ssrf
	 * @psalm-taint-escape file
	 * @psalm-taint-escape url_component
	 * @psalm-taint-escape url_path
	 */
	private static function appPath(string $path): string {
		if (!str_starts_with($path, '/')) {
			throw new RuntimeException("Not a path on the app: {$path}");
		}
		return $path;
	}

	/** @param array<string, string>|null $fields
	 *  @return array{status: int, location: string, body: string, headers: array<string, string>} */
	private function request(string $path, ?array $fields): array {
		$ch = curl_init($this->baseUrl . self::appPath($path)) ?: throw new RuntimeException('curl_init() failed');
		$headers = [];
		curl_setopt_array($ch, [ // @phpstan-ignore argument.type ($this->jar is the constructor's tempnam() path; empty only if tempnam() failed)
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
			// response headers, names lower-cased
			CURLOPT_HEADERFUNCTION => function (CurlHandle $ch, string $line) use (&$headers): int {
				$parts = explode(':', $line, 2);
				if (count($parts) === 2) {
					$headers[strtolower(trim($parts[0]))] = trim($parts[1]);
				}
				return strlen($line);
			},
			CURLOPT_COOKIEJAR => $this->jar,
			CURLOPT_COOKIEFILE => $this->jar,
			CURLOPT_TIMEOUT => 30,
		]);
		if ($fields !== null) {
			curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
		}
		$body = curl_exec($ch);
		if ($body === false) {
			throw new RuntimeException("Request to {$path} failed: " . curl_error($ch));
		}
		$result = [
			'status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
			'location' => (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL),
			'body' => (string) $body,
			'headers' => $headers,
		];
		curl_close($ch);
		return $result;
	}
}
