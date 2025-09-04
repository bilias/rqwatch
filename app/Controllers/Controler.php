<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Controllers;

use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBag;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use App\Core\Config;
use App\Core\RedisFactory;
use App\Utils\Helper;

use App\Services\ApiClient;

use Psr\Log\LoggerInterface;

class Controller
{
	protected RouteCollection $routes;     // $this->route to access it
	protected Request $request;
	protected ?Session $session = null;
	protected FlashBag $flashbag;
	protected UrlGeneratorInterface $urlGenerator;

	protected bool $urlsInitialized = false;
	protected string $loginUrl;
	protected string $homepageUrl;
	protected string $searchUrl;
	protected string $mapsUrl;
	protected string $mapShowAllUrl;

	protected bool $mapUrlsInitialized = false;
	protected string $mapShowUrl;
	protected string $mapAddEntryUrl;

	protected bool $is_admin = false;
	protected ?string $username = null;
	protected ?int $user_id = null;
	protected ?string $email = null;
	protected array $user_aliases = [];

	protected LoggerInterface $fileLogger;
	protected LoggerInterface $syslogLogger;

	public function getRequest(): Request {
		if ($this->request) {
			return $this->request;
		}
	}

	public function setRequest(Request $request): void {
		$this->request = $request;
		if (!$request->hasSession()) {
			throw new \RuntimeException("Session not initialized on request.");
		}

		$this->session = $request->getSession();
		$this->flashbag = $this->session->getFlashBag();

		if (!empty($this->session)) {
			if (!empty($this->session->get('is_admin'))) {
				$this->is_admin = $this->session->get('is_admin');
			}
			if (!empty($this->session->get('username'))) {
				$this->username = $this->session->get('username');
			}
			if (!empty($this->session->get('user_id'))) {
				$this->user_id = $this->session->get('user_id');
			}
			if (!empty($this->session->get('email'))) {
				$this->email = $this->session->get('email');
			}
			if (!empty($this->session->get('user_aliases'))) {
				$this->user_aliases = $this->session->get('user_aliases');
			}
		}
	}

	public function setUrlGenerator(UrlGeneratorInterface $urlGenerator): void {
		$this->urlGenerator = $urlGenerator;
	}

	public function setRoutes(RouteCollection $routes): void {
		$this->routes = $routes;
	}

	public function getIsAdmin(): bool {
		return $this->is_admin;
	}

	public function getEmail(): string {
		return $this->email;
	}

	public function getRole(): string {
		if ($this->getIsAdmin()) {
			return 'admin';
		}
		return 'user';
	}

	public function getUserAliases(): array {
		return $this->user_aliases;
	}

	public function getUserEmailAddresses(): array {
		return array_unique(array_filter(array_merge([$this->email], $this->user_aliases ?? [])));
	}

	protected function getRuntime(): string {
		$startTime = Config::get('startTime');
		$startMemory = Config::get('startMemory');

		$runtime = Helper::get_runtime($startTime, $startMemory);
		Config::set('startTime', microtime(true));
		Config::set('startMemory', memory_get_usage());
		return $runtime;
	}

	public function initUrls(): void {
		if ($this->urlsInitialized) {
			return;
		}

		if ($this->getIsAdmin()) {
			$this->homepageUrl = $this->urlGenerator->generate('admin_homepage');
			$this->searchUrl = $this->urlGenerator->generate('admin_search');
			$this->mapsUrl = $this->urlGenerator->generate('admin_maps');
			$this->mapShowAllUrl = $this->urlGenerator->generate('admin_map_show_all');
		} else {
			$this->homepageUrl = $this->urlGenerator->generate('homepage');
			$this->searchUrl = $this->urlGenerator->generate('search');
			$this->mapsUrl = $this->urlGenerator->generate('maps');
			$this->mapShowAllUrl = $this->urlGenerator->generate('map_show_all');
		}

		$this->loginUrl = $this->urlGenerator->generate('login');
		$this->urlsInitialized = true;
	}

	public function initMapUrls(string $map): void {
		if ($this->mapUrlsInitialized) {
			return;
		}

		if (!$this->urlsInitialized) {
			$this->initUrls();
		}

		if ($this->getIsAdmin()) {
			$this->mapShowUrl = $this->urlGenerator->generate('admin_map_show', [ 'map' => $map ]);
			$this->mapAddEntryUrl = $this->urlGenerator->generate('admin_map_add_entry', [ 'map' => $map ]);
		} else {
			$this->mapShowUrl = $this->urlGenerator->generate('map_show', [ 'map' => $map ]);
			$this->mapAddEntryUrl = $this->urlGenerator->generate('map_add_entry', [ 'map' => $map ]);
		}

		$this->mapUrlsInitialized = true;
   }

	public function getFileLogger(): LoggerInterface {
		return $this->fileLogger;
	}

	public function getSyslogLogger(): LoggerInterface {
		return $this->syslogLogger;
	}

	public function setFileLogger(LoggerInterface $logger): void {
		$this->fileLogger = $logger;
	}

	public function setSyslogLogger(LoggerInterface $logger): void {
		$this->syslogLogger = $logger;
	}

	public function setLoggers(LoggerInterface $fileLogger, LoggerInterface $syslogLogger): void {
		$this->setFileLogger($fileLogger);
		$this->setSyslogLogger($syslogLogger);
	}

	public function getRspamdStat(): array {
		if (Helper::env_bool('REDIS_ENABLE')) {
			$redis = RedisFactory::get();
			$redisKey = Config::get('rspamd_stat_redis_key');
			$ttl = Config::get('rspamd_stat_redis_cache_ttl');

			// Try fetching from redis cache first
			try {
				$cached = $redis->get($redisKey);
				if ($cached !== false) {
					$stats = json_decode($cached, true);
					if (is_array($stats) && !empty($stats)) {
						$this->fileLogger->debug("Rspamd stats loaded from Redis cache");
						return $stats;
					}
					$this->fileLogger->warning("Empty Rspamd stats returned from Redis cache");
				}
			} catch (\Throwable $e) {
				$this->fileLogger->error("Redis error when reading Rspamd stats: " . $e->getMessage());
				// fallback to fetching live if Redis fails
			}
		}

		$api_servers = Config::get('API_SERVERS');

		$apiClient = new ApiClient();
		$password = $_ENV['RSPAMD_CONTROLLER_PASS'];

		$stats = [];
		foreach ($api_servers as $api_server => $config) {
			if (empty($config['stat_url'])) {
				$this->fileLogger->error("API server '{$api_server}' has an empty stat_url. Check config.local.php");
				continue;
			}
			try {
				$response = $apiClient->getWithRspamdPassword($config['stat_url'], $password);
				if ($response->getStatusCode() === Response::HTTP_OK) {
					$stats[$api_server] = json_decode($response->getContent(), true);
				}
			} catch (\Exception $e) {
				$this->fileLogger->error("Stat request to '{$api_server}' failed: " . $e->getMessage());
				continue;
			}
		}

		if (Helper::env_bool('REDIS_ENABLE')) {
			// Store in Redis for future use
			try {
				$redis->set($redisKey, json_encode($stats), ['ex' => $ttl]);
				$this->fileLogger->debug("Rspamd stats cached in Redis for {$ttl} seconds");
			} catch (\Throwable $e) {
				$this->fileLogger->error("Redis error when writing Rspamd stats: " . $e->getMessage());
			}
		}

		return $stats;
	}

}
