<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Controllers;

use App\Charts\ChartBuilder;
use App\Configuration\Config;
use App\Core\Routing\RouteName;
use App\Forms\QidForm;

use App\Inventory\MapInventory;

use App\Services\MailLogService;
use App\Services\MapService;
use App\Services\MapHitService;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

use Throwable;

class MapHitController extends ViewController
{
	// per map and per uncredited symbol: distinct mails today, in 7 and in 31 days
	public function showReport(): Response {
		if ($denied = $this->checkAccess()) {
			return $denied;
		}

		$this->twigFormView($this->request);

		$qidform = QidForm::create($this->formFactory, $this->request);
		if ($response = QidForm::check_form($qidform, $this->urlGenerator, $this->is_admin)) {
			return $response;
		}

		$maps = $this->maps();
		$service = new MapHitService();

		try {
			$hits = $service->mapHits($maps);
			$unmapped = $service->unmappedHits(array_merge([], ...array_column($maps, 1)));
		} catch (Throwable $e) {
			$this->fileLogger->error("[MapHitController_showReport] query error: " . $e->getMessage());
			$this->flashbag->add('error', "Map hits query error. Check logs.");
			return new RedirectResponse($this->url(RouteName::ADMIN_MAPS));
		}

		$configs = MapInventory::getMapConfigs();
		$longest = max(MapHitService::WINDOWS);

		$rows = [];
		foreach ($maps as $map => [$model, $symbols]) {
			$rows[] = [
				'map' => $map,
				'description' => $configs[$map]['description'] ?? $map,
				'model' => $model,
				'symbols' => $symbols,
				'windows' => $hits[$map]['windows'],
				'last' => $hits[$map]['last'],
			];
		}
		// busiest first, idle maps last
		usort($rows, fn ($a, $b) => [$b['windows'][$longest], $a['map']] <=> [$a['windows'][$longest], $b['map']]);
		uasort($unmapped, fn ($a, $b) => $b['windows'][$longest] <=> $a['windows'][$longest]);

		return new Response($this->twig->render('map_hits.twig', [
			'qidform' => $qidform->createView(),
			'rows' => $rows,
			'unmapped' => $unmapped,
			'windows' => MapHitService::WINDOWS,
			'longest' => $longest,
			'runtime' => $this->getRuntime(),
			'flashes' => $this->getFlashes(),
			'is_admin' => $this->is_admin,
			'username' => $this->username,
			'auth_provider' => $this->session->get('auth_provider'),
			'current_route' => $this->request->getPathInfo(),
			'rspamd_stats' => $this->getRspamdStat(),
		]));
	}

	/*
	 * The mails behind one count: ?map= (with ?entry= for one entry, the only
	 * form open to users, for entries they can see) or ?symbol=, and ?days=
	 */
	public function showMails(): Response {
		$entry = $this->request->query->getInt('entry', 0);
		if ($denied = $this->checkAccess($entry > 0)) {
			return $denied;
		}

		$this->twigFormView($this->request);

		$qidform = QidForm::create($this->formFactory, $this->request);
		if ($response = QidForm::check_form($qidform, $this->urlGenerator, $this->is_admin)) {
			return $response;
		}

		$report = $this->is_admin ? $this->url(RouteName::ADMIN_MAP_HITS) : $this->getHomepageUrl();

		// 0: every recorded hit, as the detail page links a mail's entry
		$days = $this->request->query->getInt('days', max(MapHitService::WINDOWS));
		if ($days !== 0 && !in_array($days, MapHitService::WINDOWS, true)) {
			return new RedirectResponse($report);
		}
		$window = $days === 0 ? null : $days;

		$map = (string) $this->request->query->get('map', '');
		$symbol = (string) $this->request->query->get('symbol', '');
		$maps = $this->maps();
		$service = new MapHitService();
		$outcomeFilters = [];
		$period = null;

		try {
			if ($map !== '' && $entry > 0) {
				$config = MapInventory::getAvailableMapConfigs($this->getRole(), $map);
				$found = $config ? (new MapService($this->getUserContext()))->findMapEntry($config['model'], $map, $entry) : null;
				if ($found === null) {
					return new RedirectResponse($report);
				}
				$ids = $service->entryHitMailIds(MapHitService::entryModel($config['model']), $entry, $window, $this->max_items);
				$key = $config['model'] === 'MapCustom'
					? (string) $found->getAttribute('pattern')
					: implode(' | ', array_map(fn ($f) => (string) $found->getAttribute($f), $config['fields']));
				$filter = ['filter' => 'Map entry', 'choice' => 'of ' . $config['description'], 'value' => $key];
				$params = ['map' => $map, 'entry' => $entry, 'days' => $days];
			} elseif (!$this->is_admin) {
				return new RedirectResponse($report);
			} elseif ($map !== '' && isset($maps[$map])) {
				[$model, $symbols] = $maps[$map];
				[$outcome, $outcomeFilters, $outcomeParams] = $this->outcomeQuery();
				// one day of the trend chart
				$day = (string) $this->request->query->get('day', '');
				$day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) && strtotime($day) !== false ? $day : null;
				$ids = $service->mapHitMailIds($map, $model, $symbols, $window, $this->max_items, $outcome, $day);
				if ($day !== null) {
					$period = "the day {$day}";
					$outcomeParams['day'] = $day;
				}
				$configs = MapInventory::getMapConfigs();
				$filter = ['filter' => 'Map', 'choice' => 'hit by', 'value' => $configs[$map]['description'] ?? $map];
				$params = ['map' => $map, 'days' => $days] + $outcomeParams;
			} elseif ($symbol !== '' && MapService::validSymbol($symbol)) {
				$ids = $service->symbolHitMailIds($symbol, $window, $this->max_items);
				$filter = ['filter' => 'Symbol', 'choice' => 'not credited to a map', 'value' => $symbol];
				$params = ['symbol' => $symbol, 'days' => $days];
			} else {
				return new RedirectResponse($report);
			}
		} catch (Throwable $e) {
			$this->fileLogger->error("[MapHitController_showMails] query error: " . $e->getMessage());
			$this->flashbag->add('error', "Map hits query error. Check logs.");
			return new RedirectResponse($report);
		}

		$page = $this->request->query->getInt('page', 1);
		$logs = (new MailLogService($this->getUserContext()))
			->getPaginatedByIds($ids, $this->url($this->is_admin ? RouteName::ADMIN_MAP_HITS_MAILS : RouteName::MAP_HITS_MAILS), $page);

		$period ??= match ($days) {
			0 => 'any recorded day',
			1 => 'today',
			default => "the last {$days} days",
		};

		return new Response($this->twig->render('home_paginated.twig', [
			'qidform' => $qidform->createView(),
			'logs' => $logs,
			'filters' => array_merge([$filter], $outcomeFilters, [['filter' => 'Date', 'choice' => 'in', 'value' => $period]]),
			'queryParams' => $params,
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

	/*
	 * A map's mails per action, released and notified, in one window: the
	 * report's outcome link
	 */
	public function showOutcome(): Response {
		if ($denied = $this->checkAccess()) {
			return $denied;
		}

		$this->twigFormView($this->request);

		$qidform = QidForm::create($this->formFactory, $this->request);
		if ($response = QidForm::check_form($qidform, $this->urlGenerator, $this->is_admin)) {
			return $response;
		}

		$report = $this->url(RouteName::ADMIN_MAP_HITS);
		$map = (string) $this->request->query->get('map', '');
		$days = $this->request->query->getInt('days', max(MapHitService::WINDOWS));
		$maps = $this->maps();
		if (!isset($maps[$map]) || !in_array($days, MapHitService::WINDOWS, true)) {
			return new RedirectResponse($report);
		}

		[$model, $symbols] = $maps[$map];
		$service = new MapHitService();
		$chart = null;
		try {
			$outcome = $service->mapOutcome($map, $model, $symbols, $days);
			// the trend always spans the longest window
			if (Config::get('show_charts')) {
				$mails = $this->url(RouteName::ADMIN_MAP_HITS_MAILS);
				$chart = ChartBuilder::createMapHitsChart(
					$service->mapDailyHits($map, $model, $symbols, max(MapHitService::WINDOWS)),
					fn (string $day) => $mails . '?' . http_build_query(['map' => $map, 'day' => $day])
				);
			}
		} catch (Throwable $e) {
			$this->fileLogger->error("[MapHitController_showOutcome] query error: " . $e->getMessage());
			$this->flashbag->add('error', "Map hits query error. Check logs.");
			return new RedirectResponse($report);
		}

		$totals = ['mails' => 0, 'released' => 0, 'notified' => 0];
		foreach ($outcome as $counts) {
			foreach ($totals as $k => $v) {
				$totals[$k] = $v + $counts[$k];
			}
		}

		return new Response($this->twig->render('map_hits_outcome.twig', [
			'qidform' => $qidform->createView(),
			'map' => $map,
			'description' => MapInventory::getMapConfigs()[$map]['description'] ?? $map,
			'symbols' => $symbols,
			'days' => $days,
			'windows' => MapHitService::WINDOWS,
			'outcome' => $outcome,
			'totals' => $totals,
			'chart' => $chart,
			'show_charts' => $chart !== null,
			'runtime' => $this->getRuntime(),
			'flashes' => $this->getFlashes(),
			'is_admin' => $this->is_admin,
			'username' => $this->username,
			'auth_provider' => $this->session->get('auth_provider'),
			'current_route' => $this->request->getPathInfo(),
			'rspamd_stats' => $this->getRspamdStat(),
		]));
	}

	/*
	 * The outcome filters of a map's mails list (?action=, ?released=1,
	 * ?notified=1), as [service filters, Active Filters lines, query params]
	 */
	private function outcomeQuery(): array {
		$outcome = [];
		$filters = [];
		$params = [];

		$action = (string) $this->request->query->get('action', '');
		if ($action !== '' && preg_match('/^[a-z ]{1,20}$/', $action)) {
			$outcome['action'] = $action;
			$filters[] = ['filter' => 'Action', 'choice' => 'is', 'value' => $action];
			$params['action'] = $action;
		}
		foreach (['released' => 'Released', 'notified' => 'Notified'] as $key => $label) {
			if ($this->request->query->get($key) === '1') {
				$outcome[$key] = true;
				$filters[] = ['filter' => $label, 'choice' => 'is', 'value' => 'yes'];
				$params[$key] = 1;
			}
		}

		return [$outcome, $filters, $params];
	}

	// admin only, unless $entry: one entry's mails, which users may see
	private function checkAccess(bool $entry = false): ?RedirectResponse {
		if (!$this->is_admin && !$entry) {
			$this->fileLogger->warning("'{$this->username}' tried to show map hits without admin authorization");
			$this->flashbag->add('error', "Permission denied");
			return new RedirectResponse($this->getHomepageUrl());
		}

		if (!MapService::mapHitsEnabled()) {
			$this->flashbag->add('error', "Map hits need the map hits migration (db:migrate_map_hits)");
			return new RedirectResponse($this->getHomepageUrl());
		}

		return null;
	}

	// every map, as map name => [model, symbols]
	private function maps(): array {
		$symbols = MapService::mapSymbols();

		$maps = [];
		foreach (MapInventory::getMapConfigs() as $map => $config) {
			$maps[$map] = [$config['model'], $symbols[$map] ?? []];
		}
		return $maps;
	}

}
