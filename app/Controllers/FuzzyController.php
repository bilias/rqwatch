<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Controllers;

use App\Core\Routing\RouteName;
use App\Forms\QidForm;
use App\Core\Exception\FuzzyException;

use App\Models\MailLogFuzzy;

use App\Services\FuzzyService;
use App\Services\MailLogService;

use Symfony\Component\HttpFoundation\RedirectResponse;

use Symfony\Component\HttpFoundation\Response;

use InvalidArgumentException;
use Throwable;

class FuzzyController extends ViewController
{
	public function showAll(): Response {
		if (!$this->is_admin) {
			$this->fileLogger->warning("'{$this->username}' tried to show fuzzy learned mails without admin authorization");
			$this->flashbag->add('error', "Permission denied");
			return new RedirectResponse($this->getHomepageUrl());
		}

		if (!FuzzyService::isEnabled()) {
			$this->flashbag->add('error', "Fuzzy learning is disabled");
			return new RedirectResponse($this->getHomepageUrl());
		}

		// enable form rendering support (needed for csrf_token() in the template)
		$this->twigFormView($this->request);

		// generate and handle qid form
		$qidform = QidForm::create($this->formFactory, $this->request);
		if ($response = QidForm::check_form($qidform, $this->urlGenerator, $this->is_admin)) {
			return $response;
		}

		$page = $this->request->query->getInt('page', 1);
		$learned = (new FuzzyService())->getLearnedPaginated(
			$this->url(RouteName::ADMIN_FUZZY), $page, $this->items_per_page
		);

		return new Response($this->twig->render('fuzzy.twig', [
			'qidform' => $qidform->createView(),
			'learned' => $learned,
			'totalRecords' => $learned->total(),
			'items_per_page' => $this->items_per_page,
			'runtime' => $this->getRuntime(),
			'flashes' => $this->getFlashes(),
			'is_admin' => $this->is_admin,
			'username' => $this->username,
			'auth_provider' => $this->session->get('auth_provider'),
			'current_route' => $this->request->getPathInfo(),
			'rspamd_stats' => $this->getRspamdStat(),
		]));
	}

	public function learn(int $id): RedirectResponse {
		if ($denied = $this->checkRequest('fuzzy_learn')) {
			return $denied;
		}

		try {
			// findMailLog throws InvalidArgumentException when no mail found
			$maillog = (new MailLogService($this->getUserContext()))->findMailLog($id);
		} catch (InvalidArgumentException $e) {
			$this->flashbag->add('error', $e->getMessage());
			return new RedirectResponse($this->getHomepageUrl());
		}

		try {
			(new FuzzyService())->learn($maillog, (string) $this->username);
			$this->flashbag->add('success', "Mail {$maillog->qid} learned as fuzzy spam");
		} catch (FuzzyException $e) {
			$this->flashbag->add('error', $e->getMessage());
		} catch (Throwable $e) {
			$this->fileLogger->error("[FuzzyController] learn of mail {$id} failed: " . $e->getMessage());
			$this->flashbag->add('error', "Error. Contact admin");
		}

		return $this->detailResponse($maillog->id);
	}

	// $id is the mail_log_fuzzy id: the mail_logs row may be gone
	public function unlearn(int $id): RedirectResponse {
		if ($denied = $this->checkRequest('fuzzy_unlearn')) {
			return $denied;
		}

		$row = MailLogFuzzy::find($id);

		if ($row === null) {
			$this->flashbag->add('error', "Fuzzy record {$id} not found");
			return new RedirectResponse($this->getHomepageUrl());
		}

		try {
			(new FuzzyService())->unlearn($row, (string) $this->username);
			$this->flashbag->add('success', "Mail {$row->qid} unlearned from fuzzy");
		} catch (FuzzyException $e) {
			$this->flashbag->add('error', $e->getMessage());
		} catch (Throwable $e) {
			$this->fileLogger->error("[FuzzyController] unlearn of record {$id} failed: " . $e->getMessage());
			$this->flashbag->add('error', "Error. Contact admin");
		}

		// unlearn from the list page returns there
		if ($this->request->query->get('return') === 'list') {
			return new RedirectResponse($this->url(RouteName::ADMIN_FUZZY));
		}

		return $this->detailResponse($row->mail_log_id);
	}

	// admin, valid CSRF token and the feature enabled; a redirect otherwise
	private function checkRequest(string $csrfId): ?RedirectResponse {
		if (!$this->is_admin) {
			$this->fileLogger->warning("'{$this->username}' tried {$csrfId} without admin authorization");
			$this->flashbag->add('error', "Permission denied");
			return new RedirectResponse($this->getHomepageUrl());
		}

		if (!$this->csrfValid($csrfId)) {
			$this->fileLogger->warning(
				"CSRF check failed on {$csrfId} from " . ($_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN')
			);
			$this->flashbag->add('error', 'Invalid or expired request. Please try again.');
			return new RedirectResponse($this->getHomepageUrl());
		}

		if (!FuzzyService::isEnabled()) {
			$this->flashbag->add('error', "Fuzzy learning is disabled");
			return new RedirectResponse($this->getHomepageUrl());
		}

		return null;
	}

	private function detailResponse(?int $mailLogId): RedirectResponse {
		if ($mailLogId === null) {
			return new RedirectResponse($this->getHomepageUrl());
		}

		return new RedirectResponse($this->url(RouteName::ADMIN_DETAIL,
			[ 'type' => 'id', 'value' => $mailLogId ]));
	}
}
