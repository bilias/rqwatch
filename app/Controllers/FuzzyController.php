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
use App\Configuration\Config;
use App\Forms\QidForm;
use App\Forms\FuzzySearchForm;
use App\Core\Exception\FuzzyException;

use App\Models\MailLogFuzzy;

use App\Services\FuzzyService;
use App\Services\MailLogService;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Form\FormInterface;

use Illuminate\Pagination\LengthAwarePaginator;

use Symfony\Component\HttpFoundation\Response;

use InvalidArgumentException;
use Throwable;

class FuzzyController extends ViewController
{
	public function showAll(): Response {
		if ($denied = $this->checkListAccess()) {
			return $denied;
		}

		// enable form rendering support (needed for csrf_token() in the template)
		$this->twigFormView($this->request);

		// generate and handle qid form
		$qidform = QidForm::create($this->formFactory, $this->request);
		if ($response = QidForm::check_form($qidform, $this->urlGenerator, $this->is_admin)) {
			return $response;
		}

		$page = $this->request->query->getInt('page', 1);
		$fuzzy = new FuzzyService();
		$sort = $this->listSort();
		$learned = $fuzzy->getLearnedPaginated(
			$this->url(RouteName::ADMIN_FUZZY), $page, $this->items_per_page, $sort
		);

		return $this->renderList($fuzzy, $learned, $qidform, null, $sort);
	}

	// the search form posts here and is redirected to GET ?q=, the results page
	public function search(): Response {
		if ($denied = $this->checkListAccess()) {
			return $denied;
		}

		// enable form rendering support (needed for csrf_token() in the template)
		$this->twigFormView($this->request);

		// generate and handle qid form
		$qidform = QidForm::create($this->formFactory, $this->request);
		if ($response = QidForm::check_form($qidform, $this->urlGenerator, $this->is_admin)) {
			return $response;
		}

		$form = FuzzySearchForm::create($this->formFactory, $this->request, $this->urlGenerator);
		if ($form->isSubmitted()) {
			if (!$form->isValid()) {
				$this->flashbag->add('error', 'The value can only contain letters, numbers and ._+-@');
				return new RedirectResponse($this->url(RouteName::ADMIN_FUZZY));
			}
			// a GET results page: Back, refresh and paging keep the search
			return new RedirectResponse($this->url(RouteName::ADMIN_FUZZY_SEARCH) . '?' .
				http_build_query(['q' => (string) $form->get('search')->getData()]));
		}

		$search = trim((string) $this->request->query->get('q', ''));
		if ($search === '' || strlen($search) > 128 || !preg_match(FuzzySearchForm::PATTERN, $search)) {
			return new RedirectResponse($this->url(RouteName::ADMIN_FUZZY));
		}

		$page = $this->request->query->getInt('page', 1);
		$fuzzy = new FuzzyService();
		$sort = $this->listSort();
		$learned = $fuzzy->searchLearnedPaginated(
			$search, $this->url(RouteName::ADMIN_FUZZY_SEARCH), $page, $this->items_per_page, $sort
		);

		return $this->renderList($fuzzy, $learned, $qidform, $search, $sort);
	}

	// the mails that hit learned mail $id, the fuzzy list's Hits link
	public function showHitMails(int $id): Response {
		if ($denied = $this->checkListAccess()) {
			return $denied;
		}

		$list = $this->url(RouteName::ADMIN_FUZZY);

		if (!FuzzyService::hitMailsEnabled()) {
			$this->flashbag->add('error', "The fuzzy hit mails need the mail log fuzzy hit mails migration (db:migrate_mail_log_fuzzy_hits_mails)");
			return new RedirectResponse($list);
		}

		$row = MailLogFuzzy::find($id);
		if ($row === null) {
			$this->flashbag->add('error', "Fuzzy record {$id} not found");
			return new RedirectResponse($list);
		}

		$this->twigFormView($this->request);

		$qidform = QidForm::create($this->formFactory, $this->request);
		if ($response = QidForm::check_form($qidform, $this->urlGenerator, $this->is_admin)) {
			return $response;
		}

		try {
			$ids = (new FuzzyService())->getHitMailIds($id, $this->max_items);
		} catch (Throwable $e) {
			$this->fileLogger->error("[FuzzyController_showHitMails] query error: " . $e->getMessage());
			$this->flashbag->add('error', "Fuzzy hits query error. Check logs.");
			return new RedirectResponse($list);
		}

		$page = $this->request->query->getInt('page', 1);
		$logs = (new MailLogService($this->getUserContext()))
			->getPaginatedByIds($ids, $this->url(RouteName::ADMIN_FUZZY_HITS, ['id' => $id]), $page);

		// hits counted on mails since purged, or before this list was recorded
		if ($logs->total() < (int) $row->hits) {
			$this->flashbag->add('info', "{$logs->total()} of {$row->hits} hits listed: the rest are on purged mails or were counted before the hits were linked");
		}

		$flags = FuzzyService::flags();
		$label = $flags[(int) $row->flag]['label'] ?? "flag {$row->flag}";

		return new Response($this->twig->render('home_paginated.twig', [
			'qidform' => $qidform->createView(),
			'logs' => $logs,
			'filters' => [[
				'filter' => 'Fuzzy hit',
				'choice' => "on '{$label}' learned mail",
				'value' => $row->qid,
				// by id: a qid can repeat; none once the learned mail is purged
				'url' => $row->mail_log_id ? $this->url(RouteName::ADMIN_DETAIL, ['type' => 'id', 'value' => $row->mail_log_id]) : null,
			]],
			'queryParams' => [],
			'totalRecords' => $logs->total(),
			'items_per_page' => $this->items_per_page,
			'max_items' => $this->max_items,
			'runtime' => $this->getRuntime(),
			'refresh_rate' => $this->refresh_rate,
			'subject_privacy' => Config::get('subject_privacy'),
			'flashes' => $this->getFlashes(),
			'is_admin' => $this->is_admin,
			'username' => $this->username,
			'auth_provider' => $this->session->get('auth_provider'),
			'current_route' => $this->request->getPathInfo(),
			'rspamd_stats' => $this->getRspamdStat(),
		]));
	}

	// ?sort=hit: by last hit, once the fuzzy hits migration has added the column
	private function listSort(): string {
		return $this->request->query->get('sort') === 'hit' && FuzzyService::hitsEnabled() ? 'hit' : 'learned';
	}

	public function learn(int $id, int $flag): RedirectResponse {
		if ($denied = $this->checkRequest('fuzzy_learn')) {
			return $denied;
		}

		// optional: the list's weight from config when not given
		$weight = null;
		$weight_param = $this->request->query->get('weight');
		if ($weight_param !== null && $weight_param !== '') {
			if (!ctype_digit((string) $weight_param) || (int) $weight_param < 1 || (int) $weight_param > 65535) {
				$this->flashbag->add('error', "Invalid weight, must be 1-65535");
				return $this->detailResponse($id);
			}
			$weight = (int) $weight_param;
		}

		try {
			// findMailLog throws InvalidArgumentException when no mail found
			$maillog = (new MailLogService($this->getUserContext()))->findMailLog($id);
		} catch (InvalidArgumentException $e) {
			$this->flashbag->add('error', $e->getMessage());
			return new RedirectResponse($this->getHomepageUrl());
		}

		try {
			$fuzzy = new FuzzyService();
			$fuzzy->learn($maillog, $flag, (string) $this->username, $weight);
			$entry = FuzzyService::flags()[$flag];
			$used = $weight ?? $entry['weight'];
			$this->flashbag->add('success', "Mail {$maillog->qid} learned as fuzzy '{$entry['label']}' (weight {$used})");
			$this->warnShared($fuzzy, (int) $maillog->id, $flag);
		} catch (FuzzyException $e) {
			$this->flashbag->add('error', $e->getMessage());
		} catch (Throwable $e) {
			$this->fileLogger->error("[FuzzyController] learn of mail {$id} as flag {$flag} failed: " . $e->getMessage());
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
			$fuzzy = new FuzzyService();
			// counted before: the row is gone afterwards
			$shared = count($fuzzy->sharedHashes($row));
			$orphaned = $fuzzy->orphanedSlotHolders($row);
			$fuzzy->unlearn($row, (string) $this->username);
			$flags = FuzzyService::flags();
			$label = $flags[(int) $row->flag]['label'] ?? "flag {$row->flag}";
			$kept = $shared > 0 ? " ({$shared} hash(es) kept, shared with other learned mails)" : '';
			$this->flashbag->add('success', "Mail {$row->qid} unlearned from fuzzy '{$label}'{$kept}");

			// rspamd cannot drop one list's slot of a digest, only the whole digest
			if ($orphaned !== []) {
				$via = implode(', ', array_map(fn (array $h): string =>
					$h['qid'] . " ('" . ($flags[$h['flag']]['label'] ?? "flag {$h['flag']}") . "')", $orphaned));
				$this->flashbag->add('warning', "The '{$label}' entry stays active in rspamd: " .
					"the same content is also learned via {$via}. Unlearn those too to remove it, " .
					"then learn them again if you want them.");
			}
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


	// $id is the mail_log_fuzzy id: adds weight to an existing learn
	public function boost(int $id): RedirectResponse {
		if ($denied = $this->checkRequest('fuzzy_boost')) {
			return $denied;
		}

		$row = MailLogFuzzy::find($id);

		if ($row === null) {
			$this->flashbag->add('error', "Fuzzy record {$id} not found");
			return new RedirectResponse($this->getHomepageUrl());
		}

		$weight = (string) $this->request->query->get('weight', '');
		if (!ctype_digit($weight) || (int) $weight < 1 || (int) $weight > 65535) {
			$this->flashbag->add('error', "Invalid weight, must be 1-65535");
			return $this->detailResponse($row->mail_log_id);
		}

		try {
			(new FuzzyService())->addWeight($row, (int) $weight, (string) $this->username);
			$label = FuzzyService::flags()[(int) $row->flag]['label'] ?? "flag {$row->flag}";
			$this->flashbag->add('success', "Mail {$row->qid}: weight +{$weight} added to fuzzy '{$label}', now " .
				((int) $row->weight + (int) $weight));
		} catch (FuzzyException $e) {
			$this->flashbag->add('error', $e->getMessage());
		} catch (Throwable $e) {
			$this->fileLogger->error("[FuzzyController] add weight to record {$id} failed: " . $e->getMessage());
			$this->flashbag->add('error', "Error. Contact admin");
		}

		return $this->detailResponse($row->mail_log_id);
	}

	// admin and the feature enabled; a redirect otherwise
	private function checkListAccess(): ?RedirectResponse {
		if (!$this->is_admin) {
			$this->fileLogger->warning("'{$this->username}' tried to show fuzzy learned mails without admin authorization");
			$this->flashbag->add('error', "Permission denied");
			return new RedirectResponse($this->getHomepageUrl());
		}

		if (!FuzzyService::isEnabled()) {
			$this->flashbag->add('error', "Fuzzy learning is disabled");
			return new RedirectResponse($this->getHomepageUrl());
		}

		return null;
	}

	private function renderList(FuzzyService $fuzzy, LengthAwarePaginator $learned, FormInterface $qidform, ?string $search, string $sort = 'learned'): Response {
		$searchForm = FuzzySearchForm::create($this->formFactory, $this->request, $this->urlGenerator,
			$search !== null ? ['search' => $search] : null);

		return new Response($this->twig->render('fuzzy.twig', [
			'qidform' => $qidform->createView(),
			'fuzzysearchform' => $searchForm->createView(),
			'search' => $search,
			'sort' => $sort,
			'learned' => $learned,
			'shared' => $fuzzy->sharedWith($learned),
			'fuzzy_flags' => FuzzyService::flags(),
			'fuzzy_hits' => FuzzyService::hitsEnabled(),
			'fuzzy_hit_mails' => FuzzyService::hitMailsEnabled(),
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


	// warn when the hashes of a fresh learn were already learned by other mails
	private function warnShared(FuzzyService $fuzzy, int $mailLogId, int $flag): void {
		try {
			$row = $fuzzy->getByMailLogId($mailLogId);
			$others = $row ? ($fuzzy->sharedWith([$row])[$row->getKey()] ?? []) : [];

			$qids = function (array $rows): string {
				$names = array_column($rows, 'qid');
				$more = count($names) - 3;
				return implode(', ', array_slice($names, 0, 3)) . ($more > 0 ? " (+{$more} more)" : '');
			};

			// a near (not identical) match on arrival: different digests, so not shared
			$sharedIds = array_column($others, 'id');
			$near = array_filter($fuzzy->matchedBy($mailLogId),
				fn (array $m): bool => !in_array($m['id'], $sharedIds, true));
			if ($near !== []) {
				$this->flashbag->add('warning', "This mail had already matched learned mail(s) " .
					$qids($near) . ": its own hashes were added as new entries");
			}

			if ($others === []) {
				return;
			}

			$same = array_filter($others, fn (array $o): bool => $o['flag'] === $flag);
			if ($same !== []) {
				$shared = count($fuzzy->sharedHashes($row));
				$total = count(array_unique((array) $row->hashes));
				$what = $shared >= $total ? 'Its content was' :
					"{$shared} of its {$total} hashes " . ($shared === 1 ? 'was' : 'were');
				$this->flashbag->add('warning', "{$what} already learned via " . $qids($same) .
					": this learn added its weight to the existing entry");
			}

			$labels = FuzzyService::flags();
			foreach (array_unique(array_column($others, 'flag')) as $other) {
				if ($other !== $flag) {
					$label = $labels[$other]['label'] ?? "flag {$other}";
					$this->flashbag->add('warning', "The same content is also learned as '{$label}' via " .
						$qids(array_filter($others, fn (array $o): bool => $o['flag'] === $other)));
				}
			}
		} catch (Throwable $e) {
			$this->fileLogger->error("[FuzzyController] shared-hash check for mail {$mailLogId} failed: " . $e->getMessage());
		}
	}

}
