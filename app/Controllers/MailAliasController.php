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

use App\Core\Config;
use App\Utils\Helper;

use App\Forms\QidForm;
use App\Forms\MailAliasForm;
use App\Forms\MailAliasSearchForm;

use App\Models\MailAlias;
use App\Services\MailAliasService;
use App\Services\UserService;

//use Illuminate\Database\Capsule\Manager as DB;

class MailAliasController extends ViewController
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

		// Get page from ?page=, default 1
		$page = $this->request->query->getInt('page', 1);

		$service = new MailAliasService($this->getFileLogger());
		$url = $this->urlGenerator->generate('admin_aliases');
		$aliases = $service->showPaginatedAll($page, $url);

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
			'is_admin' => $this->session->get('is_admin'),
			'username' => $this->session->get('username'),
			'auth_provider' => $this->session->get('auth_provider'),
			'current_route' => $this->request->getPathInfo(),
			'rspamd_stats' => $this->getRspamdStat(),
		]));
	}

	public function searchAlias(): Response {
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
			return new RedirectResponse($this->urlGenerator->generate('admin_aliases'));
		}

		// Get page from ?page=, default 1
		$page = $this->request->query->getInt('page', 1);

		$mail_alias_search_form = $this->request->get('mail_alias_search_form');
		if (!empty($mail_alias_search_form['alias'])) {
			$search = $mail_alias_search_form['alias'];

			$service = new MailAliasService($this->getFileLogger());
			$url = $this->urlGenerator->generate('admin_aliases');
			$aliases = $service->searchPaginatedAll($page, $url, $search);
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
		$mailaliasform = MailAliasForm::create($this->formFactory, $this->request);

		$url = $this->urlGenerator->generate('admin_aliases_add');

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

			$service = new UserService($this->getFileLogger());
			$user = $service->showOneByUsername($username);

			if (empty($user)) {
				$this->flashbag->add('error', "Username '{$username}' does not exist");
				return new RedirectResponse($url);
			}
			$user_id = $user->id;
			$username = $user->username;

			$service = new MailAliasService($this->getFileLogger());
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
						$this->flashbag->add('success', "Alias '{$alias}' created for '{$username}'");
				} else {
					$this->flashbag->add('error', "Alias creation failed");
				}
				$url = $this->urlGenerator->generate('admin_aliases');
				return new RedirectResponse($url);
			} catch (\Exception $e) {
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
			'is_admin' => $this->session->get('is_admin'),
			'username' => $this->session->get('username'),
			'auth_provider' => $this->session->get('auth_provider'),
			'current_route' => $this->request->getPathInfo(),
			'rspamd_stats' => $this->getRspamdStat(),
		]));
	}

	public function edit(int $id): Response {
		// XXX NOT COMPLETE, NOT USED
		exit;
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
			$url = $this->urlGenerator->generate('admin_aliases');
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
					$url = $this->urlGenerator->generate('admin_users');
					return new RedirectResponse($url);
				} catch (\Exception $e) {
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
			'is_admin' => $this->session->get('is_admin'),
			'username' => $this->session->get('username'),
			'current_route' => $this->request->getPathInfo(),
			'rspamd_stats' => $this->getRspamdStat(),
		]));
	}

	public function del(int $id): Response {

		if (!is_null($id) and is_int($id)) {
			$alias = MailAlias::find($id);
			if ($alias) {
				if ($alias->delete()) {
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
		$url = $this->urlGenerator->generate('admin_aliases');
		return new RedirectResponse($url);
	}

}
