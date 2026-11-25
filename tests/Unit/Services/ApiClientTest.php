<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 ApiClient: TLS peer and host verification on by default, with
 SYS_CA_PATH, and an API server's own options over them; each request
 sends what its endpoint needs (rspamd's Password header and stat timeout,
 Basic auth with a form body, raw mail with its headers and timeout). The
 options are read from the Symfony client it creates; the requests go to
 a MockHttpClient put in its place.
*/

namespace Tests\Unit\Services;

use App\Configuration\Config;
use App\Services\ApiClient;
use ReflectionProperty;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\Unit\UnitTestCase;

final class ApiClientTest extends UnitTestCase
{
	/** @var array<string, mixed> the last request: method, url, headers, body, timeout */
	private array $sent = [];

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		Config::set('SYS_CA_PATH', '/etc/pki/tls/certs');
	}

	public function testTlsVerifiedByDefault(): void {
		$options = self::clientOptions(new ApiClient());
		$this->assertSame([true, true, '/etc/pki/tls/certs'], [$options['verify_peer'], $options['verify_host'], $options['capath']]);
	}

	public function testServerOptionsOverTheDefaults(): void {
		$options = self::clientOptions(new ApiClient(['verify_peer' => false]));
		$this->assertSame([false, true, '/etc/pki/tls/certs'], [$options['verify_peer'], $options['verify_host'], $options['capath']]);
	}

	public function testRspamdStat(): void {
		Config::set('rspamd_stat_api_timeout', 2.5);
		$this->mocked()->getWithRspamdPassword('https://mx1.example/stat', 's3cret')->getContent();
		$this->assertSame('GET https://mx1.example/stat', $this->sent['request']);
		$this->assertSame(['Password: s3cret'], $this->header('password'));
		$this->assertSame(2.5, $this->sent['timeout']);
	}

	public function testPostWithAuth(): void {
		$this->mocked()->postWithAuth('https://mx1.example/api', ['a' => 'b'], 'u', 'p')->getContent();
		$this->assertSame('POST https://mx1.example/api', $this->sent['request']);
		$this->assertSame(['Authorization: Basic ' . base64_encode('u:p')], $this->header('authorization'));
		$this->assertSame('a=b', $this->sent['body']);
	}

	public function testPostToRspamd(): void {
		$this->mocked()->postToRspamd('https://mx1.example/fuzzyadd', 'raw mail', ['Flag' => '1', 'Weight' => ['10']], 4.0)->getContent();
		$this->assertSame('POST https://mx1.example/fuzzyadd', $this->sent['request']);
		$this->assertSame(['Flag: 1'], $this->header('flag'));
		$this->assertSame(['Weight: 10'], $this->header('weight'));
		$this->assertSame('raw mail', $this->sent['body']);
		$this->assertSame(4.0, $this->sent['timeout']);
	}

	/** @return array<array-key, mixed> the options the Symfony client was created with */
	private static function clientOptions(ApiClient $api): array {
		$client = (new ReflectionProperty(ApiClient::class, 'client'))->getValue($api);
		self::assertIsObject($client);
		$options = (new ReflectionProperty($client, 'defaultOptions'))->getValue($client);
		self::assertIsArray($options);
		return $options;
	}

	// an ApiClient whose requests are recorded in $sent
	private function mocked(): ApiClient {
		$api = new ApiClient();
		$mock = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
			$this->sent = [
				'request' => "{$method} {$url}",
				'headers' => $options['normalized_headers'] ?? [],
				'body' => $options['body'] ?? null,
				'timeout' => $options['timeout'] ?? null,
			];
			return new MockResponse('ok');
		});
		(new ReflectionProperty(ApiClient::class, 'client'))->setValue($api, $mock);
		return $api;
	}

	/** @return mixed the lines sent for a header, by its lower-case name */
	private function header(string $name): mixed {
		$headers = $this->sent['headers'];
		$this->assertIsArray($headers);
		return $headers[$name] ?? null;
	}
}
