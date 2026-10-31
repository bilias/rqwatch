<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis
 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace Tests\Integration;

use RuntimeException;

// Minimal browser for the integration tests: one cookie jar per user, no
// redirect following, so tests see exactly what each request returns.
final class WebClient
{
	private string $jar;

	public function __construct(private string $baseUrl) {
		$this->jar = (string) tempnam(sys_get_temp_dir(), 'rqwatch-jar-');
	}

	public function __destruct() {
		@unlink($this->jar);
	}

	/** @return array{status: int, location: string, body: string} */
	public function get(string $path): array {
		return $this->request($path, null);
	}

	/** @param array<string, string> $fields
	 *  @return array{status: int, location: string, body: string} */
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

	public static function match(string $pattern, string $subject): string {
		if (!preg_match($pattern, $subject, $m)) {
			throw new RuntimeException("Pattern {$pattern} not found in page");
		}
		return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
	}

	/** @param array<string, string>|null $fields
	 *  @return array{status: int, location: string, body: string} */
	private function request(string $path, ?array $fields): array {
		$ch = curl_init($this->baseUrl . $path);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
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
		];
		curl_close($ch);
		return $result;
	}
}
