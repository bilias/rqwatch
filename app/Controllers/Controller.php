<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Controllers;

//use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Csrf\CsrfTokenManager;
use Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator;
use Symfony\Component\Security\Csrf\TokenStorage\SessionTokenStorage;
use Symfony\Component\Security\Csrf\CsrfToken;

use App\Configuration\Config;

use App\Core\App;
use App\Core\Cache\RedisCache;

use App\Inventory\Migrations;

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
	protected LoggerInterface $fileLogger;
	protected LoggerInterface $syslogLogger;

	private ?CsrfTokenManager $csrfManager = null;
	//private RouteCollection $routes;     // $this->route to access it

	/*
	 The Router builds controllers without arguments (Symfony's
	 ControllerResolver) and then injects these through setRequest() and
	 setUrlGenerator(); a cron builds a ViewController for twigView() only.
	*/
	/** @psalm-suppress PropertyNotSetInConstructor set by setRequest() */
	protected Request $request;
	// When PHP 8.3 support ends: make it final and drop its ignoreErrors entry in qa/phpstan.neon
	/** @psalm-suppress PropertyNotSetInConstructor set by setRequest() */
	protected Session $session;
	/** @psalm-suppress PropertyNotSetInConstructor set by setRequest() */
	protected FlashBagInterface $flashbag;
	/** @psalm-suppress PropertyNotSetInConstructor set by setUrlGenerator() */
	protected UrlGeneratorInterface $urlGenerator;

	private ?string $homepageUrl = null;
	private ?string $searchUrl = null;

	protected bool $is_admin = false;
	protected ?string $username = null;
	protected ?int $user_id = null;
	protected ?string $email = null;
	/** @var array<int, string> */
	protected array $user_aliases = [];

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

		$session = $request->getSession();
		if (!$session instanceof Session) {
			throw new RuntimeException("Session on request is not a Session.");
		}
		$this->session = $session;
		$this->flashbag = $session->getFlashBag();

		$this->setSessionVars($this->session);
	}

	public function setSessionVars(Session $session): void {
		if ($session->has('is_admin')) {
			$this->is_admin = SessionManager::getBool($session, 'is_admin') ?? false;
		}
		if ($session->has('username')) {
			$this->username = SessionManager::getString($session, 'username');
		}
		if ($session->has('user_id')) {
			$this->user_id = SessionManager::getInt($session, 'user_id');
		}
		if ($session->has('email')) {
			$this->email = SessionManager::getString($session, 'email');
		}
		if ($session->has('user_aliases')) {
			$this->user_aliases = SessionManager::getStringList($session, 'user_aliases');
		}
	}

	public function unsetSessionVars(): void {
		$this->is_admin = false;
		$this->username = null;
		$this->user_id = null;
		$this->email = null;
		$this->user_aliases = [];
		$this->refreshUrls();
	}

	public function clearSession(): void {
		SessionManager::destroy();
		$this->session->invalidate();
		session_unset();
		session_destroy();
		unset($this->session);
		$this->unsetSessionVars();
	}

	public function setUrlGenerator(UrlGeneratorInterface $urlGenerator): void {
		$this->urlGenerator = $urlGenerator;
	}

	/** @param array<string, mixed> $parameters route parameters */
	protected function url(RouteName $route, array $parameters = []): string {
		return UrlBuilder::generate($this->urlGenerator, $route, $parameters);
	}

	public function getRole(): string {
		if ($this->is_admin) {
			return 'admin';
		}
		return 'user';
	}

	/** @return array<int, string> the user's email and aliases, deduplicated (keys kept) */
	public function getUserEmailAddresses(): array {
		return array_unique(array_filter(array_merge([$this->email], $this->user_aliases)));
	}

	protected function getRuntime(): string {
		return App::getRuntime();
	}

	protected function refreshUrls(): void {
		$this->homepageUrl = null;
		$this->searchUrl = null;
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

	private function setRoutes(RouteCollection $routes): void {
		$this->routes = $routes;
	}
	*/

	/** @return array<array-key, mixed> API server alias (PHP may key it as an int) => its decoded rspamd stat reply */
	public function getRspamdStat(): array {
		if (!$this->is_admin || Config::getBool('rspamd_stat_disable')) {
			return [];
		}

		// required at boot (ConfigValidator), so set whenever stats are shown
		$redisKey = Config::getRequiredString('rspamd_stat_redis_key');
		$cache = App::cache();
		if ($cache !== null) {

			// Try fetching from redis cache first
			try {
				$cached = $cache->get($redisKey);
				if ($cached !== false) {
					/** @var mixed $stats */
					$stats = json_decode($cached, true);
					if (is_array($stats) && !empty($stats)) {
						$this->fileLogger->debug("Rspamd stats loaded from Redis cache");
						return $stats;
					}
					// cached empty: every server failed recently, don't ask again yet
					if ($stats === []) {
						$this->fileLogger->debug("Rspamd stats unavailable (cached failure)");
						return [];
					}
					$this->fileLogger->warning("Invalid Rspamd stats returned from Redis cache");
				}
			} catch (Throwable $e) {
				$this->fileLogger->error("Redis error when reading Rspamd stats: " . $e->getMessage());
				// fallback to fetching live if Redis fails
			}
		}

		$api_servers = Config::apiServers();

		$apiClient = new ApiClient();
		$password = Helper::env_string('RSPAMD_CONTROLLER_PASS') ?? '';

		// start every request before reading any: the client runs them concurrently,
		// so a dead controller costs one timeout, not one per server
		$responses = [];
		foreach ($api_servers as $api_server => $config) {
			$statUrl = $config['stat_url'] ?? '';
			if ($statUrl === '') {
				$this->fileLogger->error("API server '{$api_server}' has an empty stat_url. Check config.local.php");
				continue;
			}
			try {
				$responses[$api_server] = $apiClient->getWithRspamdPassword($statUrl, $password);
			} catch (Exception $e) {
				$this->fileLogger->error("Stat request to '{$api_server}' failed: " . $e->getMessage());
			}
		}

		$stats = [];
		foreach ($responses as $api_server => $response) {
			try {
				$responseCode = $response->getStatusCode();
				if ($responseCode === Response::HTTP_OK) {
					/** @var mixed $stat */
					$stat = json_decode($response->getContent(), true);
					if (is_array($stat)) {
						$stats[$api_server] = $stat;
					} else {
						$this->fileLogger->error("Stat request to '{$api_server}' returned no JSON object");
					}
				} else {
					$this->fileLogger->error("Stat request to '{$api_server}' failed with error code " . $responseCode . ": " . $response->getContent());
				}
			} catch (Exception $e) {
				$this->fileLogger->error("Stat request to '{$api_server}' failed: " . $e->getMessage());
				continue;
			}
		}

		if ($cache !== null) {
			$ttl = Config::getIntOr('rspamd_stat_redis_cache_ttl', 300);
			// Store in Redis for future use; a total failure for a shorter time
			if ($stats === []) {
				$ttl = min($ttl, Config::getIntOr('rspamd_stat_redis_failure_ttl', 60));
			}
			try {
				$cache->set($redisKey, json_encode($stats, JSON_THROW_ON_ERROR), $ttl);
				$this->fileLogger->debug("Rspamd stats cached in Redis for {$ttl} seconds");
			} catch (Throwable $e) {
				$this->fileLogger->error("Rspamd stats not cached in Redis: " . $e->getMessage());
			}
		}

		return $stats;
	}

	private function getRedisConfigTTL(): ?int {
		$cache = App::cache();
		if (Helper::env_bool('REDIS_ENABLE') && $cache instanceof RedisCache) {
			return Config::getRedisConfigTTL($cache, Config::redisConfigKey());
		}
		return null;
	}

	/** @return array{ttl: int|string|null, ttl_human: string|null, expires_at: string|null} */
	protected function getRedisConfigTTLData(): array {
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

	/** @return array{is_admin: bool, username: string|null, user_id: int|null, email: string|null, user_aliases: array<int, string>} */
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
				$message .= ".\n" . Migrations::MIGRATION_HELP[$migration];
				$this->flashbag->add('warning', $message);
				$this->fileLogger->warning($message);
			}
		}
	}

	// flashes for a rendered page, admin warnings included
	/** @return array<string, list<mixed>> type => messages */
	protected function getFlashes(): array {
		$this->getAdminWarnings();
		/** @var array<string, list<mixed>> $flashes type => messages, as add() stores them */
		$flashes = $this->flashbag->all();
		return $flashes;
	}

	protected function getHomepageUrl(): string {
		if ($this->homepageUrl === null) {
			$this->homepageUrl = $this->is_admin
				? $this->url(RouteName::ADMIN_DAY_LOGS)
				: $this->url(RouteName::DAY_LOGS);
		}

		return $this->homepageUrl;
	}

	protected function getSearchUrl(): string {
		if ($this->searchUrl === null) {
			$this->searchUrl = $this->is_admin
				? $this->url(RouteName::ADMIN_SEARCH)
				: $this->url(RouteName::SEARCH);
		}

		return $this->searchUrl;
	}

}
