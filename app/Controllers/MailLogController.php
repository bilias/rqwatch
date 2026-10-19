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
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;

use App\Core\App;

use App\Core\Routing\RouteName;
use App\Configuration\Config;
use App\Utils\Helper;
use App\Utils\FormHelper;

use App\Charts\Chart;
use App\Charts\ChartBuilder;

use App\Forms\QidForm;
use App\Forms\SearchForm;
use App\Forms\MailReleaseForm;

use App\Models\MailLog;
use App\Services\MailLogService;
use App\Services\FuzzyService;
use App\Services\UserService;
use App\Services\MapService;
use App\Services\MapHitService;
use App\Services\MailerService;

use App\Inventory\MapInventory;

use App\Core\Exception\QueryTimeoutException;

use Exception;
use InvalidArgumentException;
use Throwable;

class MailLogController extends ViewController
{
	private ?MailLogService $mailLogService = null;

	private int $q_items_per_page;
	private string $quarantine_dir;
	private bool $subject_privacy;

	private ?string $searchResultsUrl = null;
	private ?string $dayLogsUrl = null;
	private ?string $quarantineUrl = null;
	private ?string $quarantinePerMonthUrl = null;
	private ?string $allUrl = null;

	public function __construct() {
		parent::__construct();

		$this->q_items_per_page = (int) Config::get('q_items_per_page') ?: 31;
		$this->subject_privacy = Config::get('subject_privacy');
		$this->quarantine_dir = $_ENV['QUARANTINE_DIR'];
	}

	private function getMailLogService(): MailLogService {
		if ($this->mailLogService === null) {
			$this->mailLogService = new MailLogService($this->getUserContext());
		}

		return $this->mailLogService;
	}

	private const INLINE_SAFE_TYPES = [
		'text/plain',
		'image/png',
		'image/jpeg',
		'image/gif',
		'image/webp',
	];

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

		$service = $this->getMailLogService();

		try {
			$logs = $service->getPaginatedAll(array(), $this->getAllUrl(), $page);
		} catch (QueryTimeoutException $e) {
			Helper::failRequest($e->getMessage());
		}

		return new Response($this->twig->render('home_paginated.twig', [
			'qidform' => $qidform->createView(),
			'logs' => $logs,
			'totalRecords' => $logs->total(),
			'items_per_page' => $this->items_per_page,
			'max_items' => $this->max_items,
			'runtime' => $this->getRuntime(),
			'refresh_rate' => $this->refresh_rate,
			'subject_privacy' => $this->subject_privacy,
			'flashes' => $this->getFlashes(),
			'is_admin' => $this->is_admin,
			'username' => $this->username,
			'auth_provider' => $this->session->get('auth_provider'),
			'current_route' => $this->request->getPathInfo(),
			'rspamd_stats' => $this->getRspamdStat(),
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

		$filters = $this->getFiltersFromSession();

		// merge GET filters
		$queryParams = [];
		foreach (MailLog::REPORT_DYN_FIELDS as $field) {
			$value = $this->request->query->get($field);
			if ($value !== null) {
				// we don't require create_day migration status here
				$key = array_search($field, FormHelper::getFilters($this->is_admin));
				if ($key !== false) {
					$filters[] = [
						'filter' => $key,
						'choice' => 'is equal to',
						'value' => $value
					];
					$queryParams[$field] = $value;
				}
				// special case
				if ($field === 'date') {
					// Remove dual date from search (ie date > )
					// and date = from Reports -> Mails by Day
					// that comes back in results with ?date= query

					$filters = array_values(array_filter(
						$filters,
						static fn(array $filter): bool =>
							($filter['filter'] ?? null) !== 'Date'
					));

					$filters[] = [
						'filter' => 'Date',
						'choice' => 'is equal to',
						'value' => $value
					];
					$queryParams[$field] = $value;
				}
			}
		}

		// ?since=Y-m-d: from that day on, as the symbols report links its last days
		$since = (string) $this->request->query->get('since', '');
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $since) && strtotime($since) !== false) {
			$filters[] = [
				'filter' => 'Date',
				'choice' => 'is greater than or equal',
				'value' => $since
			];
			$queryParams['since'] = $since;
		}

		// Get page from ?page=, default 1
		$page = $this->request->query->getInt('page', 1);

		$service = $this->getMailLogService();

		try {
			$logs = $service->getPaginatedAll($filters, $this->getSearchResultsUrl(), $page);
		} catch (QueryTimeoutException $e) {
			$this->flashbag->add('error', $e->getMessage()
				. ". Narrow it with more filters, for example a date.");
			return new RedirectResponse($this->getSearchUrl());
		}

		return new Response($this->twig->render('home_paginated.twig', [
			'qidform' => $qidform->createView(),
			'logs' => $logs,
			'totalRecords' => $logs->total(),
			'items_per_page' => $this->items_per_page,
			'max_items' => $this->max_items,
			'runtime' => $this->getRuntime(),
			'subject_privacy' => $this->subject_privacy,
			'flashes' => $this->getFlashes(),
			'is_admin' => $this->is_admin,
			'username' => $this->username,
			'auth_provider' => $this->session->get('auth_provider'),
			'current_route' => $this->request->getPathInfo(),
			'rspamd_stats' => $this->getRspamdStat(),
			'queryParams' => $queryParams,
			'filters' => $filters,
		]));
	}

	public function showReports(string $field, string $mode = 'count'): Response {
		if (empty($field)
		    || !in_array($field, MailLog::REPORT_FIELDS)
		    || (!$this->is_admin && in_array($field, MailLog::ADMIN_ONLY_FIELDS, true))
		) {
			$this->flashbag->add('error', "Field '{$field}' does not exist");
			return new RedirectResponse($this->getSearchUrl());
		}

		// enable form rendering support
		$this->twigFormView($this->request);

		// generate and handle qid form
		$qidform = QidForm::create($this->formFactory, $this->request);
		if ($response = QidForm::check_form($qidform, $this->urlGenerator, $this->is_admin)) {
			// form submitted and valid
			return $response;
		}

		$filters = $this->getFiltersFromSession();

		if (!$this->mailReportsEnabled($filters)) {
			$this->flashbag->add('warning', "Mail Reports are disabled");
			return new RedirectResponse($this->getHomepageUrl());
		}

		$service = $this->getMailLogService();

		// has applyUserScope
		$logs = $service->getReports($filters, $field, $mode)->toArray();

		$sum['count'] = 0;
		$sum['size'] = 0;
		$sum['stored'] = 0;
		foreach ($logs as $log) {
			$sum['count'] += $log['total'];
			$sum['size'] += $log['total_size'];
			$sum['stored'] += $log['total_stored'];
		}

		$field = isset($logs[0]) ? array_key_first($logs[0]) : null;

		return new Response($this->twig->render('reports.twig', [
			'qidform' => $qidform->createView(),
			'field' => $field,
			'reportFields' => MailLog::REPORT_DYN_FIELDS,
			'mode' => $mode,
			'logs' => $logs,
			'sum' => $sum,
			'filters' => $filters,
			'items_per_page' => $this->items_per_page,
			'max_items' => (int) Config::get('top_reports') ?: 30,
			'runtime' => $this->getRuntime(),
			'subject_privacy' => $this->subject_privacy,
			'flashes' => $this->getFlashes(),
			'is_admin' => $this->is_admin,
			'username' => $this->username,
			'auth_provider' => $this->session->get('auth_provider'),
			'current_route' => $this->request->getPathInfo(),
			'rspamd_stats' => $this->getRspamdStat(),
		]));
	}

	// top rspamd symbols of the current search (admin): ?sort=score (average), ?info=1
	public function showSymbolsReport(): Response {
		$this->twigFormView($this->request);

		$qidform = QidForm::create($this->formFactory, $this->request);
		if ($response = QidForm::check_form($qidform, $this->urlGenerator, $this->is_admin)) {
			return $response;
		}

		$filters = $this->getFiltersFromSession();

		if (!$this->mailReportsEnabled($filters)) {
			$this->flashbag->add('warning', "Mail Reports are disabled");
			return new RedirectResponse($this->getHomepageUrl());
		}

		$sort = $this->request->query->get('sort') === 'score' ? 'score' : 'mails';
		$informational = $this->request->query->get('info') === '1';
		$limit = (int) Config::get('top_reports') ?: 30;

		try {
			[$mails, $actions, $rows, $since] = $this->getMailLogService()->getSymbolsReport($filters, $informational, $sort, $limit);
		} catch (Throwable $e) {
			$this->fileLogger->error("[MailLogController_showSymbolsReport] query error: " . $e->getMessage());
			$this->flashbag->add('error', "Symbols report query error. Check logs.");
			return new RedirectResponse($this->getSearchUrl());
		}

		return new Response($this->twig->render('symbols_report.twig', [
			'qidform' => $qidform->createView(),
			'rows' => $rows,
			'actions' => $actions,
			'mails' => $mails,
			'since' => $since,
			'sort' => $sort,
			'informational' => $informational,
			'limit' => $limit,
			'filters' => $filters,
			'max_items' => $this->max_items,
			'runtime' => $this->getRuntime(),
			'flashes' => $this->getFlashes(),
			'is_admin' => $this->is_admin,
			'username' => $this->username,
			'auth_provider' => $this->session->get('auth_provider'),
			'current_route' => $this->request->getPathInfo(),
			'rspamd_stats' => $this->getRspamdStat(),
		]));
	}

	public function showDay(?string $date = null): Response {
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

		$service = $this->getMailLogService();

		$logs = $service->getPaginatedDay($date, $this->getDayLogsUrl(), $page);
		
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
			'flashes' => $this->getFlashes(),
			'is_admin' => $this->is_admin,
			'username' => $this->username,
			'auth_provider' => $this->session->get('auth_provider'),
			'current_route' => $this->request->getPathInfo(),
			'rspamd_stats' => $this->getRspamdStat(),
		]));
	}

	public function showQuarantineDay(?string $date = null): Response {
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

		$service = $this->getMailLogService();

		$logs = $service->getPaginatedQuarantineDay(
			$date,
			$this->getQuarantineDayUrl($date),
			$page
		);
		
		return new Response($this->twig->render('home_paginated.twig', [
			'qidform' => $qidform->createView(),
			'logs' => $logs,
			'totalRecords' => $logs->total(),
			'date' => $date,
			'in_quarantine' => true,
			'items_per_page' => $this->items_per_page,
			'max_items' => $this->max_items,
			'runtime' => $this->getRuntime(),
			'subject_privacy' => $this->subject_privacy,
			'flashes' => $this->getFlashes(),
			'is_admin' => $this->is_admin,
			'username' => $this->username,
			'auth_provider' => $this->session->get('auth_provider'),
			'current_route' => $this->request->getPathInfo(),
			'rspamd_stats' => $this->getRspamdStat(),
		]));
	}

	public function showQuarantineMonth(?string $month = null): Response {
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

		$service = $this->getMailLogService();

		try {
			$logs = $service->getPaginatedQuarantineMonth(
				$month,
				$this->getQuarantineMonthUrl($month ?? date('Y-m')),
				$page
			);
		} catch (InvalidArgumentException $e) {
			$this->flashbag->add('error', $e->getMessage());
			return new RedirectResponse($this->getQuarantinePerMonthUrl());
		}

		return new Response($this->twig->render('home_paginated.twig', [
			'qidform' => $qidform->createView(),
			'logs' => $logs,
			'totalRecords' => $logs->total(),
			'date' => $month,
			'in_quarantine' => true,
			'items_per_page' => $this->items_per_page,
			'max_items' => $this->max_items,
			'runtime' => $this->getRuntime(),
			'subject_privacy' => $this->subject_privacy,
			'flashes' => $this->getFlashes(),
			'is_admin' => $this->is_admin,
			'username' => $this->username,
			'auth_provider' => $this->session->get('auth_provider'),
			'current_route' => $this->request->getPathInfo(),
			'rspamd_stats' => $this->getRspamdStat(),
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

		$service = $this->getMailLogService();

		// Get page from ?page=, default 1
		$page = $this->request->query->getInt('page', 1);

		$days = $service->getPaginatedQuarantine($this->getQuarantineUrl(), $page);
		$totalMailsInPage = 0;
		foreach ($days as $day) {
			$totalMailsInPage += $day->cnt;
		}

		$chart = null;

		if ($totalMailsInPage > 0) {
			$chart = $this->createChart(
				fn(iterable $d): Chart => ChartBuilder::createQuarantineChart($d, $this->getQuarantineDayUrl(...), $this->getQuarantineDayTotals($service, $d)),
				$days
			);
		}

		return new Response($this->twig->render('quarantine_paginated.twig', [
			'qidform' => $qidform->createView(),
			'days' => $days,
			'totalRecords' => $days->total(),
			'totalMailsInPage' => $totalMailsInPage,
			'items_per_page' => $this->q_items_per_page,
			'chart' => $chart,
			'show_charts' => $chart !== null,
			'runtime' => $this->getRuntime(),
			'flashes' => $this->getFlashes(),
			'is_admin' => $this->is_admin,
			'username' => $this->username,
			'auth_provider' => $this->session->get('auth_provider'),
			'current_route' => $this->request->getPathInfo(),
			'rspamd_stats' => $this->getRspamdStat(),
			'quarantine_days' => (int) $_ENV['QUARANTINE_DAYS'],
		]));
	}

	public function showQuarantinePerMonth(): Response {
		// enable form rendering support
		$this->twigFormView($this->request);

		// generate and handle qid form
		$qidform = QidForm::create($this->formFactory, $this->request);
		if ($response = QidForm::check_form($qidform, $this->urlGenerator, $this->is_admin)) {
			// form submitted and valid
			return $response;
		}

		$service = $this->getMailLogService();

		// Get page from ?page=, default 1
		$page = $this->request->query->getInt('page', 1);

		$months = $service->getPaginatedQuarantinePerMonth($this->getQuarantinePerMonthUrl(), $page);
		$totalMailsInPage = 0;
		foreach ($months as $month) {
			$totalMailsInPage += $month->cnt;
		}

		$chart = null;

		if ($totalMailsInPage > 0) {
			$chart = $this->createChart(
				fn(iterable $d): Chart => ChartBuilder::createQuarantinePerMonthChart($d, $this->getQuarantineMonthUrl(...), $this->getQuarantineMonthTotals($service, $d)),
				$months
			);
		}

		return new Response($this->twig->render('quarantine_paginated_per_month.twig', [
			'qidform' => $qidform->createView(),
			'months' => $months,
			'totalRecords' => $months->total(),
			'totalMailsInPage' => $totalMailsInPage,
			'items_per_page' => $this->q_items_per_page,
			'chart' => $chart,
			'show_charts' => $chart !== null,
			'runtime' => $this->getRuntime(),
			'flashes' => $this->getFlashes(),
			'is_admin' => $this->is_admin,
			'username' => $this->username,
			'auth_provider' => $this->session->get('auth_provider'),
			'current_route' => $this->request->getPathInfo(),
			'rspamd_stats' => $this->getRspamdStat(),
			'quarantine_days' => (int) $_ENV['QUARANTINE_DAYS'],
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

		$service = $this->getMailLogService();
		$error = null;

		try {
			$ar = $service->detail($type, $value);
		} catch (InvalidArgumentException $e) {
			$this->flashbag->add('error', $e->getMessage());
			return new RedirectResponse($this->getHomepageUrl());
		}

		$stripped_mail_location = null;

		if (!empty($ar['log']->mail_location)) {
			$stripped_mail_location = Helper::stripBasePath($this->quarantine_dir, $ar['log']->mail_location);
			// base not a prefix: don't expose the absolute path to non-admins
			if ($stripped_mail_location === $ar['log']->mail_location) {
				$stripped_mail_location = null;
			}
		}

		[$mailreleaseform_t, $mailreleaseform_disabled] = $this->releaseFormView($ar['log']);

		$ip_country = Helper::getCountry($ar['log']->ip);

		$map_configs = $this->getMapConfigsWithAddUrls($ar['log']);

		// pending or skipped mail:
		// true if no recipient would be notified with current settings
		$notifications_disabled = ($ar['log']->notification_pending || $ar['log']->notified === null)
			&& (new UserService())->notificationsDisabledForAll(
				$ar['log']->recipients->pluck('recipient_email')->all()
			);

		$blacklisted = Helper::checkForBlacklist($ar['log']->symbols ?? []);

		// virus skip map entry matching this mail; only mails with an antivirus symbol can match
		$virus_skip = $ar['log']->has_virus
			? Helper::virusSkipMatch($ar['log']->symbols ?? [], MapService::getVirusSkipPatterns() ?? [])
			: null;

		// still pending, but past notification_days: the cron will not retry it
		$notification_cutoff = MailLogService::notificationCutoff();
		$notified_expired = $ar['log']->notification_pending
			&& $notification_cutoff !== null
			&& $ar['log']->created_day->format('Y-m-d') < $notification_cutoff;

		// admin row: only once the admin_notified columns exist
		$admin_notifications = App::migrationStatus()->adminNotifiedCompleted();
		$admin_notified_expired = $admin_notifications
			&& $ar['log']->admin_notified === false
			&& $ar['log']->created_day->format('Y-m-d') < MailLogService::adminNotificationCutoff();

		// admin row: fuzzy learning state, and whether Learn is offered
		$fuzzy_enabled = $this->is_admin && FuzzyService::isEnabled();
		$fuzzy_service = $fuzzy_enabled ? new FuzzyService() : null;
		$fuzzy = $fuzzy_service?->getByMailLogId($ar['log']->id);
		$fuzzy_shared = $fuzzy ? ($fuzzy_service->sharedWith([$fuzzy])[$fuzzy->id] ?? []) : [];
		$fuzzy_flags = $fuzzy_enabled ? FuzzyService::flags() : [];
		$fuzzy_learnable = $fuzzy_enabled && $fuzzy === null && $fuzzy_flags !== []
			&& $ar['log']->mail_stored
			&& FuzzyService::serverEnabled($ar['log']->server);

		$fuzzy_boostable = $fuzzy !== null && $fuzzy->weight < 65535
			&& $ar['log']->mail_stored
			&& FuzzyService::serverEnabled($ar['log']->server);

		return new Response($this->twig->render('detail.twig', [
			'qidform' => $qidform->createView(),
			'mailreleaseform' => $mailreleaseform_t,
			'log' => $ar['log'],
			'notifications_disabled' => $notifications_disabled,
			'blacklisted' => $blacklisted,
			'virus_skip' => $virus_skip,
			'notified_expired' => $notified_expired,
			'admin_notifications' => $admin_notifications,
			'admin_notifications_enabled' => !empty(Helper::adminNotificationRcpt()),
			'admin_notified_expired' => $admin_notified_expired,
			'fuzzy_enabled' => $fuzzy_enabled,
			'fuzzy' => $fuzzy,
			'fuzzy_shared' => $fuzzy_shared,
			'fuzzy_matched' => $fuzzy_enabled ? $fuzzy_service->matchedBy((int) $ar['log']->id) : [],
			'fuzzy_learnable' => $fuzzy_learnable,
			'fuzzy_boostable' => $fuzzy_boostable,
			'fuzzy_flags' => $fuzzy_flags,
			'fuzzy_hits' => $fuzzy_enabled && FuzzyService::hitsEnabled(),
			'ip_country' => $ip_country,
			'stripped_mail_location' => $stripped_mail_location,
			'error' => $error,
			'mailreleaseform_disabled' => $mailreleaseform_disabled,
			'symbols' => $ar['symbols'],
			'symbol_links' => $this->symbolLinks((int) $ar['log']->id),
			'virus_found' => $ar['virus_found'],
			'relays' => $ar['received'],
			'map_configs' => $map_configs,
			'runtime' => $this->getRuntime(),
			'subject_privacy' => $this->subject_privacy,
			'flashes' => $this->getFlashes(),
			'is_admin' => $this->is_admin,
			'username' => $this->username,
			'auth_provider' => $this->session->get('auth_provider'),
			'current_route' => $this->request->getPathInfo(),
			'rspamd_stats' => $this->getRspamdStat(),
		]));
	}

	/*
	 * Per symbol name, links to the maps a mail's recorded hit belongs to
	 * (maps naming the symbol, maps of the entries it was credited to) and to
	 * the credited entries; only maps and entries the viewer can see.
	 */
	private function symbolLinks(int $mailLogId): array {
		if (!MapService::mapHitsEnabled()) {
			return [];
		}

		try {
			$hitService = new MapHitService();
			$hits = $hitService->mailHits($mailLogId);
			if ($hits === []) {
				return [];
			}

			$available = MapInventory::getAvailableMapConfigs($this->getRole());
			$byName = [];
			foreach (MapService::mapSymbols() as $map => $names) {
				foreach ($names as $name) {
					$byName[$name][] = $map;
				}
			}
			$entryMaps = $hitService->entryMaps(array_merge([], ...array_values($hits)));
			$mapService = new MapService($this->getUserContext());

			$links = [];
			foreach ($hits as $name => $entries) {
				$maps = $byName[$name] ?? [];
				$entryLinks = [];
				foreach ($entries as [$model, $id]) {
					$map = $entryMaps["{$model}:{$id}"] ?? null;
					if ($map === null || !isset($available[$map])) {
						continue;
					}
					$maps[] = $map;
					if (!$this->is_admin && $mapService->findMapEntry($available[$map]['model'], $map, $id) === null) {
						continue;
					}
					$entryLinks[] = [
						'label' => 'entry',
						'url' => $this->url($this->is_admin ? RouteName::ADMIN_MAP_SHOW : RouteName::MAP_SHOW, ['map' => $map])
							. '?' . http_build_query(['entry' => $id]),
					];
				}
				foreach (array_unique($maps) as $map) {
					if (isset($available[$map])) {
						$links[$name][] = [
							'label' => $available[$map]['description'] ?? $map,
							'url' => $this->url($this->is_admin ? RouteName::ADMIN_MAP_SHOW : RouteName::MAP_SHOW, ['map' => $map]),
						];
					}
				}
				foreach ($entryLinks as $link) {
					$links[$name][] = $link;
				}
			}
			return $links;
		} catch (Throwable $e) {
			$this->fileLogger->error("[MailLogController_symbolLinks] map hits query error: " . $e->getMessage());
			return [];
		}
	}

	public function releaseMail(int $id): RedirectResponse {
		$service = $this->getMailLogService();

		try {
			// has applyUserScope()
			$maillog = $service->getQuarantinedMailLog($id);
		} catch (InvalidArgumentException $e) {
			$this->syslogLogger->warning("{$this->email} tried to release mail with id: {$id}. Either mail does not exist or user does not have access to it.", ['email' => $this->email, 'is_admin' => $this->is_admin]);
			$this->flashbag->add('error', $e->getMessage());
			return new RedirectResponse($this->getHomepageUrl());
		}

		if ($_ENV['MY_API_SERVER_ALIAS'] === $maillog->server && !MailerService::isConfigured()) {
			$this->fileLogger->error('MAILER_DSN or MAILER_FROM is not set in .env');
			$this->flashbag->add('error', "Mail cannot be released. Contact administrator");
			return $this->getDetailIdResponse($maillog->id);
		}

		$this->twigFormView($this->request);

		$form_data = array(
			'id' => $maillog->id,
			'email' => $maillog->mail_recipients,
		);

		$mailreleaseform = MailReleaseForm::create($this->formFactory, $this->request, $form_data);

		// release form submitted
		if ($mailreleaseform->isSubmitted() && $mailreleaseform->isValid()) {
			$data = $mailreleaseform->getData();

			if (empty($data['email'])) {
				$this->flashbag->add('error', "Original recipient address missing from mail");
				return $this->getDetailIdResponse($maillog->id);
			}

			if (empty($data['release']) && empty($data['release_alt'])) {
				$this->flashbag->add('error', "No recipient checked");
				return $this->getDetailIdResponse($maillog->id);
			}

			if (!empty($data['release']) && $data['email'] === 'unknown') {
				$this->flashbag->add('error', "Cannot release to unknown original recipient. Use alternate recipient");
				return $this->getDetailIdResponse($maillog->id);
			}

			// get all emails from user (primary + aliases)
			$emails = array_unique(array_filter(array_map('strtolower', array_merge([$this->email], $this->user_aliases))));
			// split DB recipients into array
			$rcptToList = array_map(fn($e) => strtolower(trim($e)), explode(',', $maillog->mail_recipients));
			// split form's original recipients
			$formEmailList = array_map(fn($e) => strtolower(trim($e)), explode(',', $data['email']));

			if (
				 // none of user's emails match recipients
				 (count(array_intersect($emails, $rcptToList)) === 0
				  // DB rcpt_to differs from form
				  || $rcptToList !== $formEmailList)
				 && !$this->is_admin // admin bypass
			) {
				$this->fileLogger->error("Mail release failed", [
					'user' => $this->username,
					'user_mail' => $this->email,
					'qid' => $maillog->qid,
					'db_id' => $maillog->id,
					'rcpt_to' => $maillog->mail_recipients,
					'release_to' => $data['email'],
					'is_admin' => $this->is_admin,
				]);
				$this->syslogLogger->error("Mail release of {$maillog->qid} by " .
					$this->email .
					" failed. Check rqwatch logs for details");

				$this->flashbag->add('error', "Wrong personal recipient address. Contact admin");
				return $this->getDetailIdResponse($maillog->id);
			}
			$release_to = [];

			// add original recipient checked
			if (!empty($data['release'])) {
				$release_to = array_merge($release_to, array_filter($rcptToList));
			}
			if (!empty($data['release_alt']) && empty($data['email_alt'])) {
				$this->flashbag->add('error', "Cannot release to empty alternate recipient");
				return $this->getDetailIdResponse($maillog->id);
			}
			if (empty($data['release_alt']) && !empty($data['email_alt'])) {
				$this->flashbag->add('error', "Alternate recipient checkbox not ticked");
				return $this->getDetailIdResponse($maillog->id);
			}
			if (!empty($data['release_alt']) && !empty($data['email_alt'])) {
				$alt = Helper::parseEmailList($data['email_alt']);
				if ($alt === null) {
					$this->flashbag->add('error', "Invalid alternate recipient address");
					return $this->getDetailIdResponse($maillog->id);
				}
				$release_to = array_merge($release_to, $alt);
			}
			$release_to = array_values(array_unique($release_to));

			// Mail stored locally
			if ($_ENV['MY_API_SERVER_ALIAS'] === $maillog->server) {
				$success = $service->releaseHtmlMail($release_to, $maillog, $this->twig);
			// Mail stored in remote server. Call their API
			} else {
				$success = $service->releaseMailViaApi(
				   $release_to,
					$maillog->id,
					$maillog->server,
					$this->email ?? ''
				);
			}

			if ($success) {
				// per-mail trail of who released it; a remote node also logs its own send
				$msg = "Message {$maillog->qid} released to '" . implode(', ', $release_to)
					. "' by '{$this->email}' from '" . ($_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN')
					. "' via web (sent by {$maillog->server})";
				$this->fileLogger->info($msg);
				$this->syslogLogger->info($msg);
				$this->flashbag->add('success', 
					"Mail {$maillog->qid} released to " .
					implode(', ', $release_to));
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
		$this->twigFormView($this->request);

		$error = null;
		$service = $this->getMailLogService();

		try {
			// has applyUserScope
			$mailobject = $service->getMailObject($id);
		} catch (Exception $e) {
			$this->fileLogger->warning("showMail() problem: " . $e->getMessage());
			$this->flashbag->add('error', $e->getMessage());
			return new RedirectResponse($this->getHomepageUrl());
		}

		$htmlBody = Helper::normalizeToUtf8($mailobject->getHtmlBody());

		[$mailreleaseform, $mailreleaseform_disabled] = $this->releaseFormView($mailobject->getMailLog());

		$attached = $mailobject->getAttached();
		foreach ($attached as &$attach) {
			$attach['inline_safe'] = $this->isInlineSafeType($attach['filetype']);
		}
		unset($attach);

		//return new Response($this->twig->render('mail.twig', [
		$response = new Response($this->twig->render('mail.twig', [
			'textBody' => $mailobject->getTextBody(),
			'htmlBody' => $htmlBody,
			'attached' => $attached,
			'log' => $mailobject->getMailLog(),
			'virus_found' => $mailobject->getVirusFound(),
			'symbols' => $mailobject->getSymbols(),
			'mailreleaseform' => $mailreleaseform,
			'mailreleaseform_disabled' => $mailreleaseform_disabled,
			'error' => $error,
			'runtime' => $this->getRuntime(),
			'flashes' => $this->getFlashes(),
			'is_admin' => $this->is_admin,
			'username' => $this->username,
			'auth_provider' => $this->session->get('auth_provider'),
			'current_route' => $this->request->getPathInfo(),
			'rspamd_stats' => $this->getRspamdStat(),
		]));
		// this stopped our javascript for on/off toggle
		//$response->headers->set('Content-Security-Policy', "sandbox");
		return $response;
	}

	public function saveAttachment(int $id, int $attach_id): Response {
		$service = $this->getMailLogService();

		try {
			// has applyUserScope
			$mailobject = $service->getMailObject($id);
		} catch (Exception $e) {
			$this->fileLogger->warning("saveAttachment() problem: " . $e->getMessage());
			$this->flashbag->add('error', $e->getMessage());
			return new RedirectResponse($this->getHomepageUrl());
		}

		try {
			$attachment = $service->getAttachment($mailobject->getAttachments(), $attach_id);
		} catch (Exception $e) {
			$this->flashbag->add('warning', $e->getMessage());
			return new RedirectResponse($this->getHomepageUrl());
		}

		[$filename, $filenameFallback] = $this->attachmentNames($attachment->getFilename());

		$filetype = $attachment->getFileType();
		$content = $attachment->getContent();
		$size = $attachment->getSize();

		$disposition = HeaderUtils::makeDisposition(
			HeaderUtils::DISPOSITION_ATTACHMENT,
			"$filename",
			"$filenameFallback"
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
		$service = $this->getMailLogService();

		try {
			// has applyUserScope
			$mailobject = $service->getMailObject($id);
		} catch (Exception $e) {
			$this->fileLogger->warning("openAttachment() problem: " . $e->getMessage());
			$this->flashbag->add('error', $e->getMessage());
			return new RedirectResponse($this->getHomepageUrl());
		}

		try {
			$attachment = $service->getAttachment($mailobject->getAttachments(), $attach_id);
		} catch (Exception $e) {
			$this->flashbag->add('warning', $e->getMessage());
			return new RedirectResponse($this->getHomepageUrl());
		}

		[$filename, $filenameFallback] = $this->attachmentNames($attachment->getFilename());

		//$filetype = $attachment->getFileType();
		$filetype = $this->safeInlineContentType($attachment->getFileType());

		$content = $attachment->getContent();
		$size = $attachment->getSize();

		$disposition = HeaderUtils::makeDisposition(
			HeaderUtils::DISPOSITION_INLINE,
			"$filename",
			"$filenameFallback"
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

		$deleted_filter = $this->flashbag->get('deleted_filter', []);
		if (!empty($deleted_filter)) {
			$prefillData = $deleted_filter;
		} else {
			$prefillData = [];
		}

		$searchform = SearchForm::create(
			$this->formFactory,
			$this->request,
			$this->is_admin,
			$prefillData
		);

		if ($searchform->isSubmitted() && $searchform->isValid()) {
			$data = $searchform->getData();

			$filters = $this->getFiltersFromSession();
			// add new filters
			$filters[] = [
				'filter' => $data['filter'],
				'choice' => $data['choice'],
				'value' => $data['value'],
			];
			$this->saveFiltersToSession($filters);

			// back to a GET page: refresh or Back must not add the filter again
			return new RedirectResponse($this->getSearchUrl());
		}

		// show active filters
		$filters = $this->getFiltersFromSession();

		// get current stats
		$service = $this->getMailLogService();

		$configTTLData = $this->getRedisConfigTTLData();

		$stats = $this->getMailStats($service, $filters);

		$chart = null;
		if (($stats['count'] ?? 0) > 0) {
			$chart = $this->createChart(
				[ChartBuilder::class, 'createSearchChart'],
				$stats
			);
		}

		return new Response($this->twig->render('search.twig', [
			'qidform' => $qidform->createView(),
			'filters' => $filters,
			'stats'   => $stats,
			'chart'   => $chart,
			'show_charts' => $chart !== null,
			'show_reports' => $this->mailReportsEnabled($filters),
			'searchform' => $searchform->createView(),
			'runtime' => $this->getRuntime(),
			'flashes' => $this->getFlashes(),
			'is_admin' => $this->is_admin,
			'username' => $this->username,
			'auth_provider' => $this->session->get('auth_provider'),
			'current_route' => $this->request->getPathInfo(),
			'rspamd_stats' => $this->getRspamdStat(),
			'redis_config_ttl' => $configTTLData['ttl_human'],
			'redis_config_expires_at' => $configTTLData['expires_at'],
			'dns_cache_count' => $this->is_admin ? Helper::count_dns_cache() : null,
			'database_cleanup' => $_ENV['DATABASE_DAYS'] ?? null,
		]));
	}

	public function search_filter_add_day(): RedirectResponse {
		$today = date("Y-m-d");
		$today_filter = array(
			'filter' => 'Date',
			'choice' => 'is equal to',
			'value' => $today
		);

		$filters = $this->getFiltersFromSession();
		$filters[] = $today_filter;
		$this->saveFiltersToSession($filters);

		// get back to search page
		return new RedirectResponse($this->getSearchUrl());
	}

	public function search_filter_del(?int $filter_id = null): RedirectResponse {

		// user asked to delete a specific filter number
		if ($filter_id !== null) {
			$filters = $this->getFiltersFromSession();

			if (!empty($filters)) {
				if (array_key_exists($filter_id, $filters)) {
					$this->flashbag->set('deleted_filter', $filters[$filter_id]);
					unset($filters[$filter_id]);
				}

				$this->saveFiltersToSession($filters);
			}
		} else {
			// if no filter_id (/del) delete all filters
			$this->saveFiltersToSession([]);
		}

		// get back to search page
		return new RedirectResponse($this->getSearchUrl());
	}

	private function getDetailIdResponse(int $id): RedirectResponse {
		if ($this->is_admin) {
			return new RedirectResponse($this->url(RouteName::ADMIN_DETAIL,
				[ 'type' => 'id', 'value' => $id ]));
		} else {
			return new RedirectResponse($this->url(RouteName::DETAIL,
				[ 'type' => 'id', 'value' => $id ]));
		}
	}

	private function getReleaseUrl(int $id): string {
		// mailrelease form calls releaseMail()
		if ($this->is_admin) {
			return $this->url(RouteName::ADMIN_RELEASEMAIL, ['id' => $id]);
		} else {
			return $this->url(RouteName::RELEASEMAIL, ['id' => $id]);
		}
	}

	private function getMapConfigsWithAddUrls(MailLog $log, ?string $map = null): array {
		$configs = MapInventory::getAvailableMapConfigs($this->getRole(), $map);

		if ($map !== null && empty($configs)) {
				return [];
		}

		if (!empty($log->mime_from)) {
			$log->mime_from_normalized = Helper::extractEmail($log->mime_from);
		}

		// Map config field names to the model attribute holding the value.
		$fieldMap = [
			'mime_from' => 'mime_from_normalized',
			'rcpt_to'   => 'mail_recipients',
		];

		$route = $this->is_admin
			? RouteName::ADMIN_MAP_ADD_ENTRY
			: RouteName::MAP_ADD_ENTRY;

		foreach ($configs as $mapName => &$cfg) {
			$queryParams = ['map' => $mapName];

			foreach ($cfg['fields'] ?? [] as $field) {
				$sourceField = $fieldMap[$field] ?? $field;
				$value = $log->{$sourceField} ?? null;

				if (!empty($value)) {
					$queryParams[$field] = $value;
				}
			}
			$cfg['add_url'] = $this->url($route, $queryParams);
		}
		unset($cfg);

		return $configs;
	}

	private function chartsEnabled(): bool {
		if (!Config::get('show_charts')) {
			return false;
		}

		if (!$this->is_admin && !Config::get('show_user_charts')) {
			return false;
		}

		return true;
	}

	private function createChart(callable $builder, iterable $stats): ?Chart {
		if (!$this->chartsEnabled()) {
			return null;
		}

		return $builder($stats);
	}

	// totals for the stored/total bar; 31 days, cheap whatever the table size
	private function getQuarantineDayTotals(MailLogService $service, iterable $days): array {
		$keys = [];
		foreach ($days as $day) {
			$keys[] = (string) $day->day;
		}

		if ($keys === []) {
			return [];
		}

		return $service->getDailyTotals(min($keys), max($keys));
	}

	// totals for the stored/total bar; reads the whole history,
	// same cost class as unfiltered stats, so gated the same way
	private function getQuarantineMonthTotals(MailLogService $service, iterable $months): array {
		if (!$this->mailStatsEnabled([])) {
			return [];
		}

		$keys = [];
		foreach ($months as $month) {
			$keys[] = (string) $month->month;
		}

		if ($keys === []) {
			return [];
		}

		$from = min($keys) . '-01';
		$to = date('Y-m-t', strtotime(max($keys) . '-01'));

		$totals = [];
		foreach ($service->getDailyTotals($from, $to) as $day => $total) {
			$month = substr($day, 0, 7);
			$totals[$month] = ($totals[$month] ?? 0) + $total;
		}

		return $totals;
	}

	private function mailReportsEnabled($filters): bool {
		if (!Config::get('show_mail_reports')) {
			return false;
		}

		if (!$this->is_admin && !Config::get('show_user_mail_reports')) {
			return false;
		}

		if (!empty($filters)) {
			return true;
		}

		if (!Config::get('show_unfiltered_mail_reports')) {
			return false;
		}

		if (!$this->is_admin && !Config::get('show_unfiltered_user_mail_reports')) {
			return false;
		}

		return true;
	}

	private function mailStatsEnabled($filters): bool {
		if (!Config::get('show_mail_stats')) {
			return false;
		}

		if (!$this->is_admin && !Config::get('show_user_mail_stats')) {
			return false;
		}

		if (!empty($filters)) {
			return true;
		}

		if (!Config::get('show_unfiltered_mail_stats')) {
			return false;
		}

		if (!$this->is_admin && !Config::get('show_unfiltered_user_mail_stats')) {
			return false;
		}

		return true;
	}

	private function getMailStats(MailLogService $service, array $filters): array {
		if ($this->mailStatsEnabled($filters)) {
			// has applyUserScope
			$stats = $service->getStats($filters);

			if ($stats === null) {
				// distinguish a failed stats query from stats being
				// disabled: both render no block, only one is an error
				$this->flashbag->add('error',
					"Statistics unavailable - query error. Check the logs, and remove the filter if it persists.");

				return [];
			}

			return $stats;
		}

		return [];
	}

	private function getFiltersFromSession(): array {
		$filters = $this->session->get('filters');
		if (empty($filters)) {
			return [];
		}
		$decoded = json_decode($filters, true);

		return is_array($decoded) ? $decoded : [];
	}

	private function saveFiltersToSession(array $filters): void {
		$this->session->set('filters', json_encode(array_values($filters)));
	}

	// name for filename* (path separators replaced) and an ASCII-only fallback
	private function attachmentNames(?string $name): array {
		$filename = str_replace(['/', '\\'], '_', (string) $name);
		$ext = pathinfo($filename, PATHINFO_EXTENSION);
		$fallback = md5($filename) . (preg_match('/^[A-Za-z0-9]{1,10}$/', $ext) ? ".{$ext}" : '');
		return [$filename, $fallback];
	}

	// release form of a quarantined mail for the detail and mail pages: [view or null, disabled]
	// it posts to releaseMail(), which re-checks access and recipients
	private function releaseFormView(MailLog $log): array {
		$view = null;
		if ($log->mail_stored && !empty($log->mail_location)) {
			$form = MailReleaseForm::create(
				$this->formFactory,
				$this->request,
				['id' => $log->id, 'email' => $log->mail_recipients],
				['action' => $this->getReleaseUrl($log->id)]
			);
			$view = $form->createView();
		}

		// a remote mail is sent by its own node, which checks its own settings
		$disabled = $_ENV['MY_API_SERVER_ALIAS'] === $log->server && !MailerService::isConfigured();
		if ($disabled) {
			$this->fileLogger->error('MAILER_DSN or MAILER_FROM is not set in .env');
		}

		return [$view, $disabled];
	}

	private function isInlineSafeType(string $filetype): bool {
		$normalized = strtolower(trim(explode(';', $filetype)[0]));
		return in_array($normalized, self::INLINE_SAFE_TYPES, true);
	}

	private function safeInlineContentType(string $filetype): string {
		return $this->isInlineSafeType($filetype) ? $filetype : 'application/octet-stream';
	}

	private function getSearchResultsUrl(): string {
		if ($this->searchResultsUrl === null) {
			$this->searchResultsUrl = $this->is_admin
				? $this->url(RouteName::ADMIN_SEARCH_RESULTS)
				: $this->url(RouteName::SEARCH_RESULTS);
		}

		return $this->searchResultsUrl;
	}

	private function getAllUrl(): string {
		if ($this->allUrl === null) {
			$this->allUrl = $this->is_admin
				? $this->url(RouteName::ADMIN_HOMEPAGE)
				: $this->url(RouteName::HOMEPAGE);
		}

		return $this->allUrl;
	}

	private function getDayLogsUrl(): string {
		if ($this->dayLogsUrl === null) {
			$this->dayLogsUrl = $this->is_admin
				? $this->url(RouteName::ADMIN_DAY_LOGS)
				: $this->url(RouteName::DAY_LOGS);
		}

		return $this->dayLogsUrl;
	}

	private function getQuarantineUrl(): string {
		if ($this->quarantineUrl === null) {
			$this->quarantineUrl = $this->is_admin
				? $this->url(RouteName::ADMIN_QUARANTINE)
				: $this->url(RouteName::QUARANTINE);
		}

		return $this->quarantineUrl;
	}

	private function getQuarantinePerMonthUrl(): string {
		if ($this->quarantinePerMonthUrl === null) {
			$this->quarantinePerMonthUrl = $this->is_admin
				? $this->url(RouteName::ADMIN_QUARANTINE_PER_MONTH)
				: $this->url(RouteName::QUARANTINE_PER_MONTH);
		}

		return $this->quarantinePerMonthUrl;
	}

	private function getQuarantineDayUrl(string $date): string {
		return $this->is_admin
			? $this->url(RouteName::ADMIN_QUARANTINE_DAY, ['date' => $date])
			: $this->url(RouteName::QUARANTINE_DAY, ['date' => $date]);
	}

	private function getQuarantineMonthUrl(string $month): string {
		return $this->is_admin
			? $this->url(RouteName::ADMIN_QUARANTINE_MONTH, ['month' => $month])
			: $this->url(RouteName::QUARANTINE_MONTH, ['month' => $month]);
	}

}
