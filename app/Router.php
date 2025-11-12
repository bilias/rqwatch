<?php 
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/
namespace App;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel;

use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Exception\NoConfigurationException;

use App\Core\RouteName;

use App\Core\SessionManager;
use App\Core\Middleware\AuthMiddleware;
use App\Core\Middleware\Authorization;

use Psr\Log\LoggerInterface;

use App\Core\Exception\SessionExpired;

// https://symfony.com/doc/current/create_framework/http_kernel_controller_resolver.html

class Router
{
	public function __invoke(
			RouteCollection $routes,
			array $middlewareMap,
			array $defaultMiddlewareClasses,
			LoggerInterface $fileLogger,
			LoggerInterface $syslogLogger
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

		try {
			$request->attributes->add($matcher->match($request->getPathInfo()));

			$controllerResolver = new HttpKernel\Controller\ControllerResolver();
			$controller = $controllerResolver->getController($request);

			$argumentResolver = new HttpKernel\Controller\ArgumentResolver();
			$arguments = $argumentResolver->getArguments($request, $controller);

			// Inject request and session into controllers:
			if (is_array($controller) && is_object($controller[0])) {
				if ($controller[0] instanceof \App\Controllers\Controller) {
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

			// middleware in route (moved to $middlewareMap)
			// $middlewareClasses = $request->attributes->get('_middleware', []);

			$request_route = $request->attributes->get('_route');
			if (array_key_exists($request_route, $middlewareMap)) {
				$middlewareClasses = $middlewareMap[$request->attributes->get('_route')];
			}

			// play safe incase route is missing from $middlewareMap
			if (empty($middlewareClasses)) {
				/*
				throw new \RuntimeException("Middleware missing for route '" .
					$request->attributes->get('_route') .
					"'. Check middlewareMap in config/routes.php");
				*/
				$fileLogger->warning("$request_route does not exist in middlewareMap. Using defaultMiddlewareClasses");
				$middlewareClasses = $defaultMiddlewareClasses;
			}

			// Final callable that runs the controller
			$controllerHandler = function (Request $request)
				use ($controller, $arguments, $fileLogger, $syslogLogger) 
			{
				if (is_array($controller) && $controller[0] instanceof \App\Controllers\Controller) {
					$controller[0]->setLoggers($fileLogger, $syslogLogger);
				}
				return call_user_func_array($controller, $arguments);
			};

			// Wrap controller with middleware layers (from last to first)
			$middlewareChain = $controllerHandler;

			foreach (array_reverse($middlewareClasses) as $middlewareClass) {
				$next = $middlewareChain;
				$middlewareChain = function (Request $request) use (
					$middlewareClass,
					$next,
					$urlGenerator,
					$fileLogger
				) {
					//$middleware = new $middlewareClass($urlGenerator);
					// add logger
					$middleware = $this->resolveMiddleware($middlewareClass, $urlGenerator, $fileLogger);
					return $middleware->handle($request, $next);
				};
			}

			if ($middlewareClasses[0] === 'NO_MIDDLEWARE') {
				// response without Middleware
				if (is_array($controller) && $controller[0] instanceof \App\Controllers\Controller) {
					$controller[0]->setLoggers($fileLogger, $syslogLogger);
					$response = call_user_func_array($controller, $arguments);
				}
			} else {
				// Run the full middleware + controller chain
				$response = $middlewareChain($request);
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

	private function resolveMiddleware(string $middlewareClass, UrlGenerator $urlGenerator, LoggerInterface $fileLogger): object {
		return match ($middlewareClass) {
			AuthMiddleware::class =>
				new $middlewareClass($urlGenerator, $fileLogger),
			Authorization::class =>
				new $middlewareClass($urlGenerator, $fileLogger),
			default =>
				new $middlewareClass($urlGenerator), // fallback for simple middleware
		};
	}
}

// Invoke
$router = new Router();
$routes = include __DIR__.'/../config/routes.php';

// fileLogger and syslogLogger come from bootstrap
$response = $router($routes, $middlewareMap, $defaultMiddlewareClasses, $fileLogger, $syslogLogger);
$response->send();
