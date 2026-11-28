<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Logging in and out, on RecordingDatabase, OpenID Connect on
 FakeOidcClient. The login form, with the OpenID Connect button when it is
 on; a logged-in user goes home. A database login with the right password
 records the last login, renews the session id and fills the session (the
 user, the address, admin or not, the provider, the aliases but the
 address itself), dropping what a previous user left; then to the page
 asked for before (a path on this site, not the login, logout or home
 pages) or home. A wrong password is a 401 with the form, logged; a
 throttled address is refused before. OpenID Connect: starting a login
 hands over to the provider, whose failure is said; its callback finishes
 it, creating the user the first time and keeping the ID token, but
 never as the local 'admin'. Logout needs its token and clears the
 session: to the login page, or for an OpenID Connect login to the
 provider (RP-initiated) or back with a link to its logout.
 OpenIDConnectClientException lives in the jumbojett library's client file
 (1.0), whose implicitly nullable parameters PHP 8.4 deprecates as the file
 loads: those deprecations, not rqwatch's, are ignored here.
*/

namespace Tests\Unit\Controllers;

use App\Controllers\LoginController;
use App\Core\App;
use App\Core\AppContainer;
use App\Core\Cache\RedisCache;
use App\Core\Auth\LDAP\LdapDirectory;
use App\Core\Auth\LdapAuth;
use App\Core\Auth\OIDC\OidcClient;
use App\Core\Auth\OpenIDConnectAuth;
use App\Core\Routing\RouteName;
use App\Core\SessionManager;
use App\Models\User;
use Jumbojett\OpenIDConnectClientException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use Redis;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Tests\Unit\FakeLdapDirectory;
use Tests\Unit\FakeOidcClient;
use Tests\Unit\RecordingSessionStorage;

#[IgnoreDeprecations('Implicitly marking parameter \$\w+ as nullable is deprecated')]
final class LoginControllerTest extends ControllerTestCase
{
	private FakeOidcClient $oidc;

	/** @var array<string, mixed>|null the users row, null for none */
	private ?array $user = null;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->setEnv('WEB_SCHEME', 'https');
		$this->setEnv('WEB_HOST', 'rq.example.org');
		$this->setEnv('FAILED_LOGIN_TIMEOUT', '0');
		$this->setEnv('LDAP_AUTH_ENABLED', null);
		$this->setEnv('OPENIDC_AUTH_ENABLED', null);
		$this->setEnv('OPENIDC_URL', 'https://idp.example.org');
		$this->setEnv('OPENIDC_CLIENT_ID', 'rqwatch');
		$this->setEnv('OPENIDC_CLIENT_SECRET', 'client-secret');
		foreach (['OPENIDC_REQUIRE_PKCE', 'OPENIDC_PREFERRED_USERNAME_ATTR', 'OPENIDC_ADMINS', 'OPENIDC_DEBUG_CLAIMS', 'OPENIDC_REQUIRE_VERIFIED_EMAIL', 'OPENIDC_RP_INITIATED_LOGOUT', 'OPENIDC_UPDATE_NAME_ON_LOGIN', 'LOGIN_THROTTLE_ENABLE', 'REDIS_ENABLE', 'IDLE_TIMEOUT'] as $setting) {
			$this->setEnv($setting, null);
		}
		$this->oidc = new FakeOidcClient();
		$this->oidc->userInfo = (object) ['preferred_username' => 'carol', 'sub' => 'c-1', 'email' => 'Carol@Example.org', 'email_verified' => true, 'given_name' => 'Carol', 'family_name' => 'Doe'];
		(new ReflectionProperty(OpenIDConnectAuth::class, 'client'))->setValue(null, fn (string $url, string $id, string $secret): OidcClient => $this->oidc);
		$this->user = ['id' => 7, 'username' => 'alice', 'password' => password_hash('s3cret!', PASSWORD_BCRYPT, ['cost' => 4]), 'email' => 'Alice@Example.org', 'is_admin' => 0, 'auth_provider' => 0, 'session_version' => 2];
		$this->answers = [
			['from `users` where `username` = ?', $this->byName(...)],
			['from `users` where `users`.`id` = ?', $this->byId(...)],
			['from `mail_aliases`', [['alias' => 'A.Smith@example.org'], ['alias' => 'alice@example.org'], ['alias' => ' ']]],
		];
		$this->db->affect = fn (): int => 1;
	}

	/**
	 * @param list<mixed> $bindings
	 * @return list<array<string, mixed>>
	 */
	private function byName(array $bindings): array {
		$user = $this->user;
		return $user !== null && ($bindings[0] ?? null) === $user['username'] ? [$user] : [];
	}

	/** @return list<array<string, mixed>> */
	private function byId(): array {
		return $this->user === null ? [] : [$this->user];
	}

	#[\Override]
	protected function tearDown(): void {
		(new ReflectionProperty(OpenIDConnectAuth::class, 'client'))->setValue(null, null);
		(new ReflectionProperty(LdapAuth::class, 'directory'))->setValue(null, null);
		(new ReflectionProperty(SessionManager::class, 'session'))->setValue(null, null);
		(new ReflectionProperty(SessionManager::class, 'session_timeout'))->setValue(null, null);
		parent::tearDown();
	}

	/** @param array<string, mixed> $parameters */
	private function url(RouteName $route, array $parameters = []): string {
		return $this->urls->generate($route->value, $parameters);
	}

	private function login(Request $request): LoginController {
		return $this->controller(new LoginController(), $request);
	}

	/**
	 * @param array<string, mixed> $session
	 * @return array{Response, Request}
	 */
	private function postLogin(string $username = 'Alice ', string $password = 's3cret!', array $session = []): array {
		$request = self::request('https://rq.example.org/login', $session);
		$request->setMethod('POST');
		$request->request->replace(['login_form' => ['username' => $username, 'password' => $password, '_token' => self::csrf($request, 'login_form')]]);
		return [$this->login($request)->login(), $request];
	}

	public function testForm(): void {
		$request = self::request('/login');
		self::flash($request, 'info', 'Logged out');
		$response = $this->login($request)->login();
		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());
		$html = (string) $response->getContent();
		$this->assertStringContainsString('name="login_form[username]"', $html);
		$this->assertStringContainsString('Logged out', $html);
		$this->assertStringNotContainsString('Login with', $html);
	}

	// a session that timed out and then logged out: no "session expired" message
	public function testFormAfterLogoutNoExpiredMessage(): void {
		$request = self::request('/login', ['login_redirect' => $this->url(RouteName::LOGOUT)]);
		self::flash($request, 'warning', 'Session expired');
		$this->assertStringNotContainsString('Session expired', (string) $this->login($request)->login()->getContent());
	}

	public function testFormWithOpenIdConnect(): void {
		$this->setEnv('OPENIDC_AUTH_ENABLED', 'true');
		$html = (string) $this->login(self::request('/login'))->login()->getContent();
		$this->assertStringContainsString("<a href='" . $this->url(RouteName::LOGIN_OPENIDC) . "'><button>Login with ", $html);
	}

	public function testAlreadyLoggedIn(): void {
		$request = self::request('/login', self::USER);
		$this->assertRedirect($this->url(RouteName::DAY_LOGS), $this->login($request)->login());
		$this->assertSame(['info' => ['Already logged in']], self::flashes($request));
		$this->assertSame(["warning 'alice' Already logged in"], $this->log->lines);
	}

	public function testLogin(): void {
		$stale = ['username' => 'bob', 'filters' => ['x'], 'old_username' => 'root', 'old_auth_provider' => 'DB', 'openidc_id_token' => 't', 'user_aliases' => ['b@example.org']];
		$request = self::request('https://rq.example.org/login', $stale);
		$request->getSession()->remove('username');
		$request->setMethod('POST');
		$request->request->replace(['login_form' => ['username' => ' Alice ', 'password' => 's3cret!', '_token' => self::csrf($request, 'login_form')]]);
		$before = $request->getSession()->getId();
		$response = $this->login($request)->login();
		$this->assertRedirect($this->url(RouteName::DAY_LOGS), $response);
		$session = $request->getSession();
		$this->assertNotSame($before, $session->getId());
		$this->assertSame(
			['alice', 'alice@example.org', false, 'DB', 7, 2, ['a.smith@example.org']],
			[$session->get('username'), $session->get('email'), $session->get('is_admin'), $session->get('auth_provider'), $session->get('user_id'), $session->get('session_version'), $session->get('user_aliases')]
		);
		foreach (['filters', 'old_username', 'old_auth_provider', 'openidc_id_token'] as $gone) {
			$this->assertFalse($session->has($gone), $gone);
		}
		$this->assertSame('alice', $this->selected('from `users` where `username` = ?')[1][0] ?? null);
		$update = $this->db->changes[0] ?? null;
		$this->assertNotNull($update);
		$this->assertStringStartsWith('update `users` set `last_login` = ?', $update[0]);
		$this->assertStringNotContainsString('firstname', $update[0]);
		$this->assertStringStartsWith("info User login: 'alice' (DB)", $this->log->lines[0] ?? '');
		// the id logged is the one before the rotation
		$this->assertSame([false, 'alice@example.org', '192.0.2.7', $before], array_values($this->log->contexts[0] ?? []));
		$this->assertSame(['is_admin', 'email', 'ip', 'session_id'], array_keys($this->log->contexts[0] ?? []));
		// the version as stored: not read back when nothing changed
		$this->assertNull($this->selected('select `session_version` from `users`'));
	}

	// the new id keeps the session's data (flashes, CSRF token)
	public function testLoginRotatesKeepingData(): void {
		$storage = new RecordingSessionStorage();
		$request = self::request('https://rq.example.org/login');
		$request->setSession(new Session($storage));
		$request->setMethod('POST');
		$request->request->replace(['login_form' => ['username' => 'alice', 'password' => 's3cret!', '_token' => self::csrf($request, 'login_form')]]);
		$this->login($request)->login();
		$this->assertSame([true], $storage->regenerated);
	}

	// before the session version migration: no version, 0
	public function testLoginWithoutVersion(): void {
		$this->user = ['session_version' => null] + (array) $this->user;
		[, $request] = $this->postLogin('alice');
		$this->assertSame(0, $request->getSession()->get('session_version'));
	}

	// the primary address is no alias, as stored or padded; an alias left after it keeps its place in a list
	public function testLoginAliasesAfterThePrimary(): void {
		$this->user = ['email' => ' alice@example.org '] + (array) $this->user;
		$this->answers = array_merge([['from `mail_aliases`', [['alias' => 'alice@example.org'], ['alias' => 'b@example.org']]]], $this->answers);
		[, $request] = $this->postLogin('alice');
		$this->assertSame(['b@example.org'], $request->getSession()->get('user_aliases'));
	}

	// a provider's aliases as given: trimmed, lowercased, the blank and the non-text dropped
	public function testExternalAliasesNormalized(): void {
		$user = (new User())->forceFill(['id' => 7, 'email' => 'alice@example.org']);
		$aliases = (new ReflectionMethod(LoginController::class, 'getMailAliases'))->invoke(null, $user, [' X@Example.org ', ' ', 5, 'ALICE@example.org']);
		$this->assertSame(['a.smith@example.org', 'x@example.org'], $aliases);
	}

	// a password is taken without its surrounding blanks
	public function testPasswordTrimmed(): void {
		[$response] = $this->postLogin('alice', ' s3cret! ');
		$this->assertRedirect($this->url(RouteName::DAY_LOGS), $response);
	}

	public function testAdminLogin(): void {
		$this->user = ['is_admin' => 1, 'username' => 'root'] + (array) $this->user;
		[$response, $request] = $this->postLogin('root');
		$this->assertRedirect($this->url(RouteName::ADMIN_DAY_LOGS), $response);
		$this->assertTrue($request->getSession()->get('is_admin'));
	}

	/** @return array<string, array{string, string|null}> login_redirect, where to (null: home) */
	public static function redirects(): array {
		return [
			'a page' => ['/mail/42?x=1', '/mail/42?x=1'],
			'the login page' => ['/login', null],
			'the logout page' => ['/logout', null],
			'another host' => ['//evil.example', null],
			'a backslash' => ['/\\evil.example', null],
			'a tab' => ["/\t/evil.example", null],
			'not a path' => ['https://evil.example/', null],
		];
	}

	#[DataProvider('redirects')]
	public function testBackToPageAsked(string $asked, ?string $to): void {
		[$response, $request] = $this->postLogin('alice', 's3cret!', ['login_redirect' => $asked]);
		$this->assertRedirect($to ?? $this->url(RouteName::DAY_LOGS), $response);
	}

	public function testBackToPageAskedIsForgotten(): void {
		[, $request] = $this->postLogin('alice', 's3cret!', ['login_redirect' => '/mail/42']);
		$this->assertFalse($request->getSession()->has('login_redirect'));
	}

	public function testWrongPassword(): void {
		[$response, $request] = $this->postLogin('alice', 'wrong');
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
		$this->assertStringContainsString('Wrong username or password', (string) $response->getContent());
		$this->assertSame(["warning (DB) Login failed for user: 'alice' via IP:192.0.2.7"], $this->log->lines);
		$this->assertFalse($request->getSession()->has('username'));
		$this->assertSame([], $this->db->changes);
	}

	// '0' passes the form (1 character or more) but is no password
	public function testPasswordZero(): void {
		[$response, $request] = $this->postLogin('alice', '0');
		$this->assertRedirect($this->url(RouteName::LOGIN), $response);
		$this->assertSame(['error' => ['Login credentials missing']], self::flashes($request));
		$this->assertSame([], $this->db->selects);
	}

	// the failure that reaches LOGIN_MAX_ATTEMPTS_IP blocks the address (a stub Redis counts)
	public function testWrongPasswordBlocks(): void {
		$this->setEnv('REDIS_ENABLE', 'true');
		$this->setEnv('LOGIN_THROTTLE_ENABLE', 'true');
		$this->setEnv('LOGIN_MAX_ATTEMPTS_IP', '3');
		/** @var list<string> $sets */
		$sets = [];
		$redis = $this->createStub(Redis::class);
		$redis->method('exists')->willReturn(0);
		$redis->method('incr')->willReturn(3);
		$redis->method('del')->willReturn(1);
		$redis->method('set')->willReturnCallback(function (mixed $key) use (&$sets): bool {
			$sets[] = is_string($key) ? $key : get_debug_type($key);
			return true;
		});
		$cache = new RedisCache($this->log);
		(new ReflectionProperty(RedisCache::class, 'client'))->setValue($cache, $redis);
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, $this->db->capsule, $this->status, $cache, true));
		[$response] = $this->postLogin('alice', 'wrong');
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
		$this->assertSame(['rqwatch_throttle:block:192.0.2.7'], $sets);
		$this->assertContains('warning IP:192.0.2.7 throttled for 900s after 3 failed login attempts', $this->log->lines);
	}

	// a user deleted between the password check and the login
	public function testUserGoneAfterAuth(): void {
		$reads = 0;
		$this->answers = array_merge([['from `users` where `username` = ?', function () use (&$reads): array {
			return ++$reads === 1 ? [(array) $this->user] : [];
		}]], $this->answers);
		[$response, $request] = $this->postLogin('alice');
		$this->assertRedirect($this->url(RouteName::LOGIN), $response);
		// said once
		$this->assertSame(['error' => ['Authentication problem. Contact admin']], self::flashes($request));
		$this->assertSame(["warning Authenticated user 'alice' not found in DB.", "error Authenticated user 'alice' not found in DB after auth"], $this->log->lines);
		$this->assertFalse($request->getSession()->has('username'));
	}

	public function testUnknownUser(): void {
		[$response] = $this->postLogin('nobody');
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
	}

	public function testThrottled(): void {
		$this->setEnv('REDIS_ENABLE', 'true');
		$this->setEnv('LOGIN_THROTTLE_ENABLE', 'true');
		$this->cache->values['rqwatch_throttle:block:192.0.2.7'] = '1';
		[$response, $request] = $this->postLogin();
		$this->assertRedirect($this->url(RouteName::LOGIN), $response);
		$this->assertSame(['error' => ['Max wrong login attempts reached. Please try again later.']], self::flashes($request));
		$this->assertSame(["warning Login attempt from throttled IP:192.0.2.7 for user: 'alice' (retry in 0s)"], $this->log->lines);
		$this->assertSame([], $this->db->selects);
	}

	// a stub Redis holding a block for the address that lifts in 42s
	public function testThrottledRetry(): void {
		$this->setEnv('REDIS_ENABLE', 'true');
		$this->setEnv('LOGIN_THROTTLE_ENABLE', 'true');
		$redis = $this->createStub(Redis::class);
		$redis->method('exists')->willReturn(1);
		$redis->method('ttl')->willReturn(42);
		$cache = new RedisCache($this->log);
		(new ReflectionProperty(RedisCache::class, 'client'))->setValue($cache, $redis);
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, $this->db->capsule, $this->status, $cache, true));
		$this->postLogin();
		$this->assertSame(["warning Login attempt from throttled IP:192.0.2.7 for user: 'alice' (retry in 42s)"], $this->log->lines);
	}

	// FAILED_LOGIN_TIMEOUT: the seconds a wrong password waits; none when unset
	public function testWrongPasswordWaits(): void {
		$this->setEnv('FAILED_LOGIN_TIMEOUT', '1');
		$start = microtime(true);
		$this->postLogin('alice', 'wrong');
		$this->assertGreaterThanOrEqual(1.0, microtime(true) - $start);
	}

	public function testWrongPasswordNoWait(): void {
		$this->setEnv('FAILED_LOGIN_TIMEOUT', null);
		$start = microtime(true);
		[$response] = $this->postLogin('alice', 'wrong');
		$this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
		$this->assertLessThan(0.9, microtime(true) - $start);
	}

	private function ldap(?FakeLdapDirectory $directory): void {
		$this->setEnv('LDAP_AUTH_ENABLED', 'true');
		$this->setEnv('LDAP_URI', 'ldaps://ldap.example.org');
		$this->setEnv('LDAP_BASE', 'ou=people,dc=example,dc=org');
		foreach (['LDAP_BIND_DN', 'LDAP_LOGIN_ATTR', 'LDAP_MAIL_ATTR', 'LDAP_MAIL_ALIAS_ATTR', 'LDAP_SN_ATTR', 'LDAP_GIVENNAME_ATTR', 'LDAP_SN_ATTR_FALLBACK',
			'LDAP_GIVENNAME_ATTR_FALLBACK', 'LDAP_MAIL_ATTR_MULTIVALUE', 'LDAP_ADMINS', 'LDAP_TIMEOUT', 'LDAP_UPDATE_NAME_ON_LOGIN'] as $setting) {
			$this->setEnv($setting, null);
		}
		(new ReflectionProperty(LdapAuth::class, 'directory'))->setValue(null, fn (string $uri): ?LdapDirectory => $directory);
	}

	private static function directory(): FakeLdapDirectory {
		$directory = new FakeLdapDirectory();
		$values = ['mail' => ['dave@example.org', 'd.smith@example.org'], 'sn' => ['Smith'], 'givenName' => ['Dave']];
		$attributes = ['count' => count($values)];
		foreach (array_keys($values) as $i => $name) {
			$attributes[$i] = $name;
			$attributes[$name] = ['count' => count($values[$name])] + $values[$name];
		}
		$directory->attributes = $attributes;
		return $directory;
	}

	public function testLdapFirstLogin(): void {
		$this->ldap(self::directory());
		$this->answers = array_merge([['from `mail_aliases`', []]], $this->answers);
		[$response, $request] = $this->postLogin('Dave@Example.org');
		$this->assertRedirect($this->url(RouteName::DAY_LOGS), $response);
		$created = $this->db->rows('users')[0] ?? [];
		$this->assertSame(['dave@example.org', 'd.smith@example.org', 1, 'EXTERNAL_AUTH'], [$created['username'] ?? null, $created['email'] ?? null, $created['auth_provider'] ?? null, $created['password'] ?? null]);
		$session = $request->getSession();
		$this->assertSame(['dave@example.org', 'LDAP', ['dave@example.org']], [$session->get('username'), $session->get('auth_provider'), $session->get('user_aliases')]);
	}

	// an LDAP alias also kept in the database is listed once
	public function testLdapAliasAlsoInDatabase(): void {
		$this->ldap(self::directory());
		$this->user = ['username' => 'dave@example.org', 'email' => 'd.smith@example.org', 'auth_provider' => 1] + (array) $this->user;
		$this->answers = array_merge([['from `mail_aliases`', [['alias' => 'Dave@example.org']]]], $this->answers);
		[, $request] = $this->postLogin('dave@example.org');
		$this->assertSame(['dave@example.org'], $request->getSession()->get('user_aliases'));
	}

	// a database user now in LDAP: the provider and address follow, its other sessions end
	public function testLdapLoginOfDatabaseUser(): void {
		$this->ldap(self::directory());
		$this->setEnv('LDAP_UPDATE_NAME_ON_LOGIN', 'true');
		$this->user = ['username' => 'dave@example.org', 'email' => 'old@example.org'] + (array) $this->user;
		$this->answers = array_merge([['select `session_version` from `users`', [['session_version' => 3]]]], $this->answers);
		[$response, $request] = $this->postLogin('dave@example.org');
		$this->assertRedirect($this->url(RouteName::DAY_LOGS), $response);
		$update = $this->db->changes[0] ?? null;
		$this->assertNotNull($update);
		$this->assertStringStartsWith('update `users` set `email` = ?, `auth_provider` = ?, `last_login` = ?, `firstname` = ?, `lastname` = ?', $update[0]);
		$this->assertSame(['d.smith@example.org', 1], array_slice($update[1], 0, 2));
		$this->assertSame(['Dave', 'Smith'], array_slice($update[1], 3, 2));
		$this->assertStringContainsString('`session_version` = `session_version` + 1', $this->db->changes[1][0] ?? '');
		$this->assertSame(3, $request->getSession()->get('session_version'));
	}

	// a session version below 0 cannot be: refused, not stored
	public function testLdapNegativeSessionVersion(): void {
		$this->ldap(self::directory());
		$this->user = ['username' => 'dave@example.org', 'email' => 'old@example.org'] + (array) $this->user;
		$this->answers = array_merge([['select `session_version` from `users`', [['session_version' => -1]]]], $this->answers);
		try {
			$this->postLogin('dave@example.org');
			$this->fail('logged in');
		} catch (RuntimeException $e) {
			$this->assertSame("users.session_version of 'dave@example.org' is negative", $e->getMessage());
		}
	}

	public function testLdapUnavailable(): void {
		$this->ldap(null);
		[$response, $request] = $this->postLogin('dave@example.org');
		$this->assertRedirect($this->url(RouteName::LOGIN), $response);
		$this->assertSame(['error' => ['The login service is unavailable. Please try again later.']], self::flashes($request));
		$this->assertContains("warning (LDAP) Login service unavailable for user: 'dave@example.org' via IP:192.0.2.7", $this->log->lines);
	}

	public function testLogoutBadToken(): void {
		$request = self::request('/logout', self::USER, ['_token' => 'forged']);
		$this->assertRedirect($this->url(RouteName::DAY_LOGS), $this->login($request)->logout());
		$this->assertSame(["warning Logout without valid token for 'alice' from 192.0.2.7"], $this->log->lines);
		$this->assertSame('alice', $request->getSession()->get('username'));
	}

	/**
	 * @param array<string, mixed> $session
	 * @return array{Response, Request}
	 */
	private function logout(array $session): array {
		$request = self::request('https://rq.example.org/logout', $session);
		$request->query->set('_token', self::csrf($request, 'logout'));
		// the native session the controller destroys is not started here (MockArraySessionStorage)
		set_error_handler(fn (int $no, string $message): bool => str_starts_with($message, 'session_destroy(): Trying to destroy uninitialized session'), E_WARNING);
		try {
			return [$this->login($request)->logout(), $request];
		} finally {
			restore_error_handler();
		}
	}

	public function testLogout(): void {
		[$response, $request] = $this->logout(self::USER);
		$this->assertRedirect($this->url(RouteName::LOGIN), $response);
		$this->assertFalse($request->getSession()->has('username'));
		$this->assertStringStartsWith("info User logout: 'alice'", $this->log->lines[0] ?? '');
		$this->assertSame(['is_admin', 'email', 'ip', 'session_id'], array_keys($this->log->contexts[0] ?? []));
		$this->assertSame([false, 'alice@example.org', '192.0.2.7'], array_slice(array_values($this->log->contexts[0] ?? []), 0, 3));
	}

	// no one logged in: nothing to log
	public function testLogoutNoUser(): void {
		[$response] = $this->logout([]);
		$this->assertRedirect($this->url(RouteName::LOGIN), $response);
		$this->assertSame([], $this->log->lines);
	}

	// logged in as an OpenID Connect user from a database login: a plain logout
	public function testLogoutLoggedInAsFromDatabase(): void {
		[$response] = $this->logout(['auth_provider' => 'OPENIDC', 'old_auth_provider' => 'DB'] + self::USER);
		$this->assertRedirect($this->url(RouteName::LOGIN), $response);
	}

	/** @return array<string, array{array<string, mixed>}> */
	public static function oidcSessions(): array {
		return [
			'an OpenID Connect login' => [['auth_provider' => 'OPENIDC']],
			'logged in as another, from an OpenID Connect login' => [['auth_provider' => 'DB', 'old_auth_provider' => 'OPENIDC']],
			'an OpenID Connect login, no previous provider' => [['auth_provider' => 'OPENIDC', 'old_auth_provider' => '']],
		];
	}

	/** @param array<string, mixed> $session */
	#[DataProvider('oidcSessions')]
	public function testLogoutOpenIdConnect(array $session): void {
		[$response, $request] = $this->logout($session + self::USER);
		$this->assertRedirect('https://rq.example.org/login?openidc_session_active=1', $response);
		$this->assertFalse($request->getSession()->has('username'));
	}

	public function testLogoutOpenIdConnectAtProvider(): void {
		$this->setEnv('OPENIDC_AUTH_ENABLED', 'true');
		$this->setEnv('OPENIDC_RP_INITIATED_LOGOUT', 'true');
		[$response, $request] = $this->logout(['auth_provider' => 'OPENIDC', 'openidc_id_token' => 'eyJ.id.token'] + self::USER);
		$this->assertCount(1, $this->log->lines);
		$this->assertContains('signOut eyJ.id.token https://rq.example.org/login', $this->oidc->calls);
		$this->assertFalse($request->getSession()->has('username'));
		$this->assertRedirect($this->url(RouteName::LOGIN), $response);
	}

	public function testLogoutOpenIdConnectAtProviderFails(): void {
		$this->setEnv('OPENIDC_AUTH_ENABLED', 'true');
		$this->setEnv('OPENIDC_RP_INITIATED_LOGOUT', 'true');
		$this->oidc->signOutFailure = new RuntimeException('no end_session_endpoint');
		[$response] = $this->logout(['auth_provider' => 'OPENIDC', 'openidc_id_token' => 'eyJ.id.token'] + self::USER);
		$this->assertRedirect($this->url(RouteName::LOGIN), $response);
		$this->assertContains('warning OPENIDC logout failed: no end_session_endpoint', $this->log->lines);
	}

	// OpenID Connect turned off since the login
	public function testLogoutOpenIdConnectNotStarted(): void {
		$this->setEnv('OPENIDC_RP_INITIATED_LOGOUT', 'true');
		[$response] = $this->logout(['auth_provider' => 'OPENIDC', 'openidc_id_token' => 'eyJ.id.token'] + self::USER);
		$this->assertRedirect($this->url(RouteName::LOGIN), $response);
		$this->assertContains('warning OPENIDC logout could not be started', $this->log->lines);
		$this->assertSame([], $this->oidc->calls);
	}

	public function testLogoutOpenIdConnectWithoutToken(): void {
		$this->setEnv('OPENIDC_RP_INITIATED_LOGOUT', 'true');
		[$response] = $this->logout(['auth_provider' => 'OPENIDC'] + self::USER);
		$this->assertRedirect($this->url(RouteName::LOGIN), $response);
		$this->assertSame('warning OPENIDC ID Token empty', end($this->log->lines));
		$this->assertCount(2, $this->log->lines);
	}

	/** @return array<string, array{?string, string}> */
	public static function labels(): array {
		return ['default' => [null, 'OpenID Connect'], 'OPENIDC_LABEL' => ['Campus SSO', 'Campus SSO']];
	}

	#[DataProvider('labels')]
	public function testOpenIdConnectSessionStillActive(?string $label, string $shown): void {
		$this->setEnv('OPENIDC_AUTH_ENABLED', 'true');
		$this->setEnv('OPENIDC_LABEL', $label);
		$request = self::request('/login', [], ['openidc_session_active' => '1']);
		$this->assertRedirect($this->url(RouteName::LOGIN), $this->login($request)->login());
		$this->assertSame(['warning' => ["You are logged out, but your '{$shown}' session is still active. Close your browser to delete the session or click the link below."]], self::flashes($request));
		$this->assertTrue($request->getSession()->has('openidc_logout_url'));
	}

	public function testProviderLogoutLink(): void {
		$this->setEnv('OPENIDC_AUTH_ENABLED', 'true');
		$request = self::request('https://rq.example.org/login', ['openidc_logout_url' => 'https://idp.example.org/logout']);
		$html = (string) $this->login($request)->login()->getContent();
		$this->assertStringContainsString('href="https://idp.example.org/logout?client_id=rqwatch&post_logout_redirect_uri=https%3A%2F%2Frq.example.org%2Flogin"', html_entity_decode($html));
		$this->assertFalse($request->getSession()->has('openidc_logout_url'));
	}

	public function testOpenIdConnectDisabled(): void {
		$request = self::request('/login/openid');
		$this->assertRedirect($this->url(RouteName::DAY_LOGS), $this->login($request)->login_openidc());
		$this->assertSame(['info' => ['OpenID Connect disabled']], self::flashes($request));
		$request = self::request('/login/openid/callback');
		$this->assertRedirect($this->url(RouteName::DAY_LOGS), $this->login($request)->login_openidc_callback());
		$this->assertSame(['warning' => ['OpenID Connect disabled']], self::flashes($request));
	}

	public function testOpenIdConnectStart(): void {
		$this->setEnv('OPENIDC_AUTH_ENABLED', 'true');
		$request = self::request('https://rq.example.org/login/openid');
		$this->assertRedirect($this->url(RouteName::LOGIN), $this->login($request)->login_openidc());
		$this->assertContains('setRedirectURL https://rq.example.org/openidc/callback', $this->oidc->calls);
		$this->assertSame(['error' => ['Authentication failed']], self::flashes($request));
		$this->assertSame(['warning OPENIDC startOpenIdConnectAuthentication() failed with error: Unexpected return from authenticate().'], $this->log->lines);
	}

	public function testOpenIdConnectProviderDownLabel(): void {
		$this->setEnv('OPENIDC_AUTH_ENABLED', 'true');
		$this->setEnv('OPENIDC_LABEL', 'Campus SSO');
		$this->oidc->failure = new OpenIDConnectClientException('no discovery');
		$request = self::request('https://rq.example.org/login/openid');
		$this->login($request)->login_openidc();
		$this->assertSame(['error' => ["The 'Campus SSO' service is currently unavailable"]], self::flashes($request));
	}

	public function testOpenIdConnectProviderDown(): void {
		$this->setEnv('OPENIDC_AUTH_ENABLED', 'true');
		$this->oidc->failure = new OpenIDConnectClientException('no discovery');
		$request = self::request('https://rq.example.org/login/openid');
		$this->assertRedirect($this->url(RouteName::LOGIN), $this->login($request)->login_openidc());
		$this->assertSame(['error' => ["The 'OpenID Connect' service is currently unavailable"]], self::flashes($request));
		$this->assertSame(['warning OPENIDC startOpenIdConnectAuthentication() failed with error: no discovery'], $this->log->lines);
	}

	public function testCallback(): void {
		$this->setEnv('OPENIDC_AUTH_ENABLED', 'true');
		$this->user = null;
		$request = self::request('https://rq.example.org/login/openid/callback', ['login_redirect' => '/mail/42']);
		$response = $this->login($request)->login_openidc_callback();
		$this->assertRedirect('/mail/42', $response);
		$session = $request->getSession();
		$this->assertSame(['carol', 'carol@example.org', 'OPENIDC', 'eyJ.id.token'], [$session->get('username'), $session->get('email'), $session->get('auth_provider'), $session->get('openidc_id_token')]);
		$created = $this->db->rows('users')[0] ?? [];
		$this->assertSame(['carol', 'carol@example.org', 'Carol', 'Doe', 2, 'EXTERNAL_AUTH'], [$created['username'] ?? null, $created['email'] ?? null, $created['firstname'] ?? null, $created['lastname'] ?? null, $created['auth_provider'] ?? null, $created['password'] ?? null]);
	}

	// with no page asked for: home
	public function testCallbackHome(): void {
		$this->setEnv('OPENIDC_AUTH_ENABLED', 'true');
		$this->user = null;
		$request = self::request('https://rq.example.org/login/openid/callback');
		$this->assertRedirect($this->url(RouteName::DAY_LOGS), $this->login($request)->login_openidc_callback());
	}

	/** @return array<string, array{string}> */
	public static function openIdStepsAfterLogout(): array {
		return ['start' => ['login_openidc'], 'callback' => ['login_openidc_callback']];
	}

	// a session that timed out and then logged out: no "session expired" message
	#[DataProvider('openIdStepsAfterLogout')]
	public function testOpenIdConnectAfterLogoutNoExpiredMessage(string $step): void {
		$this->setEnv('OPENIDC_AUTH_ENABLED', 'true');
		$this->user = null;
		$request = self::request('https://rq.example.org/login/openid/callback', ['login_redirect' => $this->url(RouteName::LOGOUT)]);
		self::flash($request, 'warning', 'Session expired');
		$controller = $this->login($request);
		$step === 'login_openidc' ? $controller->login_openidc() : $controller->login_openidc_callback();
		$this->assertArrayNotHasKey('warning', self::flashes($request));
	}

	public function testCallbackUnexpectedError(): void {
		$this->setEnv('OPENIDC_AUTH_ENABLED', 'true');
		$this->oidc->failure = new RuntimeException('database gone');
		$request = self::request('/login/openid/callback');
		$this->assertRedirect($this->url(RouteName::LOGIN), $this->login($request)->login_openidc_callback());
		$this->assertSame(['error' => ['An unexpected authentication error occurred']], self::flashes($request));
		$this->assertSame(['error database gone'], $this->log->lines);
	}

	public function testCallbackNotAsLocalAdmin(): void {
		$this->setEnv('OPENIDC_AUTH_ENABLED', 'true');
		$this->oidc->userInfo = (object) ['preferred_username' => 'admin', 'email' => 'x@example.org', 'email_verified' => true];
		$request = self::request('/login/openid/callback');
		$this->assertRedirect($this->url(RouteName::LOGIN), $this->login($request)->login_openidc_callback());
		$this->assertContains('warning (OPENIDC) login refused for reserved local account \'admin\' from IP:192.0.2.7', $this->log->lines);
		$this->assertContains("error Authenticated user 'admin' not found in DB after auth", $this->log->lines);
		$this->assertSame(['error' => ['Authentication problem. Contact admin']], self::flashes($request));
		$this->assertSame([[], []], [$this->db->rows('users'), $this->db->changes]);
		$this->assertFalse($request->getSession()->has('username'));
	}

	public function testCallbackFails(): void {
		$this->setEnv('OPENIDC_AUTH_ENABLED', 'true');
		$this->oidc->userInfo = (object) ['sub' => 'c-1'];
		$request = self::request('/login/openid/callback');
		$this->assertRedirect($this->url(RouteName::LOGIN), $this->login($request)->login_openidc_callback());
		$this->assertSame(['error' => ['Authentication failed']], self::flashes($request));
	}

	public function testCallbackProviderError(): void {
		$this->setEnv('OPENIDC_AUTH_ENABLED', 'true');
		$this->oidc->failure = new OpenIDConnectClientException('state mismatch');
		$request = self::request('/login/openid/callback');
		$this->assertRedirect($this->url(RouteName::LOGIN), $this->login($request)->login_openidc_callback());
		$this->assertSame(['error' => ['OpenID Connect authentication failed']], self::flashes($request));
		$this->assertSame(['warning OPENIDC callback failed: state mismatch'], $this->log->lines);
	}

	/** @return array<string, array{string}> */
	public static function openIdSteps(): array {
		return ['start' => ['login_openidc'], 'callback' => ['login_openidc_callback']];
	}

	#[DataProvider('openIdSteps')]
	public function testOpenIdConnectLoggedInAlready(string $step): void {
		$this->setEnv('OPENIDC_AUTH_ENABLED', 'true');
		$request = self::request('/login/openid', self::USER);
		$controller = $this->login($request);
		$this->assertRedirect($this->url(RouteName::DAY_LOGS), $step === 'login_openidc' ? $controller->login_openidc() : $controller->login_openidc_callback());
		$this->assertSame(['info' => ['Already logged in']], self::flashes($request));
		$this->assertSame(["warning 'alice' Already logged in"], $this->log->lines);
	}

	/**
	 * $request on the app's session, idle past IDLE_TIMEOUT, with a message
	 * waiting: the login steps start it anew, the message dropped.
	 */
	private static function expired(Request $request): void {
		$storage = new MockArraySessionStorage();
		$storage->setSessionData(['_sf2_meta' => ['u' => time() - 120, 'c' => time() - 3600, 'l' => 0]]);
		$session = new Session($storage);
		$session->start();
		$session->getFlashBag()->add('info', 'Stale message');
		$request->setSession($session);
		(new ReflectionProperty(SessionManager::class, 'session'))->setValue(null, $session);
		(new ReflectionProperty(SessionManager::class, 'session_timeout'))->setValue(null, 60);
	}

	public function testLoginAfterExpiry(): void {
		$request = self::request('https://rq.example.org/login');
		self::expired($request);
		$request->setMethod('POST');
		$request->request->replace(['login_form' => ['username' => 'alice', 'password' => 'wrong', '_token' => self::csrf($request, 'login_form')]]);
		$html = (string) $this->login($request)->login()->getContent();
		$this->assertStringContainsString('Wrong username or password', $html);
		$this->assertStringNotContainsString('Stale message', $html);
	}

	#[DataProvider('openIdSteps')]
	public function testOpenIdConnectAfterExpiry(string $step): void {
		$this->setEnv('OPENIDC_AUTH_ENABLED', 'true');
		$this->oidc->failure = new OpenIDConnectClientException('no discovery');
		$request = self::request('https://rq.example.org/login/openid');
		self::expired($request);
		$controller = $this->login($request);
		$step === 'login_openidc' ? $controller->login_openidc() : $controller->login_openidc_callback();
		$this->assertArrayNotHasKey('info', self::flashes($request));
	}

	/** @param array<string, mixed> $user the users row */
	private function oidcUser(array $user): Request {
		$this->setEnv('OPENIDC_AUTH_ENABLED', 'true');
		$this->user = $user + ['id' => 8, 'username' => 'carol', 'password' => 'EXTERNAL_AUTH', 'email' => 'carol@example.org', 'is_admin' => 0, 'auth_provider' => 2, 'session_version' => 2];
		$request = self::request('https://rq.example.org/login/openid/callback');
		$this->assertRedirect($this->url(RouteName::DAY_LOGS), $this->login($request)->login_openidc_callback());
		return $request;
	}

	private function update(): string {
		$update = $this->db->changes[0] ?? null;
		$this->assertNotNull($update);
		return $update[0];
	}

	// the names follow the provider's own setting only
	public function testOpenIdConnectNamesKept(): void {
		$this->setEnv('LDAP_UPDATE_NAME_ON_LOGIN', 'true');
		$this->oidcUser([]);
		$this->assertStringNotContainsString('firstname', $this->update());
	}

	public function testOpenIdConnectNamesUpdated(): void {
		$this->setEnv('OPENIDC_UPDATE_NAME_ON_LOGIN', 'true');
		$this->oidcUser([]);
		$this->assertStringContainsString('`firstname` = ?, `lastname` = ?', $this->update());
	}

	public function testDatabaseLoginNamesKept(): void {
		$this->setEnv('OPENIDC_UPDATE_NAME_ON_LOGIN', 'true');
		$this->postLogin('alice');
		$this->assertStringNotContainsString('firstname', $this->update());
	}

	/** @return array<string, array{string, string}> the claim of blanks, what is logged */
	public static function blankClaims(): array {
		return [
			'the address' => ['email', "error OIDC user 'carol' has no email claim; login denied"],
			'the username' => ['preferred_username', 'error Empty userinfo or preferred_username from OIDC. Check OPENIDC_PREFERRED_USERNAME_ATTR in .env'],
		];
	}

	// a username or address of blanks at the provider is none: the login is denied, nothing stored
	#[DataProvider('blankClaims')]
	public function testOpenIdConnectBlankClaimDenied(string $claim, string $logged): void {
		$this->setEnv('OPENIDC_AUTH_ENABLED', 'true');
		$this->oidc->userInfo = (object) ([$claim => ' '] + (array) $this->oidc->userInfo);
		$request = self::request('https://rq.example.org/login/openid/callback');
		$this->assertRedirect($this->url(RouteName::LOGIN), $this->login($request)->login_openidc_callback());
		$this->assertSame(['error' => ['Authentication failed']], self::flashes($request));
		$this->assertSame([$logged], $this->log->lines);
		$this->assertSame([], $this->db->changes);
		$this->assertFalse($request->getSession()->has('username'));
	}

	/** @return array<string, array{int}> */
	public static function versions(): array {
		return ['bumped' => [5], 'zero' => [0]];
	}

	// a new address at the provider ends the user's other sessions; the version is read back
	#[DataProvider('versions')]
	public function testOpenIdConnectNewEmail(int $version): void {
		$this->answers = array_merge([['select `session_version` from `users`', [['session_version' => $version]]]], $this->answers);
		$request = $this->oidcUser(['email' => 'old@example.org']);
		$this->assertStringContainsString('`email` = ?', $this->update());
		$this->assertStringContainsString('`session_version` = `session_version` + 1', $this->db->changes[1][0] ?? '');
		$this->assertSame($version, $request->getSession()->get('session_version'));
	}
}
