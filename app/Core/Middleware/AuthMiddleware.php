<?php
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU Affero General Public License version 3
as published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU Affero General Public License for more details.

You should have received a copy of the GNU Affero General Public License
along with this program.  If not, see <https://www.gnu.org/licenses/>.
*/

namespace App\Core\Middleware;

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
		$loginUrl = $this->urlGenerator->generate('login');
		$homeUrl = $this->urlGenerator->generate('homepage');

		if ($requestPath !== $loginUrl && $requestPath !== $homeUrl) {
			$request->getSession()->set('login_redirect', $request->getRequestUri());
		}

		// redirect to login page
		return new RedirectResponse($loginUrl);
	}

}
