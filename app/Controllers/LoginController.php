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
//use App\Core\Auth\DbAuth;

class LoginController extends ViewController
{

	public function logout(): Response {
		if ($username = $this->session->get('username')) {
			$this->fileLogger->info("User logout: '{$username}'");
		}
		$this->clearSession();
		$this->loginUrl = $this->url(RouteName::LOGIN);
		return new RedirectResponse($this->loginUrl);
	}

	public function login(): Response {
		if (!empty($this->session->get('username'))) {
			$this->fileLogger->warning("'{$this->session->get('username')}' Already logged in");
			$this->flashbag->add('info', "Already logged in");
			$this->initUrls();
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
				$this->initUrls();
				return new RedirectResponse($this->loginUrl);
			}

			$auth = new AuthManager($this->fileLogger);

			$db_user_not_found = false;
			if ($auth->authenticate($username, $password)) {
				$username = $auth->getAuthenticatedUser();
				$is_admin = $auth->getIsAdmin();
				$email = $auth->getUserEmail();
				$auth_provider = $auth->getAuthProvider();
				$user_id = $auth->getUserId();

				// DB
				if ($auth_provider === "DB") {
					// save last login information
					User::where('username', $username)
							->update([
								'last_login' => date("Y-m-d H:i:s"),
								'updated_at' => DB::raw('updated_at'),
							]);
				// LDAP
				} else {
					$user = User::where('username', $username)->first();
					// LDAP user does not exist, create him
					if (!$user) {
						$user = new User();
						$user->username = $username;
						$user->email = $email;
						$user->firstname = $auth->getUserFirstName();
						$user->lastname = $auth->getUserLastName();
						$user->is_admin = $is_admin;
						$user->auth_provider = $auth->getAuthProviderId();
						$user->password = "EXTERNAL_AUTH";
						$user->last_login = date("Y-m-d H:i:s");
						$user->save();
					// LDAP user already exists
					} else {
						$user->update([ 'last_login' => date("Y-m-d H:i:s") ]);
					}
					$user_id = $user->id;
				}
				$this->session->set('username', $username);
				$this->session->set('email', $email);
				$this->session->set('is_admin', $is_admin);
				$this->session->set('auth_provider', $auth_provider);
				$this->session->set('user_id', $user_id);
				$this->fileLogger->info("User login: '{$username}' ($auth_provider)", [
					'is_admin' => $is_admin,
					'email' => $email,
					'ip' => $_SERVER['REMOTE_ADDR'],
				]);

				$user = User::where('username', $username)->first();
				if (!$user) {
					$this->session->remove('username');
					$this->session->remove('email');
					$this->session->remove('is_admin');
					$this->session->remove('auth_provider');
					$db_user_not_found = true;
				// user authenticated and exists in DB
				} else {
					// Update First and Last Name
					if(Helper::env_bool('LDAP_UPDATE_NAME_ON_LOGIN')) {
						User::where('username', $username)
								->update([
									'firstname' => $auth->getUserFirstName(),
									'lastname' => $auth->getUserlastName(),
								]);
					}
					// Load user aliases and set them in session
					$aliases = $this->getMailAliases($user);

					$this->session->set('user_aliases', $aliases);

					$this->setSessionVars($this->session);

					// redirect to initial requested page if exists
					$this->initUrls();

					if (!empty($login_redirect = $this->session->get('login_redirect'))) {
						if ($login_redirect !== $this->url(RouteName::LOGIN) and
						    $login_redirect !== $this->url(RouteName::LOGOUT) and
						    $login_redirect !== $this->url(RouteName::ADMIN_HOMEPAGE) and
						    $login_redirect !== $this->url(RouteName::HOMEPAGE) and
							 $login_redirect !== $this->homepageUrl) {
								$url = $login_redirect;
								$this->session->remove('login_redirect');
						} else {
							$url = $this->homepageUrl;
						}
					} else {
						$url = $this->homepageUrl;
					}

					return new RedirectResponse($url);
				}
			}
			// db user not found after auth
			if ($db_user_not_found) {
				$this->fileLogger->warning("Authenticated user '$username' not found in DB.");
				$this->flashbag->add('error', "Authentication problem. Contact admin");
				$statusCode = Response::HTTP_INTERNAL_SERVER_ERROR;
			// authenticate() failed
			} else {
				$auth_provider = $auth->getAuthProvider();
				$client_ip = $_SERVER['REMOTE_ADDR'];
				$this->fileLogger->warning("($auth_provider) Login failed for user: '{$username}' via IP:{$client_ip}");
				sleep((int)$_ENV['FAILED_LOGIN_TIMEOUT']);
				$this->flashbag->add('error', "Wrong username or password");
				$statusCode = Response::HTTP_UNAUTHORIZED;
			}
		}

		return new Response($this->twig->render('login.twig', [
			'loginform' => $loginform->createView(),
			'runtime' => $this->getRuntime(),
			'flashes' => $this->flashbag->all(),
		]),
		$statusCode);
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

}
