<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/
namespace App\Core\Routing;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;

use Symfony\Component\HttpKernel\Controller\ControllerResolver;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver;

use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Exception\NoConfigurationException;

use App\Configuration\AppConfig;

use App\Core\App;

use App\Utils\Helper;

use App\Core\SessionManager;
use App\Core\Middleware\AuthMiddleware;
use App\Core\Middleware\Authorization;

use App\Core\Database\MigrationStatus;

use App\Controllers\Controller;

use Psr\Log\LoggerInterface;

// use RuntimeException

// https://symfony.com/doc/current/create_framework/http_kernel_controller_resolver.html

class Router
{

	public function dispatch(
			RouteCollection $routes,
			// array $middlewareMap,
			array $defaultMiddlewareClasses,
	) {

		// Request initialization
		$request = Request::createFromGlobals();

		$context = new RequestContext();
		$context->fromRequest($request);
		$matcher = new UrlMatcher($routes, $context);

		// support subfolder
		$context->setHost($_ENV['WEB_HOST']);
		$context->setScheme($_ENV['WEB_SCHEME']);
		$context->setBaseUrl($_ENV['WEB_BASE']);

		$urlGenerator = new UrlGenerator($routes, $context);
		$loginUrl = $urlGenerator->generate(RouteName::LOGIN->value);

		$fileLogger = App::fileLogger();

		try {
			$request->attributes->add($matcher->match($request->getPathInfo()));

			$controllerResolver = new ControllerResolver();
			$controller = $controllerResolver->getController($request);

			$argumentResolver = new ArgumentResolver();
			$arguments = $argumentResolver->getArguments($request, $controller);

			// Inject request and session into controllers:
			if (is_array($controller) && is_object($controller[0])) {
				if ($controller[0] instanceof Controller) {
					// Session initialization
					// $session = SessionManager::getSession();
					SessionManager::setLogger($fileLogger);
					$request->setSession(SessionManager::getSession());

					// $request->attributes->set('request_id', spl_object_id($request));

					// inject request and session
					$controller[0]->setRequest($request);
					// inject urlGenerator
					$controller[0]->setUrlGenerator($urlGenerator);
				}
			} else {
				return new Response("What?", 404);
			}

			$middlewareClasses = $request->attributes->get('_middleware', []);

			$request_route = $request->attributes->get('_route');

			// moved middleware inside routes->add
			/*
			if (array_key_exists($request_route, $middlewareMap)) {
				$middlewareClasses = $middlewareMap[$request->attributes->get('_route')];
			}
			*/

			// play safe incase route is missing from $middlewareMap
			if (empty($middlewareClasses)) {
				$fileLogger->warning("$request_route does not have a _middleware. Using defaultMiddlewareClasses");
				$middlewareClasses = $defaultMiddlewareClasses;
			}

			// Final callable that runs the controller
			$controllerHandler = function (Request $request)
				use ($controller, $arguments)
			{
				return call_user_func_array($controller, $arguments);
				//return $controller(...$arguments);
			};

			$response = null;

			if ($middlewareClasses[0] !== 'NO_MIDDLEWARE') {
				// Wrap controller with middleware layers (from last to first)
				$middlewareChain = $controllerHandler;

				foreach (array_reverse($middlewareClasses) as $middlewareClass) {
					$next = $middlewareChain;
					$middlewareChain = function (Request $request) use (
						$middlewareClass,
						$next,
						$urlGenerator
					) {
						//$middleware = new $middlewareClass($urlGenerator);
						// add logger in middleware
						$middleware = $this->resolveMiddleware($middlewareClass, $urlGenerator);
						return $middleware->handle($request, $next);
					};
				}
				// Run the full middleware + controller chain
				$response = $middlewareChain($request);
			} else  {
				// NO_MIDDLEWARE: response without Middleware, and invoke controller
				$response = call_user_func_array($controller, $arguments);
				//$response = $controller(...$arguments);
			}

			if (!$response instanceof Response) {
				// Defensive fallback if controller didn't return a Response
				$response = new Response('Internal Server Error: Controller did not return a Response object', 500);
			}

		} catch (MethodNotAllowedException $e) {
			$response = new Response('Route Not Allowed', 405);
		} catch (ResourceNotFoundException $e) {
			// invalid route
			// Session initialization
			SessionManager::setLogger($fileLogger);
			$session = SessionManager::getSession();
			$request->setSession($session);
			if ($request->hasSession() && $session->has('username')) {
				// user is already authenticated
				// redirect to homepage
				$session->getFlashBag()->add('error', "Unknown URL");
				if ($session->get('is_admin')) {
					return new RedirectResponse($urlGenerator->generate(RouteName::ADMIN_HOMEPAGE->value));
				} else {
					return new RedirectResponse($urlGenerator->generate(RouteName::HOMEPAGE->value));
				}
			} else {
				// user is NOT authenticated
				// redirect to login page
				sleep((int)$_ENV['FAILED_LOGIN_TIMEOUT']);
				return new RedirectResponse($loginUrl);
				//$response = new Response('Route Not Found', 404);
			}
		} catch (NoConfigurationException $e) {
			$response = new Response('An error occurred', 500);
		}

		return $response;
	}

	private function resolveMiddleware(string $middlewareClass, UrlGenerator $urlGenerator): object {
		return match ($middlewareClass) {
			AuthMiddleware::class,
			Authorization::class =>
				new $middlewareClass($urlGenerator, App::fileLogger()),
			default =>
				new $middlewareClass($urlGenerator), // fallback for simple middleware
		};
	}

	public static function run(): void {

		$fileLogger = App::fileLogger();
		$syslogLogger = App::syslogLogger();
		$migrationStatus = App::migrationStatus();

		// we do not need Router in our API or CLI
		if (!defined('WEB_MODE') || defined('API_MODE') || defined('CLI_MODE')) {
			$fileLogger->error("Router requested with wrong mode");
			exit();
		}

		if (!Helper::env_bool('WEB_ENABLE')) {
			$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
			$fileLogger->warning("Client '{$ip}' requested '" . $_SERVER['REQUEST_URI'] . "' but Web is disabled.");
			exit("Web is disabled");
		}

		// Load routes and default middleware classes
		try {
			$routeConfig = Routes::load();
			$routes = $routeConfig['routes'];
			$defaultMiddlewareClasses = $routeConfig['defaultMiddlewareClasses'];
		} catch (Throwable $e) {
			$fileLogger->error("Routes loading failed: " . $e->getMessage());
			exit("Routes misconfigured.");
		}

		if (!isset($routes) || !isset($defaultMiddlewareClasses)) {
			$fileLogger->error("Routes loading failed: " . $e->getMessage());
			exit("Routes misconfigured.");
		}

		// Instantiate Router and handle the request
		$router = new self();
		$response = $router->dispatch($routes, $defaultMiddlewareClasses);
		$response->send();
		exit();
	}
}
