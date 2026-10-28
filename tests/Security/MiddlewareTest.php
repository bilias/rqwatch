<?php declare(strict_types=1);
/*
 The two access middlewares, for the cases that need no database:
 anonymous requests and the admin check on an existing session.
*/

namespace Tests\Security;

use App\Core\Middleware\AuthMiddleware;
use App\Core\Middleware\Authorization;
use App\Core\Routing\Routes;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
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

	protected function setUp(): void {
		$this->urls = new UrlGenerator(Routes::load()['routes'], new RequestContext());
		$this->reached = false;
	}

	private function request(string $path, array $session = []): Request {
		$request = Request::create($path);
		$s = new Session(new MockArraySessionStorage());
		foreach ($session as $k => $v) {
			$s->set($k, $v);
		}
		$request->setSession($s);
		return $request;
	}

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
		$this->assertSame(['Permission denied'], $request->getSession()->getFlashBag()->get('error'));
	}

	public function testAdminPasses(): void {
		$request = $this->request('/admin/users', ['username' => 'root', 'is_admin' => true]);
		$response = (new Authorization($this->urls, new NullLogger()))->handle($request, $this->next());
		$this->assertTrue($this->reached);
		$this->assertSame('controller', $response->getContent());
	}
}
