<?php
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Core\Middleware;

use App\Core\RouteName;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

use Psr\Log\LoggerInterface;

class AuthMiddleware
{
	private UrlGeneratorInterface $urlGenerator;
	private LoggerInterface $logger;

	public function __construct(UrlGeneratorInterface $urlGenerator, LoggerInterface $logger) {
		$this->urlGenerator = $urlGenerator;
		$this->logger = $logger;
	}

	public function handle(Request $request, callable $next): Response {

		// already authed
		if ($request->hasSession() && $request->getSession()->has('username')) {
			if (!empty($request->getSession()->get('username'))) {
				// user is already authenticated
				return $next($request);
			}
		}

		$requestPath = $request->getPathInfo();
		$loginUrl = $this->urlGenerator->generate(RouteName::LOGIN->value);
		$homeUrl = $this->urlGenerator->generate(RouteName::HOMEPAGE->value);

		if ($requestPath !== $loginUrl && $requestPath !== $homeUrl) {
			$request->getSession()->set('login_redirect', $request->getRequestUri());
		}

		// redirect to login page
		return new RedirectResponse($loginUrl);
	}

}
