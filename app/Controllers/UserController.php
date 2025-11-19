<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Controllers;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;

use App\Core\RouteName;
use App\Core\Config;
use App\Core\SessionManager;
use App\Core\Auth\AuthManager;
use App\Utils\Helper;

use App\Forms\QidForm;
use App\Forms\UserForm;
use App\Forms\UserDeleteForm;
use App\Forms\UserSearchForm;
use App\Forms\ProfileForm;

use App\Models\User;
use App\Services\UserService;

//use Illuminate\Database\Capsule\Manager as DB;

class UserController extends ViewController
{
	protected $refresh_rate;
	protected $items_per_page;
	protected $max_items;

	private ?string $adminUsersUrl = null;

	public function __construct() {
	//	parent::__construct();

		$this->items_per_page = Config::get('items_per_page');
		$this->max_items = Config::get('max_items');
	}

	private function getAdminUsersUrl(): string {
		if ($this->adminUsersUrl === null) {
			$this->adminUsersUrl = $this->url(RouteName::ADMIN_USERS);
		}

		return $this->adminUsersUrl;
	}

	public function searchUser(): Response {
		// enable form rendering support
		$this->twigFormView($this->request);

		// generate and handle qid form
		$qidform = QidForm::create($this->formFactory, $this->request);
		if ($response = QidForm::check_form($qidform, $this->urlGenerator, $this->is_admin)) {
			// form submitted and valid
			return $response;
		}

		$userSearchForm = UserSearchForm::create($this->formFactory, $this->request, $this->urlGenerator);

		if ($userSearchForm->isSubmitted() && !$userSearchForm->isValid()) {
			$this->flashbag->add('error', 'The value can only contain letters, numbers and ._+-@');
			return new RedirectResponse($this->getAdminUsersUrl());
		}

		// Get page from ?page=, default 1
		$page = $this->request->query->getInt('page', 1);

		$user_search_form = $this->request->get('user_search_form');
		if (!empty($user_search_form['user'])) {
			$search = $user_search_form['user'];

			$service = new UserService($this->getFileLogger());
			$url = $this->getAdminUsersUrl();
			$users = $service->searchPaginatedAll($page, $url, $search);
		}

		//return new Response($this->twig->render('home.twig', [
		return new Response($this->twig->render('users_paginated.twig', [
			'qidform' => $qidform->createView(),
			'usersearchform' => $userSearchForm->createView(),
			'users' => $users,
			'totalRecords' => $users->total(),
			'items_per_page' => $this->items_per_page,
			'runtime' => $this->getRuntime(),
			'refresh_rate' => $this->refresh_rate,
			'flashes' => $this->flashbag->all(),
			'is_admin' => $this->session->get('is_admin'),
			'username' => $this->session->get('username'),
			'auth_provider' => $this->session->get('auth_provider'),
			'current_route' => $this->request->getPathInfo(),
			'rspamd_stats' => $this->getRspamdStat(),
		]));
	}

	public function showAll(): Response {
		// enable form rendering support
		$this->twigFormView($this->request);

		// generate and handle qid form
		$qidform = QidForm::create($this->formFactory, $this->request);
		if ($response = QidForm::check_form($qidform, $this->urlGenerator, $this->is_admin)) {
			// form submitted and valid
			return $response;
		}

		// Get page from ?page=, default 1
		$page = $this->request->query->getInt('page', 1);

		$service = new UserService($this->getFileLogger());
		$url = $this->getAdminUsersUrl();
		$users = $service->showPaginatedAll($page, $url);

		$userSearchForm = UserSearchForm::create($this->formFactory, $this->request, $this->urlGenerator);

		//return new Response($this->twig->render('home.twig', [
		return new Response($this->twig->render('users_paginated.twig', [
			'qidform' => $qidform->createView(),
			'usersearchform' => $userSearchForm->createView(),
			'users' => $users,
			'totalRecords' => $users->total(),
			'items_per_page' => $this->items_per_page,
			'runtime' => $this->getRuntime(),
			'refresh_rate' => $this->refresh_rate,
			'flashes' => $this->flashbag->all(),
			'is_admin' => $this->session->get('is_admin'),
			'username' => $this->session->get('username'),
			'auth_provider' => $this->session->get('auth_provider'),
			'current_route' => $this->request->getPathInfo(),
			'rspamd_stats' => $this->getRspamdStat(),
		]));
	}

	public function showOne(int $id = null): Response {
		$this->twigFormView($this->request);

		// generate and handle qid form
		$qidform = QidForm::create($this->formFactory, $this->request);
		if ($response = QidForm::check_form($qidform, $this->urlGenerator, $this->is_admin)) {
			// form submitted and valid
			return $response;
		}

		$service = new UserService($this->getFileLogger());
		$user = $service->showOne($id);
		
		if (!$user) {
			$this->flashbag->add('error', "User not found");
		}

		$mail_aliases = $this->getMailAliases($user);
		$aliases_str = implode(', ', array_map('trim', $mail_aliases));

		return new Response($this->twig->render('user.twig', [
			'qidform' => $qidform->createView(),
			'user' => $user,
			'mail_aliases' => $aliases_str,
			'runtime' => $this->getRuntime(),
			'flashes' => $this->flashbag->all(),
			'is_admin' => $this->session->get('is_admin'),
			'username' => $this->session->get('username'),
			'auth_provider' => $this->session->get('auth_provider'),
			'current_route' => $this->request->getPathInfo(),
			'rspamd_stats' => $this->getRspamdStat(),
		]));
	}

	public function profile(): Response {
		$this->twigFormView($this->request);

		// generate and handle qid form
		$qidform = QidForm::create($this->formFactory, $this->request);
		if ($response = QidForm::check_form($qidform, $this->urlGenerator, $this->is_admin)) {
			// form submitted and valid
			return $response;
		}

		$profileform_t = null;

		$service = new UserService($this->getFileLogger(), $this->session);
		$user = $service->profile();

		if (!$user) {
			$this->flashbag->add('error', "User not found");
			$this->initUrls();
			return new RedirectResponse($this->homepageUrl);
		}

		$profileform = ProfileForm::create($this->formFactory, $this->request, $user->toArray());
		$profileform_t = $profileform->createView(); // for twig

		if ($profileform->isSubmitted() && $profileform->isValid()) {
			$data = $profileform->getData();
			// allow password change only for DB users
			$pass_changed = false;
			if ($user->auth_provider === 0) {
				$newPassword = $profileform->get('password')->getData();
				if (!empty($newPassword)) {
					$data['password'] = Helper::passwordHash($newPassword);
					$pass_changed = true;
				}
			}

			try {
				$user->fill($data);
				if ($pass_changed) {
					$user->password = $data['password'];
				}
				$user->save();
				if ($user) {
					$this->flashbag->add('success', "Profile updated");
				} else {
					$this->flashbag->add('error', "Profile update failed");
				}
				$this->initUrls();
				return new RedirectResponse($this->homepageUrl);
			} catch (\Exception $e) {
				$error = $e->getMessage();
				$this->flashbag->add('error', $error);
			}
		}

		return new Response($this->twig->render('user.twig', [
			'qidform' => $qidform->createView(),
			'profileform' => $profileform_t,
			'user' => $user,
			'runtime' => $this->getRuntime(),
			'flashes' => $this->flashbag->all(),
			'is_admin' => $this->session->get('is_admin'),
			'username' => $this->session->get('username'),
			'auth_provider' => $this->session->get('auth_provider'),
			'current_route' => $this->request->getPathInfo(),
			'rspamd_stats' => $this->getRspamdStat(),
		]));
	}

	public function add(): Response {
		// enable form rendering support
		$this->twigFormView($this->request);

		// generate and handle qid form
		$qidform = QidForm::create($this->formFactory, $this->request);
		if ($response = QidForm::check_form($qidform, $this->urlGenerator, $this->is_admin)) {
			// form submitted and valid
			return $response;
		}

		$error = null;
		$userform = UserForm::create($this->formFactory, $this->request);

		if ($userform->isSubmitted() && $userform->isValid()) {
			$data = $userform->getData();
			$service = new UserService($this->getFileLogger());

			if (empty($data['username'])) {
				$this->flashbag->add('error', "Username empty");
			} else if ($service->userExists($data['username'])) {
				$this->flashbag->add('error', "User '{$data['username']}' already exists");
			} else {
				$newPassword = trim($userform->get('password')->getData());
				if (empty($newPassword)) {
						$this->flashbag->add('error', 'Empty password not allowed.');
						$url = $this->getAdminUsersUrl();
						return new RedirectResponse($url);
				}
				$data['password'] = Helper::passwordHash($newPassword);

				if ($user = $service->userAdd($data)) {
					$this->flashbag->add('success', "User '{$data['username']}' created");
				} else {
					$this->flashbag->add('error', "User creation failed");
				}
				$url = $this->getAdminUsersUrl();
				return new RedirectResponse($url);
			}
		}

		return new Response($this->twig->render('user_add.twig', [
			'error' => $error,
			'qidform' => $qidform->createView(),
			'userform' => $userform->createView(),
			'runtime' => $this->getRuntime(),
			'flashes' => $this->flashbag->all(),
			'is_admin' => $this->session->get('is_admin'),
			'username' => $this->session->get('username'),
			'auth_provider' => $this->session->get('auth_provider'),
			'current_route' => $this->request->getPathInfo(),
			'rspamd_stats' => $this->getRspamdStat(),
		]));
	}

	public function edit(int $id): Response {
		// enable form rendering support
		$this->twigFormView($this->request);

		// generate and handle qid form
		$qidform = QidForm::create($this->formFactory, $this->request);
		if ($response = QidForm::check_form($qidform, $this->urlGenerator, $this->is_admin)) {
			// form submitted and valid
			return $response;
		}

		if (!empty($id)) {
			$user = User::find($id);
		}

		// DB User
		if ($user) {
			// or $user->toArray() if empty attribute and not getter in Model
			$userform = UserForm::create($this->formFactory, $this->request, $user->toArray(), ['is_edit' => true]);

			// Do not allow delete of admin user
			if ($user->username !== 'admin') {
				$userdelform = UserDeleteForm::create($this->formFactory, $this->request,
					['id' => $user->id,
					 'username' => $user->username,
					]);

				if ($response = UserDeleteForm::check_form($userdelform, $this->urlGenerator)) {
					// form submitted and valid
					return $response;
				}
				$userdelform = $userdelform->createView();
			} else {
				$userdelform = null;
			}
		} else {
			// user does not exist
			// get back to search page
			$this->flashbag->add('error', 'User not found.');
			$url = $this->getAdminUsersUrl();
			return new RedirectResponse($url);
		}

		$error = null;
		if ($userform->isSubmitted() && $userform->isValid()) {
			//$data = $userform->getData()->toArray();
			$data = $userform->getData();
			$service = new UserService($this->getFileLogger());
			// username change and new username exists
			if (empty($data['username'])) {
				$this->flashbag->add('error', "Username empty");
			} else if (($data['username'] !== $user->username) and
			           ($service->userExists($data['username']))) {
				$this->flashbag->add('error', "Username '{$data['username']}' already exists.");
			} else {
				$pass_changed = false;
				$newPassword = $userform->get('password')->getData();
				if (!empty($newPassword)) {
					$data['password'] = Helper::passwordHash($newPassword);
					$pass_changed = true;
				}

				try {
					//DB::connection()->enableQueryLog();
					$data['username'] = strtolower(trim($data['username']));
					$user->fill($data);
					if ($pass_changed) {
						$user->password = $data['password'];
					}
					$user->save();
					//dump(DB::connection()->getQueryLog());
					if ($user) {
						$this->flashbag->add('success', "User '{$user->username}' updated");
						/*
						if ($pass_changed) {
							$this->flashbag->add('info', "Password changed");
						} else {
							$this->flashbag->add('info', "Password not changed");
						}
						*/
					} else {
						$this->flashbag->add('error', "User update failed");
					}
					$url = $this->getAdminUsersUrl();
					return new RedirectResponse($url);
				} catch (\Exception $e) {
					$error = $e->getMessage();
					$this->flashbag->add('error', $error);
				}
			}
		}

		return new Response($this->twig->render('user_edit.twig', [
			'edit' => true,
			'error' => $error,
			'qidform' => $qidform->createView(),
			'userform' => $userform->createView(),
			'userdelform' => $userdelform,
			'runtime' => $this->getRuntime(),
			'flashes' => $this->flashbag->all(),
			'is_admin' => $this->session->get('is_admin'),
			'username' => $this->session->get('username'),
			'auth_provider' => $this->session->get('auth_provider'),
			'user_auth_provider' => $user->auth_provider,
			'current_route' => $this->request->getPathInfo(),
			'rspamd_stats' => $this->getRspamdStat(),
		]));
	}

	public function del(int $id): Response {

		if (!is_null($id) and is_int($id)) {
			$user = User::find($id);
			if ($user) {
				if ($user->username !== 'admin') {
					if ($user->delete()) {
						$this->flashbag->add('success', "User '{$user->username}' deleted");
					} else {
						$this->flashbag->add('error', "Failed '{$user->username}' delete");
					}
				} else {
					$this->flashbag->add('warning', 'User "admin" cannot be deleted');
				}
			}
		}

		// get back to users page
		$url = $this->getAdminUsersUrl();
		return new RedirectResponse($url);
	}

	public function getMailAliases(User $user): array {
		$mail_aliases = [];
		/*
		foreach ($user->mailAliases as $mail_alias) {
			$mail_aliases[] = strtolower(trim($mail_alias->alias));
		}
		*/
		return array_unique(array_map('strtolower', array_filter(
		   $user->mailAliases()->pluck('alias')->toArray(),
		   fn($alias) => !empty(trim($alias))
		)));
	}

	public function loginAs(int $id): Response {
		if (!$this->getIsAdmin()) {
			$this->fileLogger->warning("'{$this->session->get('username')}' tried to use loginAs without admin authorization");
			$this->flashbag->add('error', "Permission denied");
			$this->initUrls();
			return new RedirectResponse($this->homepageUrl);
		}

		if (!is_null($id) and is_int($id)) {
			$user = User::where('id', $id)->first();
			// user found
			if ($user) {
				$old_username = $this->session->get('username');

				// only clears $this->vars from base Controller
				$this->unsetSessionVars();

				$this->session->set('username', $user->username);
				$this->session->set('user_id', $user->id);
				$this->session->set('email', $user->email);
				$this->session->set('is_admin', $user->is_admin);

				// need this to get the auth_provider description
				$auth = new AuthManager($this->fileLogger);
				$this->session->set('auth_provider', AuthManager::getAuthProviderById($user->auth_provider));
				$aliases = array_unique(array_map('strtolower', array_filter(
					$user->mailAliases()->pluck('alias')->toArray(),
					fn($alias) => !empty(trim($alias))
				)));
				$this->session->set('user_aliases', $aliases);
				$this->session->set('old_username', $old_username);

				// push session vars to $this->vars
				$this->setSessionVars($this->session);
				$this->unsetUrls();

				$this->fileLogger->info("'{$old_username}' logged in as '{$user->username}'");
				$this->flashbag->add('success', "You are now logged in as {$user->username}");
				$this->initUrls();
				return new RedirectResponse($this->homepageUrl);
			}
		}

		$this->flashbag->add('error', "User not found");
		$this->initUrls();
		$url = $this->getAdminUsersUrl();
		return new RedirectResponse($url);
	}

}
