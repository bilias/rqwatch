<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Services;

use App\Configuration\Config;

use Symfony\Component\HttpClient\HttpClient;
//use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class ApiClient
{
	private HttpClientInterface $client;

	/*
	 The HTTP client: Symfony's; through $httpClient instead when a unit test
	 has set one (a MockHttpClient), so the calls run without a network. It
	 gets the same options.
	*/
	/** @var (\Closure(array<string, mixed>): HttpClientInterface)|null options => the client */
	private static ?\Closure $httpClient = null; // @phpstan-ignore property.unusedType (the unit tests set it, through reflection)

	/** @param array<string, mixed> $options Symfony HttpClient options */
	public function __construct(array $options = []) {
		// Set default options for secure communication
		$defaultOptions = [
			'verify_peer' => true,
			'verify_host' => true,
			'capath' => Config::getString('SYS_CA_PATH'),
		];
		$mergedOptions = array_merge($defaultOptions, $options);

		$this->client = self::$httpClient !== null
			? (self::$httpClient)($mergedOptions)
			: HttpClient::create($mergedOptions);
		//$this->client = new CurlHttpClient($mergedOptions);
	}

	/** @param array<string, mixed> $data form fields */
	public function postWithAuth(string $url, array $data, string $username, string $password): ResponseInterface {
		return $this->client->request('POST', $url, [
			'headers' => [
				'Authorization' => 'Basic ' . base64_encode("$username:$password"),
			],
			'body' => $data,
		]);
	}

	public function getWithRspamdPassword(string $url, string $password): ResponseInterface
	{
		return $this->client->request('GET', $url, [
			'headers' => [ 'Password' => $password, ],
			'timeout' => Config::getFloat('rspamd_stat_api_timeout'),
		]);
	}


	/** @param array<string, string|list<string>> $headers a list repeats the header */
	public function postToRspamd(string $url, string $body, array $headers, float $timeout): ResponseInterface
	{
		return $this->client->request('POST', $url, [
			'headers' => $headers,
			'body' => $body,
			'timeout' => $timeout,
		]);
	}

}
