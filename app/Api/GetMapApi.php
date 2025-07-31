<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Api;

use App\Core\Config;
use App\Utils\Helper;
use Psr\Log\LoggerInterface;
use App\Core\Auth\BasicAuth;

use App\Models\MapCombined;
use App\Models\MapActivityLog;
use App\Inventory\MapInventory;

use Illuminate\Database\Eloquent\Builder;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class GetMapApi extends RqwatchApi
{
	protected string $logPrefix = 'GetMapApi';

	protected function getAllowedIps(): array {
		return array_map('trim', explode(',', $_ENV['MAP_API_ACL']));;
	}

	protected function getAuthCredentials(): array {
		return [$_ENV['MAP_API_USER'], $_ENV['MAP_API_PASS']];
	}

	public function handle(): void {
		$map = $this->request->query->get('map');
		if (!$map) {
			$this->dropLogResponse(
				Response::HTTP_BAD_REQUEST,
				"Missing map name in query",
				"{$this->clientIp} sent map request without ?map param",
				'warning'
			);
		}

		$relevantHeaders = [
			'If-Modified-Since',
			'If-None-Match',
			'User-Agent',
			'Authorization',
		];

		$headers = [];
		foreach ($relevantHeaders as $header) {
			if ($value = $this->request->headers->get($header)) {
				$headers[$header] = $value;
			}
		}

		$this->fileLogger->debug("[GetMapApi]", [
            'client_ip' => $this->clientIp,
            'map' => $map,
            'method' => $this->request->getMethod(),
            'headers' => $headers,
				'content' => $this->request->getContent(),
        ]);

		$map_config = $this->getMapApiConfig($map);
		if (!$map_config) {
			$this->dropLogResponse(
				Response::HTTP_NOT_FOUND,
				"Unknown map '$map'",
				"[GetMapApi] {$this->clientIp} requested unknown map: '$map'",
				'warning'
			);
		}

		$map_name = $map_config['map_name'];
		$method = $map_config['api_handler'];

		if (empty($method)) {
			$this->dropLogResponse(
				Response::HTTP_NOT_FOUND,
				"Error getting data for map '$map'",
				"[GetMapApi] {$this->clientIp} api_handler is missing config for map: '{$map_name}'",
				'error'
			);
		}

		if (method_exists($this, $method)) {
			$this->$method($this->request);
		} else {
			$this->dropLogResponse(
				Response::HTTP_NOT_FOUND,
				"Error getting data for map '$map'",
				"[GetMapApi] {$this->clientIp} method: '{$method}' missing for map: '{$map_name}'",
				'error'
			);
		}
	}

	protected function handleMailFromWhitelist(): void {
		$map_name = "mail_from_whitelist";

		$fields = MapInventory::getMapConfigs($map_name)['fields'];
		$lastModified = $this->getLastModified($map_name);

		$entries = $this->getBasicMapQuery($map_name, $fields)
							  ->smtpFrom()
							  ->pluck('mail_from')
							  ->all();

		$this->respondMap($entries, $map_name, $lastModified);
	}

	protected function handleMailFromRcptToWhitelist(): void {
		$map_name = "mail_from_rcpt_to_whitelist";

		$fields = MapInventory::getMapConfigs($map_name)['fields'];
		$lastModified = $this->getLastModified($map_name);

		$entries = $this->getBasicMapQuery($map_name, $fields)
					  ->smtpFromRcptTo()
					  ->get()
					  ->map(function ($entry) {
							return "{$entry->mail_from}|{$entry->rcpt_to}";
					  })
					  ->all();

		$this->respondMap($entries, $map_name, $lastModified);
	}

	protected function respondMap(array $entries, string $map_name, $lastModified): void {
	
		$lastModified = Helper::toUtcTimestamp($lastModified);

		$etag = sha1(implode("\n", $entries));

		if ($this->isNotModified($etag, $lastModified, $map_name)) {
			$this->fileLogger->debug("[GetMapApi] '{$map_name}' not modified");
			$this->respondNotModified($etag, $lastModified);
			return;
		}

		if ($this->request->getMethod() === 'HEAD') {
			$this->fileLogger->debug("[GetMapApi] '{$map_name}' headers only response");
			$this->respondHeadersOnly($etag, $lastModified);
			return;
		}

		$this->fileLogger->info("[GetMapApi] give {$this->clientIp} new map for '{$map_name}'");
		$this->respondPlainMap($entries, $etag, $lastModified);
	}

	protected function isNotModified(
		string $etag,
		int $lastModified,
		string $map_name
	): bool {

		$ifNoneMatch = $this->request->headers->get('If-None-Match');
		$ifModifiedSince = $this->request->headers->get('If-Modified-Since');

		if ($ifNoneMatch === null && $ifModifiedSince === null) {
			$this->fileLogger->debug("[GetMapApi] '{$map_name}' No cache headers; treating as modified");
			return false;
		}

		// Remove quotes from client ETag header if present
		if ($ifNoneMatch !== null) {
			$ifNoneMatch = trim($ifNoneMatch, '"');
			$this->fileLogger->debug("[GetMapApi] '{$map_name}' Checking ETag: client=$ifNoneMatch, current=$etag");
			if ($ifNoneMatch !== $etag) {
				$this->fileLogger->info("[GetMapApi] {$this->clientIp} ETag changed for '{$map_name}'");
				return false;
			}
		}

		if ($ifModifiedSince !== null) {
			$clientTime = $ifModifiedSince ? strtotime($ifModifiedSince) : 0;
			$this->fileLogger->debug("[GetMapApi] '{$map_name}' Checking Last-Modified: clientTime=$clientTime, serverTime=$lastModified");

			if ($lastModified > $clientTime) {
				$this->fileLogger->info("[GetMapApi] {$this->clientIp} Last-Modified changed for '{$map_name}'");
				return false;
			}
		}
		return true;
	}

	protected function respondNotModified(string $etag, int $lastModified): void {
		$response = new Response('', Response::HTTP_NOT_MODIFIED, [
			'ETag' => $etag,
			'Last-Modified' => gmdate('D, d M Y H:i:s', $lastModified) . ' GMT',
		]);

		$response->send();
	}

	protected function respondHeadersOnly(string $etag, int $lastModified): void {
		$response = new Response('', Response::HTTP_OK, [
			'ETag' => '"' . $etag . '"',
			'Last-Modified' => gmdate('D, d M Y H:i:s', $lastModified) . ' GMT',
			'Content-Type' => 'text/plain',
		]);

		$response->send();
	}

	protected function respondPlainMap(array $entries, string $etag, int $lastModified): void {

		$body = "# Last-Modified: " .
			gmdate('D, d M Y H:i:s', $lastModified) . " GMT ({$etag})\n" .
			implode("\n", $entries) . "\n";

		$response = new Response();
		$response->setContent($body);
		$response->setCharset('UTF-8');
		$response->headers->set('Content-Type', 'text/plain');
		$response->headers->set('ETag', '"' . $etag . '"');
		// Although new ETag triggers download,
		// rspamd requires a new timestamp to reload its cache
		$response->headers->set('Last-Modified',
			gmdate('D, d M Y H:i:s', $lastModified) . ' GMT');
		$response->setStatusCode(Response::HTTP_OK);
		$response->send();

		exit;
	}

	protected function getMapApiConfig(string $map): ?array {
		$configs = MapInventory::getMapConfigs();
		$map = strtolower($map);

		$api_config = [];
		foreach ($configs as $key => $config) {
			$api_key = strtolower(str_replace("_", "", $key));

			if ($api_key === $map) {
				$api_config = $config;
				$api_config['map_name'] = $key;
				return $api_config;
			}
		}
		return null;
	}

	protected function getLastModified(string $map_name): string {
		$map_activity_log = MapActivityLog::find($map_name);
		if ($map_activity_log) {
			return $map_activity_log->getRawLastChangedAt();
		} else {
			return date("Y-m-d H:i:s");
		}
	}

	protected function getBasicMapQuery(string $map_name, array $fields): Builder {
		return MapCombined::select($fields)
								->forMap($map_name);
	}

}
