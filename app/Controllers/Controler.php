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
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBag;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use App\Core\Config;
use App\Core\SessionManager;
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

		$this->setSessionVars($this->session);
	}

	public function setSessionVars(Session $session): void {
		if (!empty($session)) {
			if ($session->has('is_admin')) {
				$this->is_admin = $session->get('is_admin');
			}
			if ($session->has('username')) {
				$this->username = $session->get('username');
			}
			if ($session->has('user_id')) {
				$this->user_id = $session->get('user_id');
			}
			if ($session->has('email')) {
				$this->email = $session->get('email');
			}
			if ($session->has('user_aliases')) {
				$this->user_aliases = $session->get('user_aliases');
			}
		}
	}

	public function unsetSessionVars(): void {
		unset($this->is_admin);
		unset($this->username);
		unset($this->user_id);
		unset($this->email);
		unset($this->user_aliases);
		unset($this->urlsInitialized);
	}

	public function clearSession(): void {
		SessionManager::destroy();
		$this->session->invalidate();
		session_unset();
		session_destroy();
		$this->session = null;
		$this->unsetSessionVars();
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

	public function unsetUrls(): void {
		$this->urlsInitialized = false;
	}

	public function initUrls(): void {
		if ($this->urlsInitialized) {
			return;
		}

		if ($this->getIsAdmin()) {
			$this->homepageUrl = $this->urlGenerator->generate('admin_day_logs');
			$this->searchUrl = $this->urlGenerator->generate('admin_search');
		} else {
			$this->homepageUrl = $this->urlGenerator->generate('day_logs');
			$this->searchUrl = $this->urlGenerator->generate('search');
		}

		$this->urlsInitialized = true;
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
		if (Config::get('rspamd_stat_disable')) {
			return [];
		}

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
				$responseCode = $response->getStatusCode();
				if ($responseCode === Response::HTTP_OK) {
					$stats[$api_server] = json_decode($response->getContent(), true);
				} else {
					$this->fileLogger->error("Stat request to '{$api_server}' failed with error code " . $responseCode . ": " . $response->getContent());
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

	public function getRedisConfigTTL(): ?int {
		if (Helper::env_bool('REDIS_ENABLE')) {
			return Config::getRedisConfigTTL($_ENV['REDIS_CONFIG_KEY']);
		}
		return null;
	}

	public function getRedisConfigTTLData(): array {
		$ttl = $this->getRedisConfigTTL();
		if ($ttl === null || $ttl < 0) {
			return [
				'ttl' => $ttl,
				'expires_at' => null,
			];
		}

		$expiresAt = (new \DateTimeImmutable())->add(new \DateInterval('PT' . $ttl . 'S'));
		return [
			'ttl' => "$ttl sec",
			'ttl_human' => Helper::formatTtlHuman($ttl),
			'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
		];
	}

	public function redisConfigReload(): Response {
		$this->initUrls();

		if (!Helper::env_bool('REDIS_ENABLE')) {
			$this->flashbag->add('warning', "Redis is not enabled");
			return new RedirectResponse($this->searchUrl);
		}

		try {

			// Force reload the config and cache it again
			Config::loadAndInitWithRedisCache(
				CONFIG_DEFAULT_PATH,
				CONFIG_LOCAL_PATH,
				[],
				$_ENV['REDIS_CONFIG_KEY'],
				(int) $_ENV['REDIS_CONFIG_CACHE_TTL'],
				true
			);
			$this->fileLogger->info("Config reloaded and cached in Redis");
			$this->flashbag->add('info', "Config reloaded and cached in Redis");
			return new RedirectResponse($this->searchUrl);
		} catch (Throwable $e) {
			$this->fileLogger->error("Failed redisConfigReload: " . $e->getMessage());
			$this->flashbag->add('error', "Failed redisConfigReload: " . $e->getMessage());
			return new RedirectResponse($this->searchUrl);
		}

	}

}
