<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Services;

use App\Core\Config;

use Symfony\Component\HttpClient\HttpClient;
//use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class ApiClient
{
	private HttpClientInterface $client;

	public function __construct(array $options = []) {
		// Set default options for secure communication
		$defaultOptions = [
			'verify_peer' => true,
			'verify_host' => true,
			'capath' => Config::get('SYS_CA_PATH'),
		];
		$mergedOptions = array_merge($defaultOptions, $options);

		$this->client = HttpClient::create($mergedOptions);
		//$this->client = new CurlHttpClient($mergedOptions);
	}

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
			'timeout' => Config::get('rspamd_stat_api_timeout'),
		]);
	}

}
