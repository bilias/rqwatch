<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The two access middlewares, for the cases that need no database:
 anonymous requests (never sent back to login, home or logout after
 login), the admin check on an existing session and what it logs, with the
 request in the context, an external admin-status change that ends the
 session, and a session value of another type than rqwatch writes, which
 is refused.
*/

namespace Tests\Unit\Routing;

use App\Core\App;
use App\Core\AppContainer;
use App\Core\Database\MigrationStatus;
use App\Core\Middleware\AuthMiddleware;
use App\Core\Middleware\Authorization;
use App\Core\Routing\Routes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Tests\Unit\RecordingLogger;

final class MiddlewareTest extends TestCase
{
	private UrlGenerator $urls;
	private bool $reached;

	#[\Override]
	protected function setUp(): void {
		$this->urls = new UrlGenerator(Routes::load()['routes'], new RequestContext());
		$this->reached = false;
	}

	/** @param array<string, mixed> $session attributes to set */
	private function request(string $path, array $session = []): Request {
		$request = Request::create($path);
		$s = new Session(new MockArraySessionStorage());
		/** @var mixed $v */
		foreach ($session as $k => $v) {
			$s->set($k, $v);
		}
		$request->setSession($s);
		return $request;
	}

	/** @return callable(Request): Response */
	private function next(): callable {
		return function (Request $r): Response {
			$this->reached = true;
			return new Response('controller');
		};
	}

	private function assertRedirectsTo(string $route, Response $response): void {
		$this->assertFalse($this->reached, 'Controller must not run');
		$this->assertInstanceOf(RedirectResponse::class, $response);
		$this->assertSame($this->urls->generate($route), $response->getTargetUrl());
	}

	public function testAnonymousIsSentToLogin(): void {
		$request = $this->request('/mail/42');
		$response = (new AuthMiddleware($this->urls, new NullLogger()))->handle($request, $this->next());
		$this->assertRedirectsTo('login', $response);
		$this->assertSame('/mail/42', $request->getSession()->get('login_redirect'));
	}

	/** @return array<string, array{string, string}> path, route */
	public static function noReturnPaths(): array {
		return [
			'login' => ['/login', 'login'],
			'home' => ['/', 'homepage'],
			'logout' => ['/logout', 'logout'],
		];
	}

	// after login, never back to login, home or logout
	#[DataProvider('noReturnPaths')]
	public function testNoReturnToLoginHomeOrLogout(string $path, string $route): void {
		$request = $this->request($path);
		$request->attributes->set('_route', $route);
		$response = (new AuthMiddleware($this->urls, new NullLogger()))->handle($request, $this->next());
		$this->assertRedirectsTo('login', $response);
		$this->assertFalse($request->getSession()->has('login_redirect'));
	}

	/** @return array<string, array{string, bool|null}> LDAP_ADMINS, is_admin in the session (null: not set) */
	public static function adminChanges(): array {
		return [
			'made an admin' => ['alice', false],
			'made an admin, no flag in the session' => ['alice', null],
			'no longer an admin' => ['bob', true],
		];
	}

	// an LDAP user whose admin status no longer matches LDAP_ADMINS logs in again
	#[DataProvider('adminChanges')]
	public function testAdminChangeEndsTheSession(string $admins, ?bool $isAdmin): void {
		$saved = $_ENV['LDAP_ADMINS'] ?? null;
		$_ENV['LDAP_ADMINS'] = $admins;
		try {
			$log = new RecordingLogger();
			$request = $this->request('/mail/42', ['username' => 'alice', 'auth_provider' => 'LDAP']
				+ ($isAdmin === null ? [] : ['is_admin' => $isAdmin]));
			$response = (new AuthMiddleware($this->urls, $log))->handle($request, $this->next());
		} finally {
			if ($saved === null) {
				unset($_ENV['LDAP_ADMINS']);
			} else {
				$_ENV['LDAP_ADMINS'] = $saved;
			}
		}
		$this->assertRedirectsTo('login', $response);
		$session = $request->getSession();
		$this->assertInstanceOf(Session::class, $session);
		$this->assertFalse($session->has('username'), 'the session was ended');
		$this->assertSame(['Your account was changed, please log in again'], $session->getFlashBag()->get('error'));
		$this->assertSame(["info Session of 'alice' ended: account changed"], $log->lines);
	}

	// a database user is not checked against *_ADMINS; before the session_version
	// migration the session is not checked further
	public function testDbUserPassesBeforeTheSessionVersionMigration(): void {
		App::swap(new AppContainer(0.0, 0, new NullLogger(), new NullLogger(), null, new MigrationStatus(null, new NullLogger()), null, false));
		try {
			$request = $this->request('/mail/42', ['username' => 'alice', 'auth_provider' => 'DB', 'is_admin' => false]);
			$response = (new AuthMiddleware($this->urls, new NullLogger()))->handle($request, $this->next());
		} finally {
			App::reset();
		}
		$this->assertTrue($this->reached);
		$this->assertSame('controller', $response->getContent());
	}

	public function testEmptyUsernameIsAnonymous(): void {
		$request = $this->request('/mail/42', ['username' => '']);
		$response = (new AuthMiddleware($this->urls, new NullLogger()))->handle($request, $this->next());
		$this->assertRedirectsTo('login', $response);
	}

	public function testAuthorizationWithoutLoginGoesHome(): void {
		$log = new RecordingLogger();
		$request = $this->request('/admin/users');
		$response = (new Authorization($this->urls, $log))->handle($request, $this->next());
		$this->assertRedirectsTo('homepage', $response);
		$this->assertSame(['error In Authorization without Auth'], $log->lines);
		$this->assertSame([['method' => 'GET', 'uri' => '/admin/users', 'ip' => '127.0.0.1', 'username' => null, 'is_admin' => null]], $log->contexts);
	}

	public function testNonAdminIsDenied(): void {
		$log = new RecordingLogger();
		$request = $this->request('/admin/users', ['username' => 'alice', 'is_admin' => false]);
		$response = (new Authorization($this->urls, $log))->handle($request, $this->next());
		$this->assertRedirectsTo('homepage', $response);
		$this->assertSame(["warning User 'alice' tried to access '/admin/users' without admin authorization"], $log->lines);
		$session = $request->getSession();
		$this->assertInstanceOf(Session::class, $session);
		$this->assertSame(['Permission denied'], $session->getFlashBag()->get('error'));
	}

	public function testAdminPasses(): void {
		$request = $this->request('/admin/users', ['username' => 'root', 'is_admin' => true]);
		$response = (new Authorization($this->urls, new NullLogger()))->handle($request, $this->next());
		$this->assertTrue($this->reached);
		$this->assertSame('controller', $response->getContent());
	}

	// a stored value of another type is a bug: refused, never read as admin
	public function testAdminFlagNotBoolRefused(): void {
		$request = $this->request('/admin/users', ['username' => 'root', 'is_admin' => 1]);
		try {
			(new Authorization($this->urls, new NullLogger()))->handle($request, $this->next());
			$this->fail('expected a RuntimeException');
		} catch (RuntimeException $e) {
			$this->assertSame("Session value 'is_admin' must be true or false, not int", $e->getMessage());
		}
		$this->assertFalse($this->reached, 'Controller must not run');
	}

	public function testAuthProviderNotTextRefused(): void {
		$request = $this->request('/mail/42', ['username' => 'alice', 'auth_provider' => 5, 'is_admin' => false]);
		try {
			(new AuthMiddleware($this->urls, new NullLogger()))->handle($request, $this->next());
			$this->fail('expected a RuntimeException');
		} catch (RuntimeException $e) {
			$this->assertSame("Session value 'auth_provider' must be a string, not int", $e->getMessage());
		}
		$this->assertFalse($this->reached, 'Controller must not run');
	}
}
