<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Core\Middleware;

use App\Core\App;
use App\Core\Routing\RouteName;
use App\Models\User;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

use Psr\Log\LoggerInterface;

use Throwable;

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
		if ($request->hasSession() && $request->getSession()->has('username') && !empty($request->getSession()->get('username'))) {
			$session = $request->getSession();
			if (!$this->sessionStillValid($session)) {
				$this->logger->info("Session of '" . $session->get('username') . "' ended: account changed");
				$session->invalidate();
				$session->getFlashBag()->add('error', 'Your account was changed, please log in again');
				return new RedirectResponse($this->urlGenerator->generate(RouteName::LOGIN->value));
			}

			// user is already authenticated
			return $next($request);
		}

		$requestPath = $request->getPathInfo();
		$loginUrl = $this->urlGenerator->generate(RouteName::LOGIN->value);
		$homeUrl = $this->urlGenerator->generate(RouteName::HOMEPAGE->value);

		// never return to logout after login
		if ($requestPath !== $loginUrl && $requestPath !== $homeUrl &&
			$request->attributes->get('_route') !== RouteName::LOGOUT->value) {
			$request->getSession()->set('login_redirect', $request->getRequestUri());
		}

		// redirect to login page
		return new RedirectResponse($loginUrl);
	}

	// false once users.session_version was bumped by an account change, or the user is gone
	private function sessionStillValid(SessionInterface $session): bool {
		if (!App::migrationStatus()->sessionVersionCompleted()) {
			return true;
		}

		try {
			$current = User::whereKey((int) $session->get('user_id', 0))->value('session_version');
		} catch (Throwable $e) {
			$this->logger->error("[AuthMiddleware] session check failed: " . $e->getMessage());
			return true;
		}

		if ($current === null) {
			return false;
		}

		// session from before this check existed: adopt the current version
		if (!$session->has('session_version')) {
			$session->set('session_version', (int) $current);
			return true;
		}

		return (int) $session->get('session_version') === (int) $current;
	}

}
