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

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Csrf\CsrfTokenManager;
use Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator;
use Symfony\Component\Security\Csrf\TokenStorage\SessionTokenStorage;
use Symfony\Component\Security\Csrf\CsrfToken;

use App\Configuration\AppConfig;
use App\Configuration\Config;

use App\Core\App;

use App\Inventory\Migrations;
use App\Core\Database\MigrationStatus;

use App\Core\Routing\RouteName;
use App\Core\Routing\UrlBuilder;

use App\Core\SessionManager;

use App\Utils\Helper;

use App\Services\ApiClient;

use Psr\Log\LoggerInterface;

use DateTimeImmutable;
use DateInterval;

use Exception;
use Throwable;
use RuntimeException;

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

	protected ?CsrfTokenManager $csrfManager = null;

	public function __construct() {
		$this->fileLogger = App::fileLogger();
		$this->syslogLogger = App::syslogLogger();
	}

	protected function csrfManager(): CsrfTokenManager {
		if ($this->csrfManager === null) {
			// creates a RequestStack object using the current request
			$requestStack = new RequestStack([$this->request]);
			$this->csrfManager = new CsrfTokenManager(
				new UriSafeTokenGenerator(),
				new SessionTokenStorage($requestStack)
			);
		}
		return $this->csrfManager;
	}

	protected function csrfValid(string $id): bool {
		$token = $this->request->query->get('_token');

		if (!is_string($token) || $token === '') {
			return false;
		}

		return $this->csrfManager()->isTokenValid(new CsrfToken($id, $token));
	}

	public function setRequest(Request $request): void {
		$this->request = $request;
		if (!$request->hasSession()) {
			throw new RuntimeException("Session not initialized on request.");
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
		$this->is_admin = false;
		$this->username = null;
		$this->user_id = null;
		$this->email = null;
		$this->user_aliases = [];
		$this->urlsInitialized = false;
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

	protected function url(RouteName $route, array $parameters = []): string {
		return UrlBuilder::generate($this->urlGenerator, $route, $parameters);
	}

	public function getRole(): string {
		if ($this->is_admin) {
			return 'admin';
		}
		return 'user';
	}

	public function getUserEmailAddresses(): array {
		return array_unique(array_filter(array_merge([$this->email], $this->user_aliases ?? [])));
	}

	protected function getRuntime(): string {
		return App::getRuntime();
	}

	public function unsetUrls(): void {
		$this->urlsInitialized = false;
	}

	public function refreshUrls(): void {
		$this->urlsInitialized = false;
		$this->initUrls();
	}

	public function initUrls(): void {
		if ($this->urlsInitialized) {
			return;
		}

		$this->loginUrl = $this->url(RouteName::LOGIN);

		if ($this->is_admin) {
			$this->homepageUrl = $this->url(RouteName::ADMIN_DAY_LOGS);
			$this->searchUrl = $this->url(RouteName::ADMIN_SEARCH);
		} else {
			$this->homepageUrl = $this->url(RouteName::DAY_LOGS);
			$this->searchUrl = $this->url(RouteName::SEARCH);
		}

		$this->urlsInitialized = true;
	}

	public function getFileLogger(): LoggerInterface {
		return $this->fileLogger;
	}

	public function getSyslogLogger(): LoggerInterface {
		return $this->syslogLogger;
	}

	/*
	public function setFileLogger(LoggerInterface $logger): void {
		$this->fileLogger = $logger;
	}

	public function setSyslogLogger(LoggerInterface $logger): void {
		$this->syslogLogger = $logger;
	}

	public function setRoutes(RouteCollection $routes): void {
		$this->routes = $routes;
	}
	*/

	public function getRspamdStat(): array {
		if (!$this->is_admin || Config::get('rspamd_stat_disable')) {
			return [];
		}

		if (Helper::env_bool('REDIS_ENABLE')) {
			$redisKey = Config::get('rspamd_stat_redis_key');
			$ttl = Config::get('rspamd_stat_redis_cache_ttl');

			// Try fetching from redis cache first
			try {
				$cached = App::cache()->get($redisKey);
				if ($cached !== false) {
					$stats = json_decode($cached, true);
					if (is_array($stats) && !empty($stats)) {
						$this->fileLogger->debug("Rspamd stats loaded from Redis cache");
						return $stats;
					}
					$this->fileLogger->warning("Empty Rspamd stats returned from Redis cache");
				}
			} catch (Throwable $e) {
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
			} catch (Exception $e) {
				$this->fileLogger->error("Stat request to '{$api_server}' failed: " . $e->getMessage());
				continue;
			}
		}

		if (Helper::env_bool('REDIS_ENABLE')) {
			// Store in Redis for future use
			try {
				App::cache()->set($redisKey, json_encode($stats), $ttl);
				$this->fileLogger->debug("Rspamd stats cached in Redis for {$ttl} seconds");
			} catch (Throwable $e) {
				$this->fileLogger->error("Redis error when writing Rspamd stats: " . $e->getMessage());
			}
		}

		return $stats;
	}

	public function getRedisConfigTTL(): ?int {
		if (Helper::env_bool('REDIS_ENABLE')) {
			return Config::getRedisConfigTTL(
				App::cache(),
				$_ENV['REDIS_CONFIG_KEY']
			);
		}
		return null;
	}

	public function getRedisConfigTTLData(): array {
		$ttl = $this->getRedisConfigTTL();
		if ($ttl === null || $ttl < 0) {
			return [
				'ttl' => $ttl,
				'ttl_human' => null,
				'expires_at' => null,
			];
		}

		$expiresAt = (new DateTimeImmutable())->add(new DateInterval('PT' . $ttl . 'S'));
		return [
			'ttl' => "$ttl sec",
			'ttl_human' => Helper::formatTtlHuman($ttl),
			'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
		];
	}

	public function redisConfigReload(): Response {
		$this->initUrls();

		if (!$this->is_admin) {
			$this->fileLogger->warning("'{$this->username}' tried to reload config in redis without admin authorization");
			$this->flashbag->add('error', "Permission denied");
			return new RedirectResponse($this->searchUrl);
		}

		if (!$this->csrfValid('config_reload')) {
			$this->fileLogger->warning(
				"CSRF check failed on redisConfigReload from " . $_SERVER['REMOTE_ADDR']
			);
			$this->flashbag->add('error', 'Invalid or expired request. Please try again.');
			return new RedirectResponse($this->searchUrl);
		}

		if (!Helper::env_bool('REDIS_ENABLE')) {
			$this->flashbag->add('warning', "Redis is not enabled");
			return new RedirectResponse($this->searchUrl);
		}

		try {

			// Force reload the config and cache it again
			Config::loadAndInitWithRedisCache(
				App::cache(),
				AppConfig::CONFIG_DEFAULT_PATH,
				AppConfig::CONFIG_LOCAL_PATH,
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

	public function dnsFlush(): Response {
		$this->initUrls();

		if (!$this->is_admin) {
			$this->fileLogger->warning("'{$this->username}' tried to flush DNS cache without admin authorization");
			$this->flashbag->add('error', "Permission denied");
			return new RedirectResponse($this->searchUrl);
		}

		if (!$this->csrfValid('dns_flush')) {
			$this->fileLogger->warning(
				"CSRF check failed on dnsFlush from " . $_SERVER['REMOTE_ADDR']
			);
			$this->flashbag->add('error', 'Invalid or expired request. Please try again.');
			return new RedirectResponse($this->searchUrl);
		}

		if (!Helper::env_bool('REDIS_ENABLE')) {
			$this->flashbag->add('warning', "Redis is not enabled");
			return new RedirectResponse($this->searchUrl);
		}

		try {
			$deleted = Helper::flush_dns_cache();
			$this->fileLogger->info("DNS cache flushed: {$deleted} entries removed");
			$this->flashbag->add('info', "DNS cache flushed: {$deleted} entries removed");

		} catch (Throwable $e) {
			$this->fileLogger->error("DNS Flush failed: " . $e->getMessage());
			$this->flashbag->add('error', "dnsFlush failed");
		}

		return new RedirectResponse($this->searchUrl);
	}

	protected function getUserContext(): array {
		return [
			'is_admin' => $this->is_admin,
			'username' => $this->username,
			'user_id' => $this->user_id,
			'email' => $this->email,
			'user_aliases' => $this->user_aliases,
		];
	}

	protected function getAdminWarnings(): void {
		if (!$this->is_admin) {
			return;
		}

		if (!App::migrationStatus()->hasMigrationsTable()) {
			$message = "Migration tracking table is missing. Please run db:migrate";
			$this->flashbag->add('warning', $message);
			$this->fileLogger->warning($message);
			return;
		}

		$migrationsStatus = App::migrationStatus()->getAllMigrationStates();

		foreach ($migrationsStatus as $migration => $status) {

			if ($status === Migrations::STATUS_COMPLETED) {
				continue;
			}

			$message = "Database migration: '" .
				Migrations::MIGRATION_DESCR[$migration] .
				"' is " .
				($status ?? Migrations::STATUS_PENDING);

			if ($status === Migrations::STATUS_RUNNING) {
				$this->flashbag->add('info', $message);
			} else {
				$message .= ". See " .
					Migrations::MIGRATION_HELP[$migration];
				$this->flashbag->add('warning', $message);
				$this->fileLogger->warning($message);
			}
		}
	}

}
