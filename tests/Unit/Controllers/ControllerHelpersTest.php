<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The Controller's helpers, without a database. A request must carry a
 Session: its flash bag and login values are taken from it. A CSRF token
 is valid only for its own id, and only when the request carries it as
 _token. Logging out or clearing the session forgets the user and the
 cached home and search pages, which are the admin's or the user's own;
 clearing it also ends the PHP session and takes the login and flashes
 from the one SessionManager keeps. The helpers are for the app's
 controllers, its subclasses: none is private.
 The user context, role, addresses (deduplicated, keys kept) and loggers
 are read as set. The Redis config cache's TTL is shown, with when it
 expires, only with REDIS_ENABLE and a RedisCache. An admin's pages warn
 of a missing migrations table (and of nothing else then), of each
 migration not completed (a running one as info, others with what to
 run), in the flash messages, again on every page.
*/

namespace Tests\Unit\Controllers;

use App\Core\App;
use App\Core\AppContainer;
use App\Core\Cache\RedisCache;
use App\Core\Database\MigrationStatus;
use App\Core\Routing\RouteName;
use App\Core\SessionManager;
use App\Controllers\Controller;
use App\Inventory\Migrations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Redis;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManager;
use Tests\Unit\RecordingCache;
use Tests\Unit\RecordingLogger;
use Tests\Unit\UnitTestCase;

final class ControllerHelpersTest extends UnitTestCase
{
	/** @var list<string> each route a URL was made for */
	private array $routes = [];

	/** @param array<string, mixed> $values */
	private static function session(array $values = []): Session {
		$session = new Session(new MockArraySessionStorage());
		/** @var mixed $value */
		foreach ($values as $key => $value) {
			$session->set($key, $value);
		}
		return $session;
	}

	/** @param array<string, mixed> $query */
	private function controller(?Session $session = null, array $query = []): Controller {
		$controller = new Controller();
		$request = new Request($query);
		$request->setSession($session ?? self::session());
		$controller->setRequest($request);
		$urls = $this->createStub(UrlGeneratorInterface::class);
		$urls->method('generate')->willReturnCallback(function (string $name, array $parameters = []): string {
			$this->routes[] = $name;
			return "/{$name}" . ($parameters === [] ? '' : '/' . implode('/', array_map(fn (mixed $p): string => is_scalar($p) ? (string) $p : '', $parameters)));
		});
		$controller->setUrlGenerator($urls);
		return $controller;
	}

	// as the app's controllers call them: so not private
	private static function call(Controller $controller, string $method, mixed ...$arguments): mixed {
		$helper = new ReflectionMethod(Controller::class, $method);
		self::assertFalse($helper->isPrivate(), "{$method}() is private");
		return $helper->invoke($controller, ...$arguments);
	}

	private static function read(Controller $controller, string $property): mixed {
		return (new ReflectionProperty(Controller::class, $property))->getValue($controller);
	}

	private const array ALICE = ['is_admin' => false, 'username' => 'alice', 'user_id' => 7, 'email' => 'alice@example.org', 'user_aliases' => ['al@example.org']];

	public function testRequest(): void {
		$session = self::session(self::ALICE);
		$controller = $this->controller($session);
		$this->assertSame([$session, $session->getFlashBag()], [self::read($controller, 'session'), self::read($controller, 'flashbag')]);
		$this->assertSame(self::ALICE, self::call($controller, 'getUserContext'));
	}

	public function testRequestWithoutSession(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Session not initialized on request.');
		(new Controller())->setRequest(new Request());
	}

	public function testRequestWithAnotherSession(): void {
		$request = new Request();
		$request->setSession($this->createStub(SessionInterface::class));
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Session on request is not a Session.');
		(new Controller())->setRequest($request);
	}

	/** @return array<string, array{?string, string, bool}> the _token sent (null: the one issued for logout), the id checked, valid */
	public static function tokens(): array {
		return [
			'its own' => [null, 'logout', true],
			'another id\'s' => [null, 'delete_user', false],
			'a wrong one' => ['not-the-token', 'logout', false],
			'empty' => ['', 'logout', false],
		];
	}

	#[DataProvider('tokens')]
	public function testCsrf(?string $sent, string $id, bool $valid): void {
		$session = self::session();
		$issuer = $this->controller($session);
		/** @var CsrfTokenManager $manager */
		$manager = self::call($issuer, 'csrfManager');
		$this->assertSame($manager, self::call($issuer, 'csrfManager'), 'one manager per controller');
		$token = $sent ?? $manager->getToken('logout')->getValue();
		$this->assertSame($valid, self::call($this->controller($session, ['_token' => $token]), 'csrfValid', $id));
	}

	// even when one was issued for the id
	public function testCsrfWithoutToken(): void {
		$session = self::session();
		$controller = $this->controller($session);
		$manager = self::call($controller, 'csrfManager');
		$this->assertInstanceOf(CsrfTokenManager::class, $manager);
		$manager->getToken('logout');
		$this->assertFalse(self::call($controller, 'csrfValid', 'logout'));
	}

	public function testLoggedOut(): void {
		$controller = $this->controller(self::session(['is_admin' => true] + self::ALICE));
		self::call($controller, 'getHomepageUrl');
		self::call($controller, 'getSearchUrl');
		$controller->unsetSessionVars();
		$this->assertSame(
			[false, null, null, null, [], null, null],
			[self::read($controller, 'is_admin'), self::read($controller, 'username'), self::read($controller, 'user_id'), self::read($controller, 'email'),
				self::read($controller, 'user_aliases'), self::read($controller, 'homepageUrl'), self::read($controller, 'searchUrl')]
		);
		$this->assertSame('/day_logs', self::call($controller, 'getHomepageUrl'));

		// and when the login changes
		self::call($controller, 'getSearchUrl');
		self::call($controller, 'refreshUrls');
		$this->assertSame([null, null], [self::read($controller, 'homepageUrl'), self::read($controller, 'searchUrl')]);
	}

	// the PHP session too, so in a process of its own
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testClearSession(): void {
		session_start();
		$_SESSION['seen'] = true;
		$session = self::session(self::ALICE);
		$controller = $this->controller($session);
		// the app's session, which SessionManager keeps
		$kept = self::session(['username' => 'alice', 'theme' => 'dark']);
		$kept->getFlashBag()->add('error', 'Session expired');
		(new ReflectionProperty(SessionManager::class, 'session'))->setValue(null, $kept);
		$controller->clearSession();
		$this->assertSame(
			[PHP_SESSION_NONE, [], false, null, null, null, ['theme' => 'dark'], []],
			[session_status(), $_SESSION, (new ReflectionProperty(Controller::class, 'session'))->isInitialized($controller), $session->get('username'),
				self::read($controller, 'username'), self::read($controller, 'email'), $kept->all(), $kept->getFlashBag()->peekAll()]
		);
	}

	public function testUrls(): void {
		$controller = $this->controller();
		$this->assertSame(
			['/detail/id/7', '/search?subject=a+b&page=2'],
			[self::call($controller, 'url', RouteName::DETAIL, ['type' => 'id', 'value' => 7]), self::call($controller, 'urlWithQuery', RouteName::SEARCH, ['subject' => 'a b', 'page' => '2'])]
		);
	}

	// made once, until the login changes
	public function testHomepageAndSearch(): void {
		$controller = $this->controller(self::session(self::ALICE));
		$this->assertSame(['/day_logs', '/day_logs', '/search', '/search'], [
			self::call($controller, 'getHomepageUrl'), self::call($controller, 'getHomepageUrl'),
			self::call($controller, 'getSearchUrl'), self::call($controller, 'getSearchUrl'),
		]);
		$admin = $this->controller(self::session(['is_admin' => true]));
		$this->assertSame(['/admin_day_logs', '/admin_search'], [self::call($admin, 'getHomepageUrl'), self::call($admin, 'getSearchUrl')]);
		$this->assertSame(['day_logs', 'search', 'admin_day_logs', 'admin_search'], $this->routes);
	}

	public function testRole(): void {
		$this->assertSame(['user', 'admin'], [$this->controller(self::session(self::ALICE))->getRole(), $this->controller(self::session(['is_admin' => true]))->getRole()]);
	}

	public function testUserEmailAddresses(): void {
		$controller = $this->controller(self::session(['email' => 'alice@example.org', 'user_aliases' => ['al@example.org', 'alice@example.org', '', 'a2@example.org']]));
		$this->assertSame([0 => 'alice@example.org', 1 => 'al@example.org', 4 => 'a2@example.org'], $controller->getUserEmailAddresses());
		$this->assertSame([], $this->controller()->getUserEmailAddresses());
	}

	public function testRuntimeAndLoggers(): void {
		$syslog = new RecordingLogger();
		App::swap(new AppContainer(0.0, 0, $this->log, $syslog, null, null, null, false));
		$controller = new Controller();
		$this->assertSame([$this->log, $syslog], [$controller->getFileLogger(), $controller->getSyslogLogger()]);
		$runtime = self::call($controller, 'getRuntime');
		$this->assertIsString($runtime);
		$this->assertMatchesRegularExpression('/^Execution time: \d+\.\d{3} sec \| Memory used: -?\d+\.\d{2} MB \| Peak memory: \d+\.\d{2} MB$/D', $runtime);
	}

	private function redis(int|false $ttl): RedisCache {
		$client = $this->createMock(Redis::class);
		$client->expects($this->once())->method('ttl')->with('rqwatch_config_test')->willReturn($ttl);
		$cache = new RedisCache($this->log);
		(new ReflectionProperty(RedisCache::class, 'client'))->setValue($cache, $client);
		return $cache;
	}

	/** @return array<string, array{int, string}> the TTL, as shown */
	public static function ttls(): array {
		return [
			'minutes' => [125, '2m 5s'],
			'seconds' => [59, '59s'],
			'expiring' => [0, '0s'],
		];
	}

	#[DataProvider('ttls')]
	public function testRedisConfigTtl(int $ttl, string $human): void {
		$this->setEnv('REDIS_ENABLE', 'true');
		$this->setEnv('REDIS_CONFIG_KEY', 'rqwatch_config_test');
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, null, null, $this->redis($ttl), false));
		$before = date('Y-m-d H:i:s', time() + $ttl);
		$data = self::call(new Controller(), 'getRedisConfigTTLData');
		$after = date('Y-m-d H:i:s', time() + $ttl);
		$this->assertIsArray($data);
		$this->assertSame(["{$ttl} sec", $human], [$data['ttl'] ?? null, $data['ttl_human'] ?? null]);
		$this->assertIsString($data['expires_at'] ?? null);
		$this->assertGreaterThanOrEqual($before, $data['expires_at']);
		$this->assertLessThanOrEqual($after, $data['expires_at']);
	}

	public function testRedisConfigTtlGone(): void {
		$this->setEnv('REDIS_ENABLE', 'true');
		$this->setEnv('REDIS_CONFIG_KEY', 'rqwatch_config_test');
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, null, null, $this->redis(-2), false));
		$this->assertSame(['ttl' => null, 'ttl_human' => null, 'expires_at' => null], self::call(new Controller(), 'getRedisConfigTTLData'));
	}

	// no Redis asked: not enabled, or a cache that is not Redis
	public function testNoRedisConfigTtl(): void {
		$none = ['ttl' => null, 'ttl_human' => null, 'expires_at' => null];
		$this->setEnv('REDIS_ENABLE', null);
		$redis = new RedisCache($this->log);
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, null, null, $redis, false));
		$this->assertSame($none, self::call(new Controller(), 'getRedisConfigTTLData'));
		$this->setEnv('REDIS_ENABLE', 'true');
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, null, null, new RecordingCache(), false));
		$this->assertSame($none, self::call(new Controller(), 'getRedisConfigTTLData'));
	}

	// without the table, no migration is known completed
	private function migrations(bool $table): MigrationStatus {
		$status = new MigrationStatus(null, $this->log);
		$status->setMigrationTableExists($table);
		foreach (Migrations::MIGRATIONS as $migration) {
			$status->setMigrationState($migration, $table ? Migrations::STATUS_COMPLETED : null);
		}
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, null, $status, null, false));
		return $status;
	}

	// a user is not warned, and nothing is asked
	public function testNoWarningsForAUser(): void {
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, null, null, null, false));
		$controller = $this->controller(self::session(self::ALICE));
		self::call($controller, 'getAdminWarnings');
		$this->assertSame([[], []], [self::call($controller, 'getFlashes'), $this->log->lines]);
	}

	public function testNoMigrationsTable(): void {
		$this->migrations(false);
		$message = 'Migration tracking table is missing. Please run db:migrate';
		$this->assertSame(['warning' => [$message]], self::call($this->controller(self::session(['is_admin' => true])), 'getFlashes'));
		$this->assertSame(["warning {$message}"], $this->log->lines);
	}

	public function testMigrationWarnings(): void {
		$status = $this->migrations(true);
		$status->setMigrationState(Migrations::MAP_HITS, Migrations::STATUS_RUNNING);
		$status->setMigrationState(Migrations::EVER_STORED, null);
		$status->setMigrationState(Migrations::MAIL_LOG_VIRUSES, Migrations::STATUS_FAILED);
		$session = self::session(['is_admin' => true, 'username' => 'root']);
		$session->getFlashBag()->add('success', 'Saved');
		$controller = $this->controller($session);
		$warning = fn (string $migration, string $state): string => "Database migration: '" . Migrations::MIGRATION_DESCR[$migration] . "' is {$state}.\n" . Migrations::MIGRATION_HELP[$migration];
		$pending = $warning(Migrations::EVER_STORED, 'pending');
		$failed = $warning(Migrations::MAIL_LOG_VIRUSES, 'failed');
		$this->assertSame([
			'success' => ['Saved'],
			'warning' => [$pending, $failed],
			'info' => ["Database migration: '" . Migrations::MIGRATION_DESCR[Migrations::MAP_HITS] . "' is running"],
		], self::call($controller, 'getFlashes'));
		$this->assertSame(["warning {$pending}", "warning {$failed}"], $this->log->lines);
		// the warnings again on every page, other messages once
		$again = self::call($controller, 'getFlashes');
		$this->assertIsArray($again);
		$this->assertSame(['warning', 'info'], array_keys($again));
	}
}
