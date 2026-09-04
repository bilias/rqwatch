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

use App\Core\Routing\RouteName;
use App\Configuration\Config;
use App\Utils\Helper;

use App\Forms\QidForm;
use App\Forms\MailAliasForm;
use App\Forms\MailAliasSearchForm;

use App\Models\MailAlias;
use App\Models\User;

use App\Services\MailAliasService;
use App\Services\UserService;

use Exception;

//use Illuminate\Database\Capsule\Manager as DB;

class MailAliasController extends ViewController
{
	private ?MailAliasService $mailAliasService = null;

	private ?string $adminAliasesUrl = null;

	private function getMailAliasService(): MailAliasService {
		if ($this->mailAliasService === null) {
			$this->mailAliasService = new MailAliasService();
		}

		return $this->mailAliasService;
	}

	private function getAdminAliasesUrl(): string {
		if ($this->adminAliasesUrl === null) {
			$this->adminAliasesUrl = $this->url(RouteName::ADMIN_ALIASES);
		}

		return $this->adminAliasesUrl;
   }

	public function showAll(): Response {
		if (!$this->is_admin) {
			$this->fileLogger->warning("'{$this->username}' tried to access mail aliases without admin authorization");
			$this->flashbag->add('error', "Permission denied");
			return new RedirectResponse($this->getHomepageUrl());
		}

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

		$service = $this->getMailAliasService();
		$url = $this->getAdminAliasesUrl();
		$aliases = $service->showPaginatedAll($url, $page);

		$mailAliasSearchForm = MailAliasSearchForm::create($this->formFactory, $this->request, $this->urlGenerator);

		return new Response($this->twig->render('aliases_paginated.twig', [
			'qidform' => $qidform->createView(),
			'mailaliassearchform' => $mailAliasSearchForm->createView(),
			'aliases' => $aliases,
			'totalRecords' => $aliases->total(),
			'items_per_page' => $this->items_per_page,
			'runtime' => $this->getRuntime(),
			'refresh_rate' => $this->refresh_rate,
			'flashes' => $this->flashbag->all(),
			'is_admin' => $this->is_admin,
			'username' => $this->username,
			'auth_provider' => $this->session->get('auth_provider'),
			'current_route' => $this->request->getPathInfo(),
			'rspamd_stats' => $this->getRspamdStat(),
		]));
	}

	public function searchAlias(): Response {
		if (!$this->is_admin) {
			$this->fileLogger->warning("'{$this->username}' tried to search mail aliases without admin authorization");
			$this->flashbag->add('error', "Permission denied");
			return new RedirectResponse($this->getHomepageUrl());
		}

		// enable form rendering support
		$this->twigFormView($this->request);

		// generate and handle qid form
		$qidform = QidForm::create($this->formFactory, $this->request);
		if ($response = QidForm::check_form($qidform, $this->urlGenerator, $this->is_admin)) {
			// form submitted and valid
			return $response;
		}

		$mailAliasSearchForm = MailAliasSearchForm::create($this->formFactory, $this->request, $this->urlGenerator);

		if ($mailAliasSearchForm->isSubmitted() && !$mailAliasSearchForm->isValid()) {
			$this->flashbag->add('error', 'The value can only contain letters, numbers and ._+-@');
			return new RedirectResponse($this->getAdminAliasesUrl());
		}

		// Get page from ?page=, default 1
		$page = $this->request->query->getInt('page', 1);

		$mail_alias_search_form = $this->request->get('mail_alias_search_form');
		$aliases = null;
		if (!empty($mail_alias_search_form['alias'])) {
			$search = $mail_alias_search_form['alias'];

			$service = $this->getMailAliasService();
			$url = $this->getAdminAliasesUrl();
			$aliases = $service->searchPaginatedAll($url, $search, $page);
		}

		return new Response($this->twig->render('aliases_paginated.twig', [
			'qidform' => $qidform->createView(),
			'mailaliassearchform' => $mailAliasSearchForm->createView(),
			'aliases' => $aliases,
			'totalRecords' => $aliases->total(),
			'items_per_page' => $this->items_per_page,
			'runtime' => $this->getRuntime(),
			'refresh_rate' => $this->refresh_rate,
			'flashes' => $this->flashbag->all(),
			'is_admin' => $this->is_admin,
			'username' => $this->username,
			'auth_provider' => $this->session->get('auth_provider'),
			'current_route' => $this->request->getPathInfo(),
			'rspamd_stats' => $this->getRspamdStat(),
		]));
	}

	public function add(): Response {
		if (!$this->is_admin) {
			$this->fileLogger->warning("'{$this->username}' tried to add mail aliases without admin authorization");
			$this->flashbag->add('error', "Permission denied");
			return new RedirectResponse($this->getHomepageUrl());
		}
		// enable form rendering support
		$this->twigFormView($this->request);

		// generate and handle qid form
		$qidform = QidForm::create($this->formFactory, $this->request);
		if ($response = QidForm::check_form($qidform, $this->urlGenerator, $this->is_admin)) {
			// form submitted and valid
			return $response;
		}

		$error = null;
		$mailaliasform = MailAliasForm::create($this->formFactory, $this->request);

		$url = $this->url(RouteName::ADMIN_ALIASES_ADD);

		if ($mailaliasform->isSubmitted() && $mailaliasform->isValid()) {
			$data = $mailaliasform->getData();
			if (empty($data['username'])) {
				$this->flashbag->add('error', "E-mail empty");
				return new RedirectResponse($url);
			}
			if (empty($data['alias'])) {
				$this->flashbag->add('error', "E-mail alias empty");
				return new RedirectResponse($url);
			}
			$username = strtolower(trim($data['username']));
			$alias = strtolower(trim($data['alias']));

			$service = new UserService();
			$user = $service->showOneByUsername($username);

			if (empty($user)) {
				$this->flashbag->add('error', "Username '{$username}' does not exist");
				return new RedirectResponse($url);
			}
			$user_id = $user->id;
			$username = $user->username;

			$service = $this->getMailAliasService();
			if ($service->aliasExists($user_id, $alias)) {
				$this->flashbag->add('error', "Alias '{$alias}' already exists for user '{$username}'");
				return new RedirectResponse($url);
			}

			$data = array(
				'user_id' => $user_id,
				'alias' => $alias,
			);

			try {
				$mailalias = new MailAlias;
				$mailalias->fill($data);
				$mailalias->save();
				if ($mailalias) {
						$this->fileLogger->info("Alias '{$alias}' created for '{$username}' by '{$this->username}'");
						$this->flashbag->add('success', "Alias '{$alias}' created for '{$username}'");
				} else {
					$this->flashbag->add('error', "Alias creation failed");
				}
				return new RedirectResponse($this->getAdminAliasesUrl());
			} catch (Exception $e) {
				$error = $e->getMessage();
				$this->flashbag->add('error', $error);
			}
		}

		return new Response($this->twig->render('alias_add.twig', [
			'error' => $error,
			'qidform' => $qidform->createView(),
			'mailaliasform' => $mailaliasform->createView(),
			'runtime' => $this->getRuntime(),
			'flashes' => $this->flashbag->all(),
			'is_admin' => $this->is_admin,
			'username' => $this->username,
			'auth_provider' => $this->session->get('auth_provider'),
			'current_route' => $this->request->getPathInfo(),
			'rspamd_stats' => $this->getRspamdStat(),
		]));
	}

	public function edit(int $id): Response {
		// XXX NOT COMPLETE, NOT USED
		exit;
		if (!$this->is_admin) {
			$this->fileLogger->warning("'{$this->username}' tried to edit mail aliases without admin authorization");
			$this->flashbag->add('error', "Permission denied");
			return new RedirectResponse($this->getHomepageUrl());
		}

		// enable form rendering support
		$this->twigFormView($this->request);

		// generate and handle qid form
		$qidform = QidForm::create($this->formFactory, $this->request);
		if ($response = QidForm::check_form($qidform, $this->urlGenerator, $this->is_admin)) {
			// form submitted and valid
			return $response;
		}

		if (!empty($id)) {
			$alias = MailAlias::find($id);
		}

		// 
		if ($alias) {
			$mailaliasform = MailAliasForm::create($this->formFactory, $this->request, $alias->toArray());

		} else {
			// alias does not exist
			// get back to search page
			$this->flashbag->add('error', 'Alias not found.');
			$url = $this->getAdminAliasesUrl();
			return new RedirectResponse($url);
		}

		$error = null;
		if ($mailaliasform->isSubmitted() && $mailaliasform->isValid()) {
			//$data = $mailaliasform->getData()->toArray();
			$data = $mailaliasform->getData();
			dd($data);
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
				$newPassword = $mailaliasform->get('password')->getData();
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
					$url = $this->getAdminAliasesUrl();
					return new RedirectResponse($url);
				} catch (Exception $e) {
					$error = $e->getMessage();
					$this->flashbag->add('error', $error);
				}
			}
		}

		return new Response($this->twig->render('alias_edit.twig', [
			'edit' => true,
			'error' => $error,
			'qidform' => $qidform->createView(),
			'mailaliasform' => $mailaliasform->createView(),
			'runtime' => $this->getRuntime(),
			'flashes' => $this->flashbag->all(),
			'is_admin' => $this->is_admin,
			'username' => $this->username,
			'current_route' => $this->request->getPathInfo(),
			'rspamd_stats' => $this->getRspamdStat(),
		]));
	}

	public function del(int $id): Response {
		if (!$this->is_admin) {
			$this->fileLogger->warning("'{$this->username}' tried to delete alias without admin authorization");
			$this->flashbag->add('error', "Permission denied");
			return new RedirectResponse($this->getAdminAliasesUrl());
		}

		if (!$this->csrfValid('alias_del')) {
			$this->fileLogger->warning(
				"CSRF check failed on alias del from " . $_SERVER['REMOTE_ADDR']
			);
			$this->flashbag->add('error', 'Invalid or expired request. Please try again.');
			return new RedirectResponse($this->getAdminAliasesUrl());
		}

		if (!is_null($id) and is_int($id)) {
			$alias = MailAlias::find($id);
			if ($alias) {
				if ($alias->delete()) {
					$this->fileLogger->info("Alias '{$alias->alias}' deleted by '{$this->username}'");
					$this->flashbag->add('success', "Alias '{$alias->alias}' deleted");
				} else {
					$this->flashbag->add('error', "Failed '{$alias->alias}' delete");
				}
			} else {
				$this->flashbag->add('error', "Alias not found");
			}
		} else {
			$this->flashbag->add('error', "Bad alias id");
		}

		// get back to aliases page
		$url = $this->getAdminAliasesUrl();
		return new RedirectResponse($url);
	}

}
