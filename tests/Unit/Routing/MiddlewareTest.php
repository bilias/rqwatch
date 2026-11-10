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
 anonymous requests, the admin check on an existing session, and a
 session value of another type than rqwatch writes, which is refused.
*/

namespace Tests\Unit\Routing;

use App\Core\Middleware\AuthMiddleware;
use App\Core\Middleware\Authorization;
use App\Core\Routing\Routes;
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

	public function testEmptyUsernameIsAnonymous(): void {
		$request = $this->request('/mail/42', ['username' => '']);
		$response = (new AuthMiddleware($this->urls, new NullLogger()))->handle($request, $this->next());
		$this->assertRedirectsTo('login', $response);
	}

	public function testAuthorizationWithoutLoginGoesHome(): void {
		$request = $this->request('/admin/users');
		$response = (new Authorization($this->urls, new NullLogger()))->handle($request, $this->next());
		$this->assertRedirectsTo('homepage', $response);
	}

	public function testNonAdminIsDenied(): void {
		$request = $this->request('/admin/users', ['username' => 'alice', 'is_admin' => false]);
		$response = (new Authorization($this->urls, new NullLogger()))->handle($request, $this->next());
		$this->assertRedirectsTo('homepage', $response);
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
