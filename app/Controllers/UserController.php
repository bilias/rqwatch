<?php declare(strict_types=1);
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

namespace App\Controllers;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;

use App\Core\Config;
use App\Utils\Helper;

use App\Forms\QidForm;
use App\Forms\UserForm;
use App\Forms\UserDeleteForm;
use App\Forms\ProfileForm;

use App\Models\User;
use App\Services\UserService;

//use Illuminate\Database\Capsule\Manager as DB;

class UserController extends ViewController
{
	protected $refresh_rate;
	protected $items_per_page;
	protected $max_items;

	public function __construct() {
	//	parent::__construct();

		$this->items_per_page = Config::get('items_per_page');
		$this->max_items = Config::get('max_items');
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

		$fields = User::SELECT_FIELDS;

		/* without Pagination
		$service = new UserService($this->getFileLogger());
		$users = $service->showAll();
		*/

		// Get page from ?page=, default 1
		$page = $this->request->query->getInt('page', 1);

		$service = new UserService($this->getFileLogger());
		$url = $this->urlGenerator->generate('admin_users');
		$users = $service->showPaginatedAll($page, $url);

		//return new Response($this->twig->render('home.twig', [
		return new Response($this->twig->render('users_paginated.twig', [
			'qidform' => $qidform->createView(),
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
		$aliases_str = implode(',', array_map('trim', $mail_aliases));

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
		$dbuser = $service->profile();

		if (!$dbuser) {
			$this->flashbag->add('error', "User not found");
			$this->initUrls();
			return new RedirectResponse($this->homepageUrl);
		}

		$profileform = ProfileForm::create($this->formFactory, $this->request, $dbuser->toArray());
		$profileform_t = $profileform->createView(); // for twig

		if ($profileform->isSubmitted() && $profileform->isValid()) {
			$data = $profileform->getData();
			$pass_changed = false;
			$newPassword = $profileform->get('password')->getData();
			if (!empty($newPassword)) {
				$data['password'] = Helper::passwordHash($newPassword);
				$pass_changed = true;
			}

			try {
				$dbuser->fill($data);
				if ($pass_changed) {
					$dbuser->password = $data['password'];
				}
				$dbuser->save();
				if ($dbuser) {
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
			'user' => $dbuser,
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
			if (empty($data['username'])) {
				$this->flashbag->add('error', "Username empty");
			}
			else if (User::where('username', strtolower(trim($data['username'])))
			               ->exists()) {
				$this->flashbag->add('error', "User '{$data['username']}' already exists");
			} else {
				$newPassword = $userform->get('password')->getData();
				if (!empty($newPassword)) {
					$data['password'] = Helper::passwordHash($newPassword);
				} else {
						$this->flashbag->add('error', 'Empty password not allowed.');
						$url = $this->urlGenerator->generate('admin_users');
						return new RedirectResponse($url);
				}

				try {
					$user = new User;
					$data['username'] = strtolower(trim($data['username']));
					$user->fill($data);
					$user->password = $data['password'];
					$user->save();
						if ($user) {
							$this->flashbag->add('success', "User '{$user->username}' created");
						} else {
							$this->flashbag->add('error', "User creation failed");
						}
					$url = $this->urlGenerator->generate('admin_users');
					return new RedirectResponse($url);
				} catch (\Exception $e) {
					$error = $e->getMessage();
					$this->flashbag->add('error', $error);
				}
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
			$url = $this->urlGenerator->generate('admin_users');
			return new RedirectResponse($url);
		}

		$error = null;
		if ($userform->isSubmitted() && $userform->isValid()) {
			//$data = $userform->getData()->toArray();
			$data = $userform->getData();
			// username change and new username exists
			if (empty($data['username'])) {
				$this->flashbag->add('error', "Username empty");
			}
			else if (($data['username'] !== $user->username) and
			         (User::where('username', strtolower(trim($data['username'])))
						       ->exists())) {
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
					$url = $this->urlGenerator->generate('admin_users');
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
			'auth_provider' => $user->auth_provider,
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
		$url = $this->urlGenerator->generate('admin_users');
		return new RedirectResponse($url);
	}

	public function getMailAliases(User $user): array {
		$mail_aliases = [];
		foreach ($user->mailAliases as $mail_alias) {
			$mail_aliases[] = $mail_alias->alias;
		}
		return $mail_aliases;
	}

}
