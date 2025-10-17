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

class Authorization
{
	private UrlGeneratorInterface $urlGenerator;
	private LoggerInterface $logger;

	public function __construct(UrlGeneratorInterface $urlGenerator, LoggerInterface $logger) {
		$this->urlGenerator = $urlGenerator;
		$this->logger = $logger;
	}

	public function handle(Request $request, callable $next): Response {

		$loginUrl = $this->urlGenerator->generate('login');
		$homeUrl = $this->urlGenerator->generate('homepage');



		if (!$request->hasSession() || !$request->getSession()->has('username')) {
			$this->logger->error("In Authorization without Auth",
				$this->getLogContext($request));
			exit;
			return new RedirectResponse($homeUrl);
		}

		$username = $request->getSession()->get('username');
		$is_admin = $request->getSession()->get('is_admin');

		// admin has access
		if ($is_admin) {
			return $next($request);
		}

		// user does not have access
		$requestPath = $request->getPathInfo();
		$this->logger->warning("User '$username' tried to access '$requestPath' without admin authorization",
			$this->getLogContext($request));
		$request->getSession()->getFlashBag()->add('error', 'Permission denied');
		return new RedirectResponse($homeUrl);
	}

	private function getLogContext(Request $request): array {
		return array(
			'method' => $request->getMethod(),
			'uri' => $request->getRequestUri(),
			'ip' => $request->getClientIp(),
			'username' => $request->getSession()->get('username'),
			'is_admin' => $request->getSession()->get('is_admin'),
		);
	}

}
