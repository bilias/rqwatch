<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU Affero General Public License version 3
as published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU Affero General Public License for more details.

You should have received a copy of the GNU Affero General Public License
along with this program.  If not, see <https://www.gnu.org/licenses/>.
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

}
