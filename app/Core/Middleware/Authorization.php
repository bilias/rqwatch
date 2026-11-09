<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Core\Middleware;

use App\Core\Routing\RouteName;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;

use Psr\Log\LoggerInterface;

final class Authorization
{
	private UrlGeneratorInterface $urlGenerator;
	private LoggerInterface $logger;

	public function __construct(UrlGeneratorInterface $urlGenerator, LoggerInterface $logger) {
		$this->urlGenerator = $urlGenerator;
		$this->logger = $logger;
	}

	/** @param callable(Request): Response $next */
	public function handle(Request $request, callable $next): Response {
		$homeUrl = $this->urlGenerator->generate(RouteName::HOMEPAGE->value);

		if (!$request->hasSession() || !$request->getSession()->has('username')) {
			$this->logger->error("In Authorization without Auth",
				$this->getLogContext($request));
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
		$session = $request->getSession();
		if ($session instanceof FlashBagAwareSessionInterface) {
			$session->getFlashBag()->add('error', 'Permission denied');
		}
		return new RedirectResponse($homeUrl);
	}

	/** @return array<string, mixed> */
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
