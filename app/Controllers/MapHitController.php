<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Controllers;

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

	// the mails behind one count of the report: ?map= or ?symbol=, and ?days=
	public function showMails(): Response {
		if ($denied = $this->checkAccess()) {
			return $denied;
		}

		$this->twigFormView($this->request);

		$qidform = QidForm::create($this->formFactory, $this->request);
		if ($response = QidForm::check_form($qidform, $this->urlGenerator, $this->is_admin)) {
			return $response;
		}

		$report = $this->url(RouteName::ADMIN_MAP_HITS);

		$days = $this->request->query->getInt('days', max(MapHitService::WINDOWS));
		if (!in_array($days, MapHitService::WINDOWS, true)) {
			return new RedirectResponse($report);
		}

		$map = (string) $this->request->query->get('map', '');
		$symbol = (string) $this->request->query->get('symbol', '');
		$maps = $this->maps();
		$service = new MapHitService();

		try {
			if ($map !== '' && isset($maps[$map])) {
				[$model, $symbols] = $maps[$map];
				$ids = $service->mapHitMailIds($map, $model, $symbols, $days, $this->max_items);
				$configs = MapInventory::getMapConfigs();
				$filter = ['filter' => 'Map', 'choice' => 'hit by', 'value' => $configs[$map]['description'] ?? $map];
				$params = ['map' => $map, 'days' => $days];
			} elseif ($symbol !== '' && MapService::validSymbol($symbol)) {
				$ids = $service->symbolHitMailIds($symbol, $days, $this->max_items);
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
			->getPaginatedByIds($ids, $this->url(RouteName::ADMIN_MAP_HITS_MAILS), $page);

		$period = $days === 1 ? 'today' : "the last {$days} days";

		return new Response($this->twig->render('home_paginated.twig', [
			'qidform' => $qidform->createView(),
			'logs' => $logs,
			'filters' => [$filter, ['filter' => 'Date', 'choice' => 'in', 'value' => $period]],
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

	private function checkAccess(): ?RedirectResponse {
		if (!$this->is_admin) {
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
