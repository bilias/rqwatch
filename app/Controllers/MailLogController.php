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
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

use App\Core\Config;
use App\Utils\Helper;

use App\Forms\QidForm;
use App\Forms\SearchForm;
use App\Forms\MailReleaseForm;

use App\Models\MailLog;
use App\Services\MailLogService;

use App\Inventory\MapInventory;

class MailLogController extends ViewController
{
	protected int $refresh_rate;
	protected int $items_per_page;
	protected int $max_items;
	protected string $quarantine_dir;

	public function __construct() {
	//	parent::__construct();

		$this->refresh_rate = Config::get('refresh_rate');
		$this->items_per_page = Config::get('items_per_page');
		$this->max_items = Config::get('max_items');
		$this->subject_privacy = Config::get('subject_privacy');
		$this->quarantine_dir = $_ENV['QUARANTINE_DIR'];
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

		$fields = MailLog::SELECT_FIELDS;

		/* without Pagination
		$service = new MailLogService($this->getFileLogger(), $this->session);
		$logs = $service->showAll();
		*/

		// Get page from ?page=, default 1
		$page = $this->request->query->getInt('page', 1);

		$service = new MailLogService($this->getFileLogger(), $this->session);;

		if ($this->getIsAdmin()) {
			$url = $this->urlGenerator->generate('admin_homepage');
		} else {
			$url = $this->urlGenerator->generate('homepage');
		}
		$logs = $service->showPaginatedAll(array(), $page, $url);

		//return new Response($this->twig->render('home.twig', [
		return new Response($this->twig->render('home_paginated.twig', [
			'qidform' => $qidform->createView(),
			'logs' => $logs,
			'totalRecords' => $logs->total(),
			'items_per_page' => $this->items_per_page,
			'max_items' => $this->max_items,
			'runtime' => $this->getRuntime(),
			'refresh_rate' => $this->refresh_rate,
			'subject_privacy' => $this->subject_privacy,
			'flashes' => $this->flashbag->all(),
			'is_admin' => $this->getIsAdmin(),
			'username' => $this->session->get('username'),
			'auth_provider' => $this->session->get('auth_provider'),
			'current_route' => $this->request->getPathInfo(),
		]));
	}

	public function showResults(): Response {
		// enable form rendering support
		$this->twigFormView($this->request);

		// generate and handle qid form
		$qidform = QidForm::create($this->formFactory, $this->request);
		if ($response = QidForm::check_form($qidform, $this->urlGenerator, $this->is_admin)) {
			// form submitted and valid
			return $response;
		}

		$fields = MailLog::SELECT_FIELDS;

		// get filters from session
		$filters = $this->session->get('filters');
		if ($filters) {
			$filters = json_decode($filters, true);
		} else {
			$filters = array();
		}

		// Get page from ?page=, default 1
		$page = $this->request->query->getInt('page', 1);

		$service = new MailLogService($this->getFileLogger(), $this->session);

		if ($this->getIsAdmin()) {
			$url = $this->urlGenerator->generate('admin_search_results');
		} else {
			$url = $this->urlGenerator->generate('search_results');
		}
		$logs = $service->showPaginatedResults($filters, $page, $url);

		return new Response($this->twig->render('home_paginated.twig', [
			'qidform' => $qidform->createView(),
			'logs' => $logs,
			'totalRecords' => $logs->total(),
			'items_per_page' => $this->items_per_page,
			'max_items' => $this->max_items,
			'runtime' => $this->getRuntime(),
			'subject_privacy' => $this->subject_privacy,
			'flashes' => $this->flashbag->all(),
			'is_admin' => $this->getIsAdmin(),
			'username' => $this->session->get('username'),
			'auth_provider' => $this->session->get('auth_provider'),
			'current_route' => $this->request->getPathInfo(),
		]));
	}

	public function showDay(string $date = null): Response {
		// enable form rendering support
		$this->twigFormView($this->request);

		// generate and handle qid form
		$qidform = QidForm::create($this->formFactory, $this->request);
		if ($response = QidForm::check_form($qidform, $this->urlGenerator, $this->is_admin)) {
			// form submitted and valid
			return $response;
		}

		$fields = MailLog::SELECT_FIELDS;

		// Get page from ?page=, default 1
		$page = $this->request->query->getInt('page', 1);

		$service = new MailLogService($this->getFileLogger(), $this->session);

		if ($this->getIsAdmin()) {
			$url = $this->urlGenerator->generate('admin_day_logs', [
				'date' => $date,
			]);
		} else {
			$url = $this->urlGenerator->generate('day_logs', [
				'date' => $date,
			]);
		}
		$logs = $service->showPaginatedDay($page, $date, $url);
		
		return new Response($this->twig->render('home_paginated.twig', [
			'qidform' => $qidform->createView(),
			'logs' => $logs,
			'totalRecords' => $logs->total(),
			'date' => $date,
			'items_per_page' => $this->items_per_page,
			'max_items' => $this->max_items,
			'refresh_rate' => $this->refresh_rate,
			'runtime' => $this->getRuntime(),
			'subject_privacy' => $this->subject_privacy,
			'flashes' => $this->flashbag->all(),
			'is_admin' => $this->getIsAdmin(),
			'username' => $this->session->get('username'),
			'auth_provider' => $this->session->get('auth_provider'),
			'current_route' => $this->request->getPathInfo(),
		]));
	}

	public function showQuarantineDay(string $date = null): Response {
		// enable form rendering support
		$this->twigFormView($this->request);

		// generate and handle qid form
		$qidform = QidForm::create($this->formFactory, $this->request);
		if ($response = QidForm::check_form($qidform, $this->urlGenerator, $this->is_admin)) {
			// form submitted and valid
			return $response;
		}

		$fields = MailLog::SELECT_FIELDS;

		// Get page from ?page=, default 1
		$page = $this->request->query->getInt('page', 1);

		$service = new MailLogService($this->getFileLogger(), $this->session);
		if ($this->getIsAdmin()) {
			$url = $this->urlGenerator->generate('admin_quarantine_day', [
				'date' => $date,
			]);
		} else {
			$url = $this->urlGenerator->generate('quarantine_day', [
				'date' => $date,
			]);
		}
		$logs = $service->showPaginatedQuarantineDay($page, $date, $url);
		
		return new Response($this->twig->render('home_paginated.twig', [
			'qidform' => $qidform->createView(),
			'logs' => $logs,
			'totalRecords' => $logs->total(),
			'date' => $date,
			'items_per_page' => $this->items_per_page,
			'max_items' => $this->max_items,
			'runtime' => $this->getRuntime(),
			'subject_privacy' => $this->subject_privacy,
			'flashes' => $this->flashbag->all(),
			'is_admin' => $this->getIsAdmin(),
			'username' => $this->session->get('username'),
			'auth_provider' => $this->session->get('auth_provider'),
			'current_route' => $this->request->getPathInfo(),
		]));
	}

	public function showQuarantine(): Response {
		// enable form rendering support
		$this->twigFormView($this->request);

		// generate and handle qid form
		$qidform = QidForm::create($this->formFactory, $this->request);
		if ($response = QidForm::check_form($qidform, $this->urlGenerator, $this->is_admin)) {
			// form submitted and valid
			return $response;
		}

		$service = new MailLogService($this->getFileLogger(), $this->session);
		//$days = $service->showQuarantine();

		// Get page from ?page=, default 1
		$page = $this->request->query->getInt('page', 1);

		if ($this->getIsAdmin()) {
			$url = $this->urlGenerator->generate('admin_quarantine');
		} else {
			$url = $this->urlGenerator->generate('quarantine');
		}

		$days = $service->showPaginatedQuarantine($page, $url);
		$totalMails = 0;
		foreach ($days as $day) {
			$totalMails += $day->cnt;
		}

		return new Response($this->twig->render('quarantine_paginated.twig', [
			'qidform' => $qidform->createView(),
			'days' => $days,
			'totalRecords' => $days->total(),
			'totalMails' => $totalMails,
			'items_per_page' => $this->items_per_page,
			'runtime' => $this->getRuntime(),
			'flashes' => $this->flashbag->all(),
			'is_admin' => $this->getIsAdmin(),
			'username' => $this->session->get('username'),
			'auth_provider' => $this->session->get('auth_provider'),
			'current_route' => $this->request->getPathInfo(),
		]));
	}

	public function detail(string $type, string|int $value): Response {
		// enable form rendering support
		$this->twigFormView($this->request);

		// generate and handle qid form
		$qidform = QidForm::create($this->formFactory, $this->request);
		if ($response = QidForm::check_form($qidform, $this->urlGenerator, $this->is_admin)) {
			// form submitted and valid
			return $response;
		}

		$service = new MailLogService($this->getFileLogger(), $this->session);
		$error = null;

		try {
			$ar = $service->detail($type, $value);
		} catch (\InvalidArgumentException $e) {
			$this->flashbag->add('error', $e->getMessage());
			$this->initUrls();
			return new RedirectResponse($this->homepageUrl);
		}

		$stripped_mail_location = null;
		$mailreleaseform_t = null;

		if (!empty($ar['log']->mail_location)) {
			$stripped_mail_location = Helper::stripBasePath($this->quarantine_dir, $ar['log']->mail_location);
		}

		// mail is in quarantine
		if ($ar['log']->mail_stored && !empty($ar['log']->mail_location)) {
			$form_data = array(
				'id' => $ar['log']->id,
				'email' => $ar['log']->rcpt_to,
			);

			// mailreleaseform submit goes to seperate method releaseMail()
			$options = ['action' => $this->getReleaseUrl($ar['log']->id)];

			$mailreleaseform = MailReleaseForm::create($this->formFactory, $this->request, $form_data, $options);
			$mailreleaseform_t = $mailreleaseform->createView();
		}

		$mailreleaseform_disabled=false;
		if (empty($_ENV['MAILER_FROM'])) {
			$this->fileLogger->error('MAILER_FROM is empty. Please define it in .env');
			$mailreleaseform_disabled=true;
		}

		$ip_country = Helper::getCountry($ar['log']->ip);

		$map_configs = $this->getMapConfigsWithAddUrls($ar['log']);

		return new Response($this->twig->render('detail.twig', [
			'qidform' => $qidform->createView(),
			'mailreleaseform' => $mailreleaseform_t,
			'log' => $ar['log'],
			'ip_country' => $ip_country,
			'stripped_mail_location' => $stripped_mail_location,
			'error' => $error,
			'mailreleaseform_disabled' => $mailreleaseform_disabled,
			'symbols' => $ar['symbols'],
			'virus_found' => $ar['virus_found'],
			'relays' => $ar['received'],
			'map_configs' => $map_configs,
			'runtime' => $this->getRuntime(),
			'subject_privacy' => $this->subject_privacy,
			'flashes' => $this->flashbag->all(),
			'is_admin' => $this->getIsAdmin(),
			'username' => $this->session->get('username'),
			'auth_provider' => $this->session->get('auth_provider'),
			'current_route' => $this->request->getPathInfo(),
		]));
	}

	public function releaseMail(int $id): RedirectResponse {
		if (empty($_ENV['MAILER_FROM'])) {
			$this->fileLogger->error('MAILER_FROM is empty. Please define it in .env');
			$this->flashbag->add('error', "MAILER_FROM is empty. Contact admin");
			$this->initUrls();
			return new RedirectResponse($this->homepageUrl);
		}

		$service = new MailLogService($this->getFileLogger(), $this->session);
		$error = null;

		try {
			// has applyUserScope()
			$maillog = $service->showQuarantinedMail($id);
		} catch (\InvalidArgumentException $e) {
			$this->syslogLogger->warning("{$this->email} tried to release mail with id: {$id}. Either mail does not exist or user does not have access to it.", ['email' => $this->email, 'is_admin' => $this->is_admin]);
			$this->flashbag->add('error', $e->getMessage());
			$this->initUrls();
			return new RedirectResponse($this->homepageUrl);
		}

		$this->twigFormView($this->request);

		$form_data = array(
			'id' => $maillog->id,
			'email' => $maillog->rcpt_to,
		);

		$mailreleaseform = MailReleaseForm::create($this->formFactory, $this->request, $form_data);

		// release form submitted
		if (!empty($mailreleaseform) && $mailreleaseform->isSubmitted() && $mailreleaseform->isValid()) {
			$data = $mailreleaseform->getData();

			if (empty($data['email'])) {
				$this->flashbag->add('error', "Original recipient address missing from mail");
				return $this->getDetailIdResponse($maillog->id);
			}

			if (empty($data['release']) and empty($data['release_alt'])) {
				$this->flashbag->add('error', "No recipient checked");
				return $this->getDetailIdResponse($maillog->id);
			}

			if (!empty($data['release']) and (empty($data['email']) or $data['email'] === 'unknown')) {
				$this->flashbag->add('error', "Cannot release to empty of unknown original recipient. Use alternate recipient");
				return $this->getDetailIdResponse($maillog->id);
			}

			// rcpt_to is different than session email (or aliases). Should NOT happen, unless we are admin
			     // rcpt_to different than logged in user's mail or aliases
			if (((!in_array($maillog->rcpt_to, array_merge([$this->getEmail()], $this->user_aliases ?? []), true)) or
			    ($maillog->rcpt_to !== $data['email']))				// rcpt_to defferent than form's original recipient
				  and !$this->getIsAdmin()) {								// admin can release everything
				$this->fileLogger->error("Mail release failed", [
					'user' => $this->session->get('username'),
					'user_mail' => $this->session->get('email'),
					'qid' => $maillog->qid,
					'db_id' => $maillog->id,
					'rcpt_to' => $maillog->rcpt_to,
					'release_to' => $data['email'],
					'is_admin' => $this->getIsAdmin(),
				]);
				$this->syslogLogger->error("Mail release of {$maillog->qid} by " .
					$this->session->get('email') .
					" failed. Check rqwatch logs for details");

				$this->flashbag->add('error', "Wrong personal recipient address. Contact admin");
				return $this->getDetailIdResponse($maillog->id);
			}
			$release_to = [];

			// add original recipient checked
			if (!empty($data['release']) and !empty($data['email'])) {
				$release_to[] = $data['email'];
			}
			if (!empty($data['release_alt']) and empty($data['email_alt'])) {
				$this->flashbag->add('error', "Cannot release to empty alternate recipient");
				return $this->getDetailIdResponse($maillog->id);
			}
			if (empty($data['release_alt']) and !empty($data['email_alt'])) {
				$this->flashbag->add('error', "Alternate recipient checkbox not ticked");
				return $this->getDetailIdResponse($maillog->id);
			}
			if (!empty($data['release_alt']) and !empty($data['email_alt'])) {
				$release_to[] = $data['email_alt'];
			}

			// Mail stored locally
			if ($_ENV['MY_API_SERVER_ALIAS'] === $maillog->server) {
				$success = $service->releaseHtmlMail($release_to, $maillog, $this->twig);
				if ($success) {
					$this->fileLogger->info("Message {$maillog->qid} released to '" .
						implode(', ', $release_to) . "' by '" . $this->session->get('email') .
						"' via local web/api");
					$this->syslogLogger->info("Message {$maillog->qid} released to '" .
						implode(', ', $release_to) . "' by '" . $this->session->get('email') .
						"' via local web/api");
				}
			// Mail stored in remote server. Call their API
			} else {
				$success = $service->releaseMailViaApi($release_to, $maillog->id, $maillog->server);
			}

			if ($success) {
				$this->flashbag->add('success', "Message released to " .
					implode(', ', $release_to) . " by " . $this->session->get('email'));
				return $this->getDetailIdResponse($maillog->id);
			} else {
				$this->flashbag->add('error', "Message failed to be released");
				$this->fileLogger->error("Message {$maillog->qid} failed to be released");
				return $this->getDetailIdResponse($maillog->id);
			}
		}

		// form empty or invalid
		$this->flashbag->add('error', "Invalid release mail data");
		return $this->getDetailIdResponse($maillog->id);
	}

	public function showMail(int $id): Response {
		// enable form rendering support
		$this->twigView($this->request);

		$error = null;
		$service = new MailLogService($this->getFileLogger(), $this->session);

		try {
			// has applyUserScope
			$mailobject = $service->getMailObject($id);
		} catch (\Exception $e) {
			$this->fileLogger->warning($e->getMessage() . ". Mail does not exist or user does not have access to it");
			$this->flashbag->add('error', $e->getMessage());
			$this->initUrls();
			return new RedirectResponse($this->homepageUrl);
		}

		return new Response($this->twig->render('mail.twig', [
			'textBody' => $mailobject->getTextBody(),
			'htmlBody' => $mailobject->getHtmlBody(),
			'attached' => $mailobject->getAttached(),
			'log' => $mailobject->getMailLog(),
			'virus_found' => $mailobject->getVirusFound(),
			'symbols' => $mailobject->getSymbols(),
			'error' => $error,
			'runtime' => $this->getRuntime(),
			'flashes' => $this->flashbag->all(),
			'is_admin' => $this->getIsAdmin(),
			'username' => $this->session->get('username'),
			'auth_provider' => $this->session->get('auth_provider'),
			'current_route' => $this->request->getPathInfo(),
		]));
	}

	public function saveAttachment(int $id, int $attach_id): Response {
		$service = new MailLogService($this->getFileLogger(), $this->session);

		$this->initUrls();
		try {
			// has applyUserScope
			$mailobject = $service->getMailObject($id);
		} catch (\Exception $e) {
			$this->flashbag->add('warning', $e->getMessage());
			return new RedirectResponse($this->homepageUrl);
		}

		try {
			$attachment = $service->getAttachment($mailobject->getAttachments(), $attach_id);
		} catch (\Exception $e) {
			$this->flashbag->add('warning', $e->getMessage());
			return new RedirectResponse($this->homepageUrl);
		}

		$filename = $attachment->getFilename();
		$filetype = $attachment->getFileType();
		$content = $attachment->getContent();
		$size = $attachment->getSize();

		$disposition = HeaderUtils::makeDisposition(
			HeaderUtils::DISPOSITION_ATTACHMENT,
			"$filename"
		);

		// Create streamed response to output content and force download
		$response = new StreamedResponse(function () use ($content) {
			echo $content;
		});

		$response->headers->set('Content-Type', $filetype);
		$response->headers->set('Content-Disposition', $disposition);
		$response->headers->set('Content-Length', "$size");

		return $response;
	}

	public function openAttachment(int $id, int $attach_id): Response {
		$service = new MailLogService($this->getFileLogger(), $this->session);

		$this->initUrls();
		try {
			// has applyUserScope
			$mailobject = $service->getMailObject($id);
		} catch (\Exception $e) {
			$this->flashbag->add('error', $e->getMessage());
			return new RedirectResponse($this->homepageUrl);
		}

		try {
			$attachment = $service->getAttachment($mailobject->getAttachments(), $attach_id);
		} catch (\Exception $e) {
			$this->flashbag->add('warning', $e->getMessage());
			return new RedirectResponse($this->homepageUrl);
		}


		$filename = $attachment->getFilename();
		$filetype = $attachment->getFileType();
		$content = $attachment->getContent();
		$size = $attachment->getSize();

		$disposition = HeaderUtils::makeDisposition(
			HeaderUtils::DISPOSITION_INLINE,
			"$filename"
		);

		// Create streamed response to output content and force download
		$response = new StreamedResponse(function () use ($content) {
			echo $content;
		});

		$response->headers->set('Content-Type', $filetype);
		$response->headers->set('Content-Disposition', $disposition);
		$response->headers->set('Content-Length', "$size");

		return $response;
	}

	public function search(): Response {
		// enable form rendering support
		$this->twigFormView($this->request);

		// generate and handle qid form
		$qidform = QidForm::create($this->formFactory, $this->request);
		if ($response = QidForm::check_form($qidform, $this->urlGenerator, $this->is_admin)) {
			// form submitted and valid
			return $response;
		}

		$searchform = SearchForm::create($this->formFactory, $this->request);

		if ($searchform->isSubmitted() && $searchform->isValid()) {
			$data = $searchform->getData();

			// get old filters from session
			$filters = $this->session->get('filters');
			if ($filters) {
				$filters = json_decode($filters, true);
			} else {
				$filters = array();
			}

			// add new filters
			$filters[] = array(
				'filter' => $data['filter'],
				'choice' => $data['choice'],
				'value' => $data['value'],
			);
			$this->session->set('filters', json_encode($filters));
		}

		// show active filters
		$filters = $this->session->get('filters');

		if ($filters) {
			$filters = json_decode($filters, true);
		} else {
			$filters = array();
		}

		// get current stats
		$service = new MailLogService($this->getFileLogger(), $this->session);
		// array with results
		if (Config::get('show_stats')) {
			$stats = $service->showStats($filters);
		} else {
			$stats = array();
		}

		return new Response($this->twig->render('search.twig', [
			'qidform' => $qidform->createView(),
			'filters' => $filters,
			'stats'   => $stats,
			'searchform' => $searchform->createView(),
			'runtime' => $this->getRuntime(),
			'flashes' => $this->flashbag->all(),
			'is_admin' => $this->getIsAdmin(),
			'username' => $this->session->get('username'),
			'auth_provider' => $this->session->get('auth_provider'),
			'current_route' => $this->request->getPathInfo(),
		]));
	}

	public function search_filter_del(int $filter_id = null): Response {
		$filters_ar = array();

		// user asked to delete a specific filter number
		if (!is_null($filter_id) and is_int($filter_id)) {
			$filters = $this->session->get('filters');

			if (!empty($filters)) {
				$filters_ar = json_decode($filters, true);
				unset($filters_ar[$filter_id]);
				if (count($filters_ar) > 0) {
					$filters_ar = array_values($filters_ar);
					$this->session->set('filters', json_encode($filters_ar));
				} else { // no more filters left
					$this->session->set('filters', null);
				}
			}
		} else {
			// if no filter_id (/del) delete all filters
			$this->session->set('filters', null);
		}

		// get back to search page
		$this->initUrls();
		return new RedirectResponse($this->searchUrl);
	}

	public function getDetailIdResponse(int $id): RedirectResponse {
		if ($this->getIsAdmin()) {
			return new RedirectResponse($this->urlGenerator->generate('admin_detail',
				[ 'type' => 'id', 'value' => $id ]));
		} else {
			return new RedirectResponse($this->urlGenerator->generate('detail',
				[ 'type' => 'id', 'value' => $id ]));
		}
	}

	public function getReleaseUrl(int $id): string {
		// mailrelease form calls releaseMail()
		if ($this->getIsAdmin()) {
			return $this->urlGenerator->generate('admin_releasemail',
				[ 'id' => $id ]);
		} else {
			return $this->urlGenerator->generate('releasemail',
				[ 'id' => $id ]);
		}
	}

	public function getMapConfigsWithAddUrls(MailLog $log, ?string $map = null): array {
		$configs = MapInventory::getAvailableMapConfigs($this->getRole(), $map);

		if ($map !== null && empty($configs)) {
				return [];
		}

		if (!empty($log->mime_from)) {
			$log->mime_from_normalized = Helper::extractEmail($log->mime_from);
		}

		$fieldMap = [
			'smtp_from' => 'mail_from',
			'mime_from' => 'mime_from_normalized',
		];

		foreach ($configs as $mapName => &$cfg) {
			$route = $this->getRole() === 'admin' ? 'admin_map_add_entry' : 'map_add_entry';
			$queryParams = ['map' => $mapName];

			foreach ($cfg['fields'] ?? [] as $field) {
				$sourceField = $fieldMap[$field] ?? $field;
				$value = $log->{$sourceField} ?? null;

				if (!empty($value)) {
					$queryParams[$field] = $value;
				}
			}
			$cfg['add_url'] = $this->urlGenerator->generate($route, $queryParams);
		}
		unset($cfg);

		return $configs;
	}

}
