<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Controllers;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;

use App\Core\RouteName;
use App\Core\SessionManager;
use App\Utils\Helper;
use App\Forms\LoginForm;
use App\Models\User;

use Illuminate\Database\Capsule\Manager as DB;

use App\Core\Auth\AuthManager;
use Jumbojett\OpenIDConnectClientException;
//use App\Core\Auth\DbAuth;

class LoginController extends ViewController
{

	public function logout(): Response {
		$this->loginUrl = $this->url(RouteName::LOGIN);

		if ($username = $this->session->get('username')) {
			$this->fileLogger->info("User logout: '{$username}'");
		}

		if ($this->session->get('auth_provider') === 'OPENIDC'
			&& Helper::env_bool('OPENIDC_RP_INITIATED_LOGOUT')) {

			return $this->logout_openidc();
		}

		$this->clearSession();
		return new RedirectResponse($this->loginUrl);
	}

	public function logout_openidc(): Response {
		$idToken = $this->session->get('openidc_id_token');

		$this->clearSession();

		if (empty($idToken)) {
			$this->fileLogger->warning('OPENIDC ID Token empty');
			return new RedirectResponse($this->loginUrl);
		}

		$postLogoutRedirectUrl = $this->request->getSchemeAndHttpHost() . $this->url(RouteName::LOGIN);
		$auth = new AuthManager($this->fileLogger, $this->urlGenerator, $postLogoutRedirectUrl);

		try {
			if (!$auth->logoutOpenIdConnect($idToken)) {
				$this->fileLogger->warning('OPENIDC logout could not be started');
				return new RedirectResponse($this->loginUrl);
			}
		} catch (\Throwable $e) {
			$this->fileLogger->warning( 'OPENIDC logout failed: ' . $e->getMessage());
			return new RedirectResponse($this->loginUrl);
		}

		// unreachable
		return new RedirectResponse($this->loginUrl);
	}

	public function login(): Response {
		$this->initUrls();

		if (!empty($this->session->get('username'))) {
			$this->fileLogger->warning("'{$this->session->get('username')}' Already logged in");
			$this->flashbag->add('info', "Already logged in");
			return new RedirectResponse($this->homepageUrl);
		}

		// enable form rendering support
		$this->twigFormView($this->request);

		// generate and handle qid form
		$loginform = LoginForm::create($this->formFactory, $this->request);

		// session expired and user clicked logout.
		// don't show session expired warning
		if($this->session->get('login_redirect') === $this->url(RouteName::LOGOUT)) {
			$this->session->getFlashBag()->clear();
		}

		// Standard: Status 200
		$statusCode = Response::HTTP_OK;

		if ($loginform->isSubmitted() && $loginform->isValid()) {
			// get new session if it is expired
			SessionManager::checkSessionExpired();

			$data = $loginform->getData();
			$username = strtolower(trim($data['username']));
			$password = trim($loginform->get('password')->getData());

			if (empty($username) or empty($password)) {
				sleep((int)$_ENV['FAILED_LOGIN_TIMEOUT']);
				$this->flashbag->add('error', 'Login credentials missing');
				return new RedirectResponse($this->loginUrl);
			}

			$auth = new AuthManager($this->fileLogger);

			if (!$auth->authenticate($username, $password)) {
				$auth_provider = $auth->getAuthProvider();
				$client_ip = $_SERVER['REMOTE_ADDR'];
				$this->fileLogger->warning("($auth_provider) Login failed for user: '{$username}' via IP:{$client_ip}");
				sleep((int)$_ENV['FAILED_LOGIN_TIMEOUT']);
				$this->flashbag->add('error', "Wrong username or password");
				$statusCode = Response::HTTP_UNAUTHORIZED;
			} else {
				// user is authenticated

				// User does not exist is DB after auth
				if (!$this->finalizeAuthenticatedUser($auth)) {
					$username = $auth->getAuthenticatedUser();
					$this->fileLogger->error("Authenticated user '$username' not found in DB after auth");
					$this->flashbag->add('error', "Authentication problem. Contact admin");
					return new RedirectResponse($this->loginUrl);
				}

				if (!empty($login_redirect = $this->session->get('login_redirect'))) {
					$url = $this->getRedirectUrl($login_redirect);
				} else {
					$url = $this->homepageUrl;
				}

				return new RedirectResponse($url);
			}
		}

		return new Response($this->twig->render('login.twig', [
			'loginform' => $loginform->createView(),
			'openidc_enabled' => Helper::env_bool('OPENIDC_AUTH_ENABLED'),
			'runtime' => $this->getRuntime(),
			'flashes' => $this->flashbag->all(),
		]),
		$statusCode);
	}

	public function login_openidc(): Response {
		$this->initUrls();

		if (!Helper::env_bool('OPENIDC_AUTH_ENABLED')) {
			$this->flashbag->add('info', "OpenID Connect disabled");
			return new RedirectResponse($this->homepageUrl);
		}

		if (!empty($this->session->get('username'))) {
			$this->fileLogger->warning("'{$this->session->get('username')}' Already logged in");
			$this->flashbag->add('info', "Already logged in");
			return new RedirectResponse($this->homepageUrl);
		}

		// session expired and user clicked logout.
		// don't show session expired warning
		if($this->session->get('login_redirect') === $this->url(RouteName::LOGOUT)) {
			$this->session->getFlashBag()->clear();
		}

		// get new session if it is expired
		SessionManager::checkSessionExpired();

		$callbackUrl = $this->request->getSchemeAndHttpHost() . $this->url(RouteName::OPENIDC_CALLBACK);
		$auth = new AuthManager($this->fileLogger, $this->urlGenerator, $callbackUrl);

		try {
			$auth->startOpenIdConnectAuthentication();

			// We should never get here because authenticate() redirects.
			throw new \LogicException('OIDC authenticate() returned unexpectedly.');
		} catch (\Throwable $e) {
			$this->fileLogger->warning($e->getMessage());
			$this->flashbag->add('error', "Authentication failed");
			return new RedirectResponse($this->loginUrl);
		}

	}

	public function login_openidc_callback(): Response {
		$this->initUrls();

		if (!Helper::env_bool('OPENIDC_AUTH_ENABLED')) {
			$this->flashbag->add('warning', "OpenID Connect disabled");
			return new RedirectResponse($this->homepageUrl);
		}

		if (!empty($this->session->get('username'))) {
			$this->fileLogger->warning("'{$this->session->get('username')}' Already logged in");
			$this->flashbag->add('info', "Already logged in");
			return new RedirectResponse($this->homepageUrl);
		}

		// session expired and user clicked logout.
		// don't show session expired warning
		if($this->session->get('login_redirect') === $this->url(RouteName::LOGOUT)) {
			$this->session->getFlashBag()->clear();
		}

		// get new session if it is expired
		SessionManager::checkSessionExpired();

		try {
			$auth = new AuthManager($this->fileLogger, $this->urlGenerator);
			if (!$auth->finishOpenIdConnectAuthentication()) {
				$this->flashbag->add('error', "Authentication failed");
				return new RedirectResponse($this->loginUrl);
			}
		} catch (OpenIDConnectClientException $e) {
			$this->fileLogger->warning('OIDC callback failed: ' . $e->getMessage());
			$this->flashbag->add('error', "OpenID Connect authentication failed");
			return new RedirectResponse($this->loginUrl);
		} catch (\Throwable $e) {
			$this->fileLogger->error($e->getMessage());
			$this->flashbag->add('error', "An unexpected authentication error occurred");
			return new RedirectResponse($this->loginUrl);
		}

		// user is authenticated

		// User does not exist is DB after auth
		if (!$this->finalizeAuthenticatedUser($auth)) {
			$username = $auth->getAuthenticatedUser();
			$this->fileLogger->error("Authenticated user '$username' not found in DB after auth");
			$this->flashbag->add('error', "Authentication problem. Contact admin");
			return new RedirectResponse($this->loginUrl);
		}

		if (!empty($login_redirect = $this->session->get('login_redirect'))) {
			$url = $this->getRedirectUrl($login_redirect);
		} else {
			$url = $this->homepageUrl;
		}

		$this->session->set('openidc_id_token', $auth->getIdToken());
		return new RedirectResponse($url);
	}

	protected static function getMailAliases(User $user): array {
		/*
		$mail_aliases = [];
		foreach ($user->mailAliases as $mail_alias) {
			$mail_aliases[] = strtolower(trim($mail_alias->alias));
		}
		*/
		return array_unique(array_map('strtolower', array_filter(
		   $user->mailAliases()->pluck('alias')->toArray(),
		   fn($alias) => !empty(trim($alias))
		)));
	}

	// update last_login and user information
	// if user does not exists it is created
	private function finalizeAuthenticatedUser(AuthManager $auth): bool {
		$username = $auth->getAuthenticatedUser();
		$is_admin = $auth->getIsAdmin();
		$email = $auth->getUserEmail();
		$auth_provider = $auth->getAuthProvider();
		$auth_provider_id = $auth->getAuthProviderId();

		$user = User::where('username', $username)->first();

		// EXTERNAL AUTH (LDAP/OPENIDC)

		// EXTERNAL user does not exist in DB, create him
		if (($auth_provider !== "DB") && !$user) {
			$user = new User();
			$user->username = $username;
			$user->email = $email;
			$user->firstname = $auth->getUserFirstName();
			$user->lastname = $auth->getUserLastName();
			$user->is_admin = $is_admin;
			$user->auth_provider = $auth_provider_id;
			$user->password = "EXTERNAL_AUTH";
			$user->save();
		}

		// DB user missing
		if (!$user) {
			$this->fileLogger->warning("Authenticated user '$username' not found in DB.");
			$this->flashbag->add('error', "Authentication problem. Contact admin");
			return false;
		}

		// user is authenticated and exists in DB

		$user_id = $user->id;

		$update = [
			'last_login' => date("Y-m-d H:i:s")
		];

		$this->fileLogger->info("User login: '{$username}' ($auth_provider)", [
			'is_admin' => $is_admin,
			'email' => $email,
			'ip' => $_SERVER['REMOTE_ADDR'],
		]);

		if (($auth_provider === 'LDAP' || $auth_provider === 'OPENIDC')) {
			// update auth_provider in DB
			if ($auth_provider_id !== $user->auth_provider) {
				$update['auth_provider'] = $auth_provider_id;
			}
			// update email in DB
			if (!empty($email) && $email !== $user->email) {
				$update['email'] = $email;
			}
		}

		// update first/last name
		if (($auth_provider === 'LDAP' && Helper::env_bool('LDAP_UPDATE_NAME_ON_LOGIN')) ||
		    ($auth_provider === 'OPENIDC' && Helper::env_bool('OPENIDC_UPDATE_NAME_ON_LOGIN'))) {
				$update['firstname'] = $auth->getUserFirstName();
				$update['lastname'] = $auth->getUserlastName();
		}

		// update DB user info including last_login
		if (!$user->update($update)) {
			$this->fileLogger->error("Failed to update user '$username' in DB");
			return false;
		}

		$this->session->set('username', $username);
		$this->session->set('email', $email);
		$this->session->set('is_admin', $is_admin);
		$this->session->set('auth_provider', $auth_provider);
		$this->session->set('user_id', $user_id);

		// Load user aliases and set them in session
		$aliases = $this->getMailAliases($user);
		$this->session->set('user_aliases', $aliases);

		$this->setSessionVars($this->session);
		return true;
	}

	private function getRedirectUrl(string $login_redirect): string {
		$url = $this->homepageUrl;
		if ($login_redirect !== $this->url(RouteName::LOGIN) and
		    $login_redirect !== $this->url(RouteName::LOGOUT) and
		    $login_redirect !== $this->url(RouteName::ADMIN_HOMEPAGE) and
		    $login_redirect !== $this->url(RouteName::HOMEPAGE) and
			 $login_redirect !== $this->homepageUrl) {
				if (str_starts_with($login_redirect, '/') && !str_starts_with($login_redirect, '//')) {
					$url = $login_redirect;
				}
				$this->session->remove('login_redirect');
		}

		return $url;
	}

}
