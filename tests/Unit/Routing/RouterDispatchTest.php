<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Router::dispatch() on a request built from the globals. The URLs it
 makes use WEB_HOST, WEB_SCHEME and WEB_BASE, not the request's own host
 and base. A Controller gets the session, the request and the URL
 generator; any other callable gets neither, and a controller that is not
 a method is a 404. A route's middlewares run first to last; a route
 without any gets the default ones, logged; a declaration that is not a
 list of class names, or NO_MIDDLEWARE on a route that is not public, is a
 500, logged. A wrong method is a 405; a bad request (the typed request
 getters, an invalid Host) a 400, logged with the client. An unknown URL
 sends a visitor to login, a user home and an admin to the admin home,
 with a flash.
*/

namespace Tests\Unit\Routing;

use App\Controllers\Controller;
use App\Core\App;
use App\Core\AppContainer;
use App\Core\Database\MigrationStatus;
use App\Core\Middleware\AuthMiddleware;
use App\Core\Middleware\Authorization;
use App\Core\Routing\RouteName;
use App\Core\Routing\Router;
use App\Core\SessionManager;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Tests\Unit\UnitTestCase;

final class RouterDispatchTest extends UnitTestCase
{
	/** @var array<array-key, mixed> */
	private array $server = [];

	private Session $session;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		// sessionStillValid() asks the migration state: none completed, no query
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, null, new MigrationStatus(null, $this->log), null, false));
		$this->setEnv('WEB_HOST', 'rq.example.org');
		$this->setEnv('WEB_SCHEME', 'https');
		$this->setEnv('WEB_BASE', '/rqwatch');
		$this->server = $_SERVER;
		// behind a proxy: the request's own host, scheme and base differ from the .env ones
		$_SERVER['SCRIPT_NAME'] = '/index.php';
		$_SERVER['SCRIPT_FILENAME'] = '/var/www/index.php';
		$_SERVER['PHP_SELF'] = '/index.php';
		$_SERVER['HTTP_HOST'] = 'internal.example.net';
		$_SERVER['REMOTE_ADDR'] = '192.0.2.9';
		unset($_SERVER['HTTPS']);
		$this->session = new Session(new MockArraySessionStorage());
		self::sessionManager('session', $this->session);
		self::sessionManager('session_timeout', null);
	}

	#[\Override]
	protected function tearDown(): void {
		$_SERVER = $this->server;
		self::sessionManager('session', null);
		self::sessionManager('session_timeout', null);
		parent::tearDown();
	}

	private static function sessionManager(string $property, mixed $value): void {
		(new ReflectionProperty(SessionManager::class, $property))->setValue(null, $value);
	}

	/** @param array<string, mixed> $values */
	private function signIn(array $values): void {
		/** @var mixed $value */
		foreach ($values as $name => $value) {
			$this->session->set($name, $value);
		}
	}

	// a Controller: shows the id, the user its session gave it and an absolute URL from its generator
	private static function controller(): Controller {
		return new class extends Controller {
			public function page(string $id): Response {
				return new Response(implode('|', [
					$id,
					$this->username ?? '-',
					$this->urlGenerator->generate(RouteName::LOGIN->value, [], UrlGeneratorInterface::ABSOLUTE_URL),
				]));
			}
		};
	}

	/**
	 * not a Controller: shows whether the request has a session, or runs $run
	 * @param (Closure(): never)|null $run
	 */
	private static function plain(?Closure $run = null): object {
		return new class ($run) {
			/** @param (Closure(): never)|null $run */
			public function __construct(private ?Closure $run) {}

			public function page(Request $request): Response {
				if ($this->run !== null) {
					($this->run)();
				}
				return new Response($request->hasSession() ? 'session' : 'no session');
			}
		};
	}

	/**
	 * login (public, without middlewares), home and admin home, plus $routes
	 * @param array<string, Route> $routes
	 */
	private static function routes(array $routes, ?object $login = null): RouteCollection {
		$collection = new RouteCollection();
		$collection->add(RouteName::LOGIN->value, new Route('/login',
			['_controller' => [$login ?? self::controller(), 'page'], 'id' => 'login', '_middleware' => ['NO_MIDDLEWARE']], [], [], '', [], ['GET']));
		$collection->add(RouteName::HOMEPAGE->value, new Route('/'));
		$collection->add(RouteName::ADMIN_HOMEPAGE->value, new Route('/admin'));
		foreach ($routes as $name => $route) {
			$collection->add($name, $route);
		}
		return $collection;
	}

	private static function route(string $path, mixed $middleware): Route {
		return new Route($path, ['_controller' => [self::controller(), 'page'], 'id' => 'none', '_middleware' => $middleware]);
	}

	/** @param list<class-string> $default */
	private function dispatch(string $method, string $uri, RouteCollection $routes, array $default = [AuthMiddleware::class]): Response {
		$_SERVER['REQUEST_METHOD'] = $method;
		$_SERVER['REQUEST_URI'] = $uri;
		return (new Router())->dispatch($routes, $default);
	}

	private function assertRedirect(string $target, Response $response): void {
		$this->assertInstanceOf(RedirectResponse::class, $response);
		$this->assertSame($target, $response->getTargetUrl());
	}

	public function testPublicRoute(): void {
		$this->signIn(['username' => 'alice']);
		$response = $this->dispatch('GET', '/login', self::routes([]));
		$this->assertSame([200, 'login|alice|https://rq.example.org/rqwatch/login'], [$response->getStatusCode(), $response->getContent()]);
		$this->assertSame([], $this->log->lines);
	}

	public function testRouteParameters(): void {
		$routes = self::routes(['mail' => self::route('/mail/{id}', [AuthMiddleware::class])]);
		$this->signIn(['username' => 'alice']);
		$this->assertSame('42|alice|https://rq.example.org/rqwatch/login', $this->dispatch('GET', '/mail/42', $routes)->getContent());
	}

	public function testNotAControllerGetsNoSession(): void {
		$this->assertSame('no session', $this->dispatch('GET', '/login', self::routes([], self::plain()))->getContent());
	}

	public function testNotAMethod(): void {
		$routes = self::routes(['fn' => new Route('/fn', ['_controller' => static fn (): Response => new Response('ran'), '_middleware' => ['NO_MIDDLEWARE']])]);
		$response = $this->dispatch('GET', '/fn', $routes);
		$this->assertSame([404, 'What?'], [$response->getStatusCode(), $response->getContent()]);
	}

	// AuthMiddleware first: a visitor goes to login, never through Authorization
	public function testVisitorStopsAtTheFirstMiddleware(): void {
		$routes = self::routes(['mail' => self::route('/mail/{id}', [AuthMiddleware::class, Authorization::class])]);
		$this->assertRedirect('/rqwatch/login', $this->dispatch('GET', '/mail/42', $routes));
		$this->assertSame('/mail/42', $this->session->get('login_redirect'));
		$this->assertSame([], $this->log->lines);
	}

	public function testUserStopsAtTheSecond(): void {
		$routes = self::routes(['mail' => self::route('/mail/{id}', [AuthMiddleware::class, Authorization::class])]);
		$this->signIn(['username' => 'alice', 'is_admin' => false]);
		$this->assertRedirect('/rqwatch/', $this->dispatch('GET', '/mail/42', $routes));
		$this->assertSame(["warning User 'alice' tried to access '/mail/42' without admin authorization"], $this->log->lines);
	}

	public function testAdminPassesBoth(): void {
		$routes = self::routes(['mail' => self::route('/mail/{id}', [AuthMiddleware::class, Authorization::class])]);
		$this->signIn(['username' => 'root', 'is_admin' => true]);
		$this->assertSame('42|root|https://rq.example.org/rqwatch/login', $this->dispatch('GET', '/mail/42', $routes)->getContent());
		$this->assertSame([], $this->log->lines);
	}

	/** @return array<string, array{mixed}> */
	public static function undeclared(): array {
		return ['none' => [null], 'empty list' => [[]]];
	}

	#[DataProvider('undeclared')]
	public function testDefaultMiddlewares(mixed $middleware): void {
		$routes = self::routes(['open' => self::route('/open', $middleware)]);
		$this->assertRedirect('/rqwatch/', $this->dispatch('GET', '/open', $routes, [Authorization::class]));
		$this->assertSame([
			'warning open does not have a _middleware. Using defaultMiddlewareClasses',
			'error In Authorization without Auth',
		], $this->log->lines);
	}

	public function testNotAClassList(): void {
		$routes = self::routes(['bad' => self::route('/bad', AuthMiddleware::class)]);
		$response = $this->dispatch('GET', '/bad', $routes);
		$this->assertSame([500, 'Internal Server Error'], [$response->getStatusCode(), $response->getContent()]);
		$this->assertSame(["critical Route 'bad' declares a _middleware that is not a list of class names; refusing to dispatch"], $this->log->lines);
	}

	public function testUnknownMiddleware(): void {
		$routes = self::routes(['odd' => self::route('/odd', [AuthMiddleware::class, self::class])]);
		$this->signIn(['username' => 'alice']);
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Unknown middleware class in route');
		$this->dispatch('GET', '/odd', $routes);
	}

	public function testNoMiddlewareOnlyForPublicRoutes(): void {
		$routes = self::routes(['private' => self::route('/private', ['NO_MIDDLEWARE'])]);
		$response = $this->dispatch('GET', '/private', $routes);
		$this->assertSame([500, 'Internal Server Error'], [$response->getStatusCode(), $response->getContent()]);
		$this->assertSame(["critical Route 'private' declares NO_MIDDLEWARE but is not in AppConfig::PUBLIC_ROUTES; refusing to dispatch"], $this->log->lines);
	}

	// the method of the request, not GET: a POST matches no GET-only route
	public function testWrongMethod(): void {
		$response = $this->dispatch('POST', '/login', self::routes([]));
		$this->assertSame([405, 'Route Not Allowed'], [$response->getStatusCode(), $response->getContent()]);
	}

	/** @return array<string, array{Closure(): never, ?string, string}> what the controller throws, client address, line logged */
	public static function badRequests(): array {
		return [
			'a typed getter' => [static fn (): never => throw new BadRequestException('page is not an int'), '192.0.2.9',
				"warning Bad request for '/login' from 192.0.2.9: page is not an int"],
			'an invalid Host' => [static fn (): never => throw new SuspiciousOperationException('Invalid Host'), '192.0.2.9',
				"warning Bad request for '/login' from 192.0.2.9: Invalid Host"],
			'no client address' => [static fn (): never => throw new BadRequestException('bad'), null,
				"warning Bad request for '/login' from UNKNOWN: bad"],
		];
	}

	/** @param Closure(): never $throw */
	#[DataProvider('badRequests')]
	public function testBadRequest(Closure $throw, ?string $client, string $logged): void {
		if ($client === null) {
			unset($_SERVER['REMOTE_ADDR']);
		}
		$response = $this->dispatch('GET', '/login', self::routes([], self::plain($throw)));
		$this->assertSame([400, 'Bad Request'], [$response->getStatusCode(), $response->getContent()]);
		$this->assertSame([$logged], $this->log->lines);
	}

	public function testUnknownUrlVisitor(): void {
		$this->assertRedirect('/rqwatch/login', $this->dispatch('GET', '/nowhere', self::routes([])));
		$this->assertSame([], $this->session->getFlashBag()->peekAll());
	}

	/** @return array<string, array{array<string, mixed>, string}> session, redirect */
	public static function unknownUrlUsers(): array {
		return [
			'a user' => [['username' => 'alice'], '/rqwatch/'],
			'not an admin' => [['username' => 'alice', 'is_admin' => false], '/rqwatch/'],
			'an admin' => [['username' => 'root', 'is_admin' => true], '/rqwatch/admin'],
		];
	}

	/** @param array<string, mixed> $session */
	#[DataProvider('unknownUrlUsers')]
	public function testUnknownUrlUser(array $session, string $target): void {
		$this->signIn($session);
		$this->assertRedirect($target, $this->dispatch('GET', '/nowhere', self::routes([])));
		$this->assertSame(['error' => ['Unknown URL']], $this->session->getFlashBag()->peekAll());
	}
}
