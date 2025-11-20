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
use App\Utils\Helper;

use App\Forms\QidForm;
use App\Forms\MapSelectForm;
use App\Forms\CustomMapConfigForm;
use App\Forms\MapSearchForm;

use App\Inventory\MapInventory;
use App\Services\MapService;

use App\Models\MapCombined;
//use App\Models\MapGeneric;
use App\Models\MapCustom;
use App\Models\CustomMapConfig;
use App\Models\MapActivityLog;
use App\Models\User;
use App\Models\MailAlias;

class MapController extends ViewController
{
	protected int $refresh_rate;
	protected int $items_per_page;
	protected int $max_items;

	protected bool $mapUrlsInitialized = false;
	protected string $mapsUrl;
	protected string $mapShowUrl;
	protected string $mapShowAllUrl;
	protected string $mapShowAllCustomUrl;
	protected string $mapAddEntryUrl;
	protected string $showCustomMapsConfigUrl;
	protected string $mapsCustomAddUrl;
	protected string $mapSearchEntryUrl;

	public function __construct() {
	//	parent::__construct();

		$this->refresh_rate = Config::get('refresh_rate');
		$this->items_per_page = Config::get('items_per_page');
		$this->max_items = Config::get('max_items');

		$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
		$this->maps_url_base = sprintf('%s://%s/maps/', $scheme, $_ENV['WEB_HOST']);
	}

	public function initMapUrls(?string $map = null): void {
		if (!empty($map)) {
			if ($this->getIsAdmin()) {
				$this->mapShowUrl = $this->url(RouteName::ADMIN_MAP_SHOW, [ 'map' => $map ]);
				$this->mapAddEntryUrl = $this->url(RouteName::ADMIN_MAP_ADD_ENTRY, [ 'map' => $map ]);
			} else {
				$this->mapShowUrl = $this->url(RouteName::MAP_SHOW, [ 'map' => $map ]);
				$this->mapAddEntryUrl = $this->url(RouteName::MAP_ADD_ENTRY, [ 'map' => $map ]);
			}
		}

		if ($this->mapUrlsInitialized) {
			return;
		}

		if (!$this->urlsInitialized) {
			$this->initUrls();
		}

		if ($this->getIsAdmin()) {
			$this->mapsUrl = $this->url(RouteName::ADMIN_MAPS);
			$this->mapShowAllUrl = $this->url(RouteName::ADMIN_MAP_SHOW_ALL);
			$this->mapShowAllCustomUrl = $this->url(RouteName::ADMIN_MAP_SHOW_ALL, ['model' => 'MapCustom']);
			$this->showCustomMapsConfigUrl = $this->url(RouteName::ADMIN_MAPS_CUSTOM_SHOW);
			$this->mapsCustomAddUrl = $this->url(RouteName::ADMIN_MAPS_CUSTOM_ADD);
			$this->mapSearchEntryUrl = $this->url(RouteName::ADMIN_MAP_SEARCH_ENTRY);
		} else {
			$this->mapsUrl = $this->url(RouteName::MAPS);
			$this->mapShowAllUrl = $this->url(RouteName::MAP_SHOW_ALL);
		}

		$this->mapUrlsInitialized = true;
   }

	public function showSelectMap(): Response {
		// enable form rendering support
		$this->twigFormView($this->request);

		// generate and handle qid form
		$qidform = QidForm::create($this->formFactory, $this->request);
		if ($response = QidForm::check_form($qidform, $this->urlGenerator, $this->is_admin)) {
			// form submitted and valid
			return $response;
		}

		/* old code
		$options = [
			'role' => $this->getRole(),
			'model' => 'MapCombined',
			'form_name' => 'map_combined_form',
		];

		$mapCombinedSelectForm = MapSelectForm::create($this->formFactory, $this->request, null, $options);
		if ($response = MapSelectForm::check_form_show($mapCombinedSelectForm, $this->urlGenerator, $this->is_admin)) {
			// form submitted and valid
			return $response;
		}

		$options['model'] = 'MapGeneric';
		$options['form_name'] = 'map_generic_form';
		$mapGenericSelectForm = MapSelectForm::create($this->formFactory, $this->request, null, $options);
		if ($response = MapSelectForm::check_form_show($mapGenericSelectForm, $this->urlGenerator, $this->is_admin)) {
			// form submitted and valid
			return $response;
		}
		*/

		[$mapCombinedSelectForm, $response] = $this->handleMapSelectForm('MapCombined');
		if ($response !== null) {
			return $response;
		}
		/* deprecated
		[$mapGenericSelectForm, $response] = $this->handleMapSelectForm('MapGeneric');
		if ($response !== null) {
			return $response;
		}
		*/
		[$mapCustomSelectForm, $response] = $this->handleMapSelectForm('MapCustom');
		if ($response !== null) {
			return $response;
		}

		return new Response($this->twig->render('map_select.twig', [
			'qidform' => $qidform->createView(),
			'mapselectform' => $mapCombinedSelectForm->createView(),
			//'mapselectgenericform' => $mapGenericSelectForm->createView(),
			'mapselectcustomform' => $mapCustomSelectForm->createView(),
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

	// Show all entries for both MapCombined/MapCustom
	public function showAllMaps(?string $model = null): Response {
		// enable form rendering support
		$this->twigFormView($this->request);

		// generate and handle qid form
		$qidform = QidForm::create($this->formFactory, $this->request);
		if ($response = QidForm::check_form($qidform, $this->urlGenerator, $this->is_admin)) {
			// form submitted and valid
			return $response;
		}

		[$mapCombinedSelectForm, $response] = $this->handleMapSelectForm('MapCombined');
		if ($response !== null) {
			return $response;
		}
		/* deprecated
		[$mapGenericSelectForm, $response] = $this->handleMapSelectForm('MapGeneric');
		if ($response !== null) {
			return $response;
		}
		*/
		[$mapCustomSelectForm, $response] = $this->handleMapSelectForm('MapCustom');
		if ($response !== null) {
			return $response;
		}

		$page = $this->request->query->getInt('page', 1);

		$service = new MapService($this->getFileLogger(), $this->session);

		$configs = MapInventory::getAvailableMapConfigs($this->getRole()) ?? null;

		$field_definitions = MapInventory::getFieldDefinitions() ?? null;
		$field_descriptions = [];
		foreach ($field_definitions as $field => $definition) {
			$field_descriptions[$field] = $definition['description'];
		}

		$this->initMapUrls();

		if ($this->getIsAdmin() and $model === 'MapCustom') {
			$map_comb_entries = null;
			$map_comb_total = null;
			$map_gen_entries = null;
			$map_gen_total = null;
			$filter_maps = null;
			$map_custom_entries = $service->showPaginatedAllMapCustom($page, $this->mapShowAllCustomUrl);
			$map_custom_total = $map_custom_entries->total();

			if (empty($map_custom_entries)) {
				$this->flashbag->add('info', 'No map entries exist');
				return new RedirectResponse($this->mapsUrl);
			}
			foreach ($map_custom_entries as $key => $map_entry) {
				// add map description
				$field = $configs[$map_entry->map_name]['fields'][0];
				$map_name = $map_entry->map_name;
				$field_description = MapService::getCustomField($map_name)['field_label'];
				$map_custom_entries[$key]->map_description = $configs[$map_entry->map_name]['description'];
				$map_custom_entries[$key]->field = $field;
				$map_custom_entries[$key]->field_description = $field_description;
			}
		} else {
			$model = 'MapCombined';
			$map_gen_entries = null;
			$map_gen_total = null;
			$map_custom_entries = null;
			$map_custom_total = null;
			$filter_maps = MapInventory::getMapsByModel($model, $configs);

			// has applyUserRcptToScope and filter maps on model
			$map_comb_entries = $service->showPaginatedAllMapCombined($page, $this->mapShowAllUrl, $filter_maps);

			if (empty($map_comb_entries)) {
				$this->flashbag->add('info', 'No map entries exist');
				return new RedirectResponse($this->mapsUrl);
			}

			foreach ($map_comb_entries as $key => $map_entry) {
				// add map description
				$map_comb_entries[$key]->map_description = $configs[$map_entry->map_name]['description'];
				$map_comb_entries[$key]->map_username = $this->getMapUser($map_entry->user);
				$map_comb_entries[$key]->user_can_delete = $this->getUserCanDelete($this->username, $map_comb_entries[$key]->map_username);
			}
			$map_comb_total = $map_comb_entries->total();
		}

		$sf_data = [ 'model' => $model ];
		$mapSearchForm = MapSearchForm::create($this->formFactory, $this->request, $this->urlGenerator, $sf_data);

		return new Response($this->twig->render('maps_all_paginated.twig', [
			'qidform' => $qidform->createView(),
			'mapsearchform' => $mapSearchForm->createView(),
			'mapselectform' => $mapCombinedSelectForm->createView(),
			//'mapselectgenericform' => $mapGenericSelectForm->createView(),
			'mapselectcustomform' => $mapCustomSelectForm->createView(),
			'map_comb_entries' => $map_comb_entries,
			'map_comb_total' => $map_comb_total,
			'map_gen_entries' => $map_gen_entries,
			'map_gen_total' => $map_gen_total,
			'map_custom_entries' => $map_custom_entries,
			'map_custom_total' => $map_custom_total,
			'field_descriptions' => $field_descriptions,
			'items_per_page' => $this->items_per_page,
			'runtime' => $this->getRuntime(),
			'flashes' => $this->flashbag->all(),
			'is_admin' => $this->session->get('is_admin'),
			'username' => $this->session->get('username'),
			'auth_provider' => $this->session->get('auth_provider'),
			'current_route' => $this->request->getPathInfo(),
			'rspamd_stats' => $this->getRspamdStat(),
			'maps_url_base' => $this->getMapsUrlBase(),
		]));
	}

	public function showCustomMapsConfig(): Response {
		// enable form rendering support
		$this->twigFormView($this->request);

		// generate and handle qid form
		$qidform = QidForm::create($this->formFactory, $this->request);
		if ($response = QidForm::check_form($qidform, $this->urlGenerator, $this->is_admin)) {
			// form submitted and valid
			return $response;
		}

		[$mapCombinedSelectForm, $response] = $this->handleMapSelectForm('MapCombined');
		if ($response !== null) {
			return $response;
		}
		/* deprecated
		[$mapGenericSelectForm, $response] = $this->handleMapSelectForm('MapGeneric');
		if ($response !== null) {
			return $response;
		}
		*/
		[$mapCustomSelectForm, $response] = $this->handleMapSelectForm('MapCustom');
		if ($response !== null) {
			return $response;
		}

		$service = new MapService($this->getFileLogger(), $this->session);

		$page = $this->request->query->getInt('page', 1);

		$this->initMapUrls();

		$map_configs = $service->showPaginatedCustomMapConfigs($page, $this->showCustomMapsConfigUrl);

		foreach ($map_configs as $key => $map_config) {
			$map_configs[$key]['map_entries'] = $map_config->MapsCustom->count();
		}

		return new Response($this->twig->render('maps_custom_config.twig', [
			'qidform' => $qidform->createView(),
			'mapselectform' => $mapCombinedSelectForm->createView(),
			//'mapselectgenericform' => $mapGenericSelectForm->createView(),
			'mapselectcustomform' => $mapCustomSelectForm->createView(),
			'map_configs' => $map_configs,
			'totalRecords' => $map_configs->total(),
			'items_per_page' => $this->items_per_page,
			'runtime' => $this->getRuntime(),
			'flashes' => $this->flashbag->all(),
			'is_admin' => $this->session->get('is_admin'),
			'username' => $this->session->get('username'),
			'auth_provider' => $this->session->get('auth_provider'),
			'current_route' => $this->request->getPathInfo(),
			'rspamd_stats' => $this->getRspamdStat(),
			'maps_url_base' => $this->getMapsUrlBase(),
		]));
	}

	public function addCustomMap(): Response {
		// enable form rendering support
		$this->twigFormView($this->request);

		// generate and handle qid form
		$qidform = QidForm::create($this->formFactory, $this->request);
		if ($response = QidForm::check_form($qidform, $this->urlGenerator, $this->is_admin)) {
			// form submitted and valid
			return $response;
		}

		[$mapCombinedSelectForm, $response] = $this->handleMapSelectForm('MapCombined');
		if ($response !== null) {
			return $response;
		}
		/* deprecated
		[$mapGenericSelectForm, $response] = $this->handleMapSelectForm('MapGeneric');
		if ($response !== null) {
			return $response;
		}
		*/
		[$mapCustomSelectForm, $response] = $this->handleMapSelectForm('MapCustom');
		if ($response !== null) {
			return $response;
		}

		$mapform = CustomMapConfigForm::create($this->formFactory, $this->request);

		$this->initMapUrls();

		if ($mapform->isSubmitted() && $mapform->isValid()) {
			$data = $mapform->getData();
			if (empty($data['map_name'])) {
				$this->flashbag->add('error', "Map Name empty");
				return new RedirectResponse($this->mapsCustomAddUrl);
			}
			$data['map_name'] = strtolower(trim($data['map_name']));

			if (empty($data['field_name'])) {
				$this->flashbag->add('error', "Field Name empty");
				return new RedirectResponse($this->mapsCustomAddUrl);
			}
			$data['field_name'] = trim($data['field_name']);
			$data['map_description'] = trim($data['map_description']);
			$data['field_label'] = trim($data['field_label']);

			$service = new MapService($this->getFileLogger(), $this->session);
			$model = 'MapCustom';

			$map_name = $data['map_name'];
			if ($map_name === 'manage_custom_maps') {
				$this->flashbag->add('error', "Map name '{$map_name}' is not allowed!");
				return new RedirectResponse($this->mapsCustomAddUrl);
			}
			if ($service->mapExists($map_name)) {
				$this->flashbag->add('error', "Map '{$map_name}' already exists!");
				return new RedirectResponse($this->mapsCustomAddUrl);
			}

			// add entry
			if ($service->addCustomMapConfig($data)) {
				$this->fileLogger->info("Custom map '{$map_name}' created by '{$this->email}'");
				$this->flashbag->add('success', "Custom Map '{$data['map_name']}' created");
				return new RedirectResponse($this->showCustomMapsConfigUrl);
			} else {
				$this->flashbag->add('error', "Custom map '{$map_name}' creation problem. Check logs.");
				return new RedirectResponse($this->mapsCustomAddUrl);
			}
		}

		return new Response($this->twig->render('maps_custom_config_add.twig', [
			'qidform' => $qidform->createView(),
			'mapselectform' => $mapCombinedSelectForm->createView(),
			//'mapselectgenericform' => $mapGenericSelectForm->createView(),
			'mapselectcustomform' => $mapCustomSelectForm->createView(),
			'items_per_page' => $this->items_per_page,
			'mapform' => $mapform->createView(),
			'runtime' => $this->getRuntime(),
			'flashes' => $this->flashbag->all(),
			'is_admin' => $this->session->get('is_admin'),
			'username' => $this->session->get('username'),
			'auth_provider' => $this->session->get('auth_provider'),
			'current_route' => $this->request->getPathInfo(),
			'rspamd_stats' => $this->getRspamdStat(),
		]));
	}

	public function editCustomMap(int $id): Response {
		$this->initMapUrls();

		if (empty($id) || !is_int($id)) {
			$this->flashbag->add('error', 'Invalid map id');
			return new RedirectResponse($this->showCustomMapsConfigUrl);
		}

		// enable form rendering support
		$this->twigFormView($this->request);

		// generate and handle qid form
		$qidform = QidForm::create($this->formFactory, $this->request);
		if ($response = QidForm::check_form($qidform, $this->urlGenerator, $this->is_admin)) {
			// form submitted and valid
			return $response;
		}

		[$mapCombinedSelectForm, $response] = $this->handleMapSelectForm('MapCombined');
		if ($response !== null) {
			return $response;
		}
		[$mapCustomSelectForm, $response] = $this->handleMapSelectForm('MapCustom');
		if ($response !== null) {
			return $response;
		}

		$customMapConfig = CustomMapConfig::find($id)->toArray();

		$mapform = CustomMapConfigForm::create($this->formFactory, $this->request, $customMapConfig);

		$this->initMapUrls();

		if ($mapform->isSubmitted() && $mapform->isValid()) {
			$data = $mapform->getData();
			if (empty($data['map_name'])) {
				$this->flashbag->add('error', "Map Name empty");
				return new RedirectResponse($this->mapsCustomAddUrl);
			}
			$data['map_name'] = strtolower(trim($data['map_name']));

			if (empty($data['field_name'])) {
				$this->flashbag->add('error', "Field Name empty");
				return new RedirectResponse($this->mapsCustomAddUrl);
			}
			$data['field_name'] = trim($data['field_name']);
			$data['map_description'] = trim($data['map_description']);
			$data['field_label'] = trim($data['field_label']);

			$service = new MapService($this->getFileLogger(), $this->session);
			$model = 'MapCustom';

			$edit_url = $this->url(RouteName::ADMIN_MAPS_CUSTOM_EDIT, [ 'id' => $id ]);

			$map_name = $data['map_name'];
			if ($map_name === 'manage_custom_maps') {
				$this->flashbag->add('error', "Map name '{$map_name}' is not allowed!");
				return new RedirectResponse($edit_url);
			}

			// map_name change
			if ($customMapConfig['map_name'] !== $map_name) {
				// check if new name exists
				if ($service->mapExists($map_name)) {
					$this->flashbag->add('error', "Map '{$map_name}' already exists!");
					return new RedirectResponse($edit_url);
				}
			}

			// update entry
			if ($service->updateCustomMapConfig($data)) {
				$this->fileLogger->info("Custom map '{$map_name}' updated by '{$this->email}'");
				$this->flashbag->add('success', "Custom Map '{$data['map_name']}' updated");
				return new RedirectResponse($this->showCustomMapsConfigUrl);
			} else {
				$this->flashbag->add('error', "Custom map '{$map_name}' update problem. Check logs.");
				return new RedirectResponse($edit_url);
			}
		}

		return new Response($this->twig->render('maps_custom_config_add.twig', [
			'qidform' => $qidform->createView(),
			'mapselectform' => $mapCombinedSelectForm->createView(),
			//'mapselectgenericform' => $mapGenericSelectForm->createView(),
			'mapselectcustomform' => $mapCustomSelectForm->createView(),
			'items_per_page' => $this->items_per_page,
			'mapform' => $mapform->createView(),
			'runtime' => $this->getRuntime(),
			'flashes' => $this->flashbag->all(),
			'is_admin' => $this->session->get('is_admin'),
			'username' => $this->session->get('username'),
			'auth_provider' => $this->session->get('auth_provider'),
			'current_route' => $this->request->getPathInfo(),
			'rspamd_stats' => $this->getRspamdStat(),
		]));
	}

	// works for all maps (MapCombined/MapCustom)
	public function showMap(string $map): Response {
		// Custom map management link, comes from map select form
		if ($map === 'manage_custom_maps') {
			return new RedirectResponse($this->url(RouteName::ADMIN_MAPS_CUSTOM_SHOW));
		}

		// enable form rendering support
		$this->twigFormView($this->request);

		// generate and handle qid form
		$qidform = QidForm::create($this->formFactory, $this->request);
		if ($response = QidForm::check_form($qidform, $this->urlGenerator, $this->is_admin)) {
			// form submitted and valid
			return $response;
		}

		[$mapCombinedSelectForm, $response] = $this->handleMapSelectForm('MapCombined');
		if ($response !== null) {
			return $response;
		}
		/* deprecated
		[$mapGenericSelectForm, $response] = $this->handleMapSelectForm('MapGeneric');
		if ($response !== null) {
			return $response;
		}
		*/
		[$mapCustomSelectForm, $response] = $this->handleMapSelectForm('MapCustom');
		if ($response !== null) {
			return $response;
		}

		if (empty($map)) {
			$this->flashbag->add('error', 'No map selected');
			return new RedirectResponse($this->mapsUrl);
		}

		$this->initMapUrls($map);

		// Fetch config for the selected map
		$config = MapInventory::getAvailableMapConfigs($this->getRole(), $map) ?? null;

		if (!$config || !array_key_exists('fields', $config)) {
			$this->flashbag->add('error', 'Invalid map selected');
			return new RedirectResponse($this->mapsUrl);
		}

		$fields = $config['fields'];
		$descriptions = [];

		// get field description for custom map from db
		if ($config['model'] === 'MapCustom') {
			$descriptions[] = MapService::getCustomField($map)['field_label'];
		}
		// use local inventory
		else {
			foreach ($fields as $field) {
				$descriptions[] = MapInventory::getFieldDefinitions($field)['description'];
			}
		}

		$descriptions[] = 'Created';
		$mapdescr = $config['description'];
		$service = new MapService($this->getFileLogger(), $this->session);

		// without pagination
		// has applyUserRcptToScope
		//$map_entries = $service->showMapCombined($map, $fields);

		$page = $this->request->query->getInt('page', 1);

		$this->initMapUrls($map);

		if($config['model'] === 'MapCombined') {
			$model = 'MapCombined';
			// has applyUserRcptToScope
			$map_entries = $service->showPaginatedMapCombined($map, $fields, $page, $this->mapShowUrl);

			foreach ($map_entries as $key => $map_entry) {
				$map_entries[$key]->map_username = $this->getMapUser($map_entry->user);
				$map_entries[$key]->user_can_delete = $this->getUserCanDelete($this->username, $map_entries[$key]->map_username);
			}
		/* deprecated
		} elseif($this->getIsAdmin() && $config['model'] === 'MapGeneric') {
			$model = 'MapGeneric';
			// without pagination
			//$map_entries = $service->showMapGeneric($map);
			$map_entries = $service->showPaginatedMapGeneric($map, $page, $this->mapShowUrl);
		*/
		} elseif($this->getIsAdmin() && $config['model'] === 'MapCustom') {
			$model = 'MapCustom';
			// without pagination
			//$map_entries = $service->showMapCustom($map);
			$map_entries = $service->showPaginatedMapCustom($map, $page, $this->mapShowUrl);
		} else {
			$this->fileLogger->warning("User {$this->username} tried to show map in " . $this->request->getPathInfo() . " with wrong model {$config['model']} or non admin rights");
			$this->flashbag->add('error', 'Error in map');
			return new RedirectResponse($this->mapsUrl);
		}

		$sf_data = [
			'model' => $model,
			'map_name' => $map,
		];
		$mapSearchForm = MapSearchForm::create($this->formFactory, $this->request, $this->urlGenerator, $sf_data);

		$last_activity = (string) MapActivityLog::where('map_name', $map)->value('last_changed_at');

		return new Response($this->twig->render('map_paginated.twig', [
			'qidform' => $qidform->createView(),
			'mapsearchform' => $mapSearchForm->createView(),
			'mapselectform' => $mapCombinedSelectForm->createView(),
			//'mapselectgenericform' => $mapGenericSelectForm->createView(),
			'mapselectcustomform' => $mapCustomSelectForm->createView(),
			'map' => $map,
			'model' => $model,
			'mapdescr' => $mapdescr,
			'fields' => $fields,
			'descriptions' => $descriptions,
			'map_entries' => $map_entries,
			'totalRecords' => $map_entries->total(),
			'last_activity' => $last_activity,
			'items_per_page' => $this->items_per_page,
			'runtime' => $this->getRuntime(),
			'flashes' => $this->flashbag->all(),
			'is_admin' => $this->session->get('is_admin'),
			'username' => $this->session->get('username'),
			'auth_provider' => $this->session->get('auth_provider'),
			'current_route' => $this->request->getPathInfo(),
			'rspamd_stats' => $this->getRspamdStat(),
			'maps_url_base' => $this->getMapsUrlBase(),
		]));
	}

	// works for both MapCombined/MapCustom
	public function addMapEntry(string $map): Response {
		// enable form rendering support
		$this->twigFormView($this->request);

		// generate and handle qid form
		$qidform = QidForm::create($this->formFactory, $this->request);
		if ($response = QidForm::check_form($qidform, $this->urlGenerator, $this->is_admin)) {
			// form submitted and valid
			return $response;
		}

		[$mapCombinedSelectForm, $response] = $this->handleMapSelectForm('MapCombined');
		if ($response !== null) {
			return $response;
		}
		/* deprecated
		[$mapGenericSelectForm, $response] = $this->handleMapSelectForm('MapGeneric');
		if ($response !== null) {
			return $response;
		}
		*/
		[$mapCustomSelectForm, $response] = $this->handleMapSelectForm('MapCustom');
		if ($response !== null) {
			return $response;
		}

		// needed to mapsUrl
		$this->initMapUrls();

		if (empty($map)) {
			$this->flashbag->add('error', 'No map selected');
			return new RedirectResponse($this->mapsUrl);
		}

		// redo with map
		$this->initMapUrls($map);

		// Fetch config for the selected map
		//$config = MapInventory::getMapConfigs($map) ?? null;
		$config = MapInventory::getAvailableMapConfigs($this->getRole(), $map) ?? null;

		if (!$config || !array_key_exists('map_form', $config)) {
			$this->fileLogger->warning("User {$this->username} tried to add map entry in " . $this->request->getPathInfo() . " without admin authorization");
			$this->flashbag->add('error', 'Invalid map selected');
			return new RedirectResponse($this->mapsUrl);
		}

		$mapdescr = $config['description'];
		$fields = $config['fields'] ?? [];
		$data = [];

		foreach ($fields as $field) {
			$value = $this->request->get($field); // Supports both GET and POST
			if ($value !== null) {
				$data[$field] = $value;
			}
		}

		// Dynamically call the correct form class's `create()` method
		$mapFormClass = $config['map_form'];
		if (!is_callable([$mapFormClass, 'create'])) {
			throw new \RuntimeException("Form class $mapFormClass does not have a static create() method");
		}

		$options = [
			'role' => $this->getRole(),
		];

		if ($config['model'] === 'MapCustom') {
			$options['map'] = $map;
		}

		if (!$this->getIsAdmin()) {
			// override user form fields. rcpt_to drop down based on user email and aliases
			$options = [
				'user_emails' => $this->getUserEmailAddresses(),
			];
		}
		$mapform = $mapFormClass::create($this->formFactory, $this->request, $data, $options);

		if ($mapform->isSubmitted() && $mapform->isValid()) {
			$data = $mapform->getData();

			if (empty($data)) {
				$this->flashbag->add('error', "Empty map data");
				return new RedirectResponse($this->mapAddEntryUrl);
			}

			// generate entry string for logs/flashbag
			$pairs = [];
			if ($config['model'] === 'MapCustom') {
				$field_db = MapService::getCustomField($options['map']);
				$pairs[] = $field_db['field_label'] . ": " . $data[$field_db['field_name']];
			} else {
				foreach ($fields as $field) {
					$pairs[] = MapInventory::getFieldDefinitions($field)['description'] . ": " . $data[$field];
				}
			}
			$entry_str = implode(', ', $pairs);

			$service = new MapService($this->getFileLogger(), $this->session);

			$model = $config['model'];
			// entry already exists
			// has applyUserRcptToScope
			if ($service->mapEntryExists($model, $map, $fields, $data)) {
				$this->flashbag->add('error', "Entry '{$entry_str}' already exists in Map '{$mapdescr}'");
				return new RedirectResponse($this->mapAddEntryUrl );
			}

			// add entry
			if ($model === 'MapCombined') {
				// has applyUseScope
				if ($service->addMapCombinedEntry($map, $fields, $data)) {
					$this->flashbag->add('success', "Entry '{$entry_str}' created in Map '{$mapdescr}'");
					return new RedirectResponse($this->mapShowUrl);
				} else {
					$this->flashbag->add('error', "Entry '{$entry_str}' creation in Map {$mapdescr} failed");
					return new RedirectResponse($this->mapAddEntryUrl);
				}
			/* deprecated
			} elseif ($this->getIsAdmin() && $model === 'MapGeneric') {
				if($service->addMapGenericEntry($map, $data[$fields[0]])) {
					$this->flashbag->add('success', "Entry '{$entry_str}' created in Map '{$mapdescr}'");
					return new RedirectResponse($this->mapShowUrl);
				} else {
					$this->flashbag->add('error', "Entry '{$entry_str}' creation in Map {$mapdescr} failed");
					return new RedirectResponse($this->mapAddEntryUrl);
				}
			*/
			} elseif ($this->getIsAdmin() && $model === 'MapCustom') {
				if($service->addMapCustomEntry($map, $data[$fields[0]])) {
					$this->flashbag->add('success', "Entry '{$entry_str}' created in Map '{$mapdescr}'");
					return new RedirectResponse($this->mapShowUrl);
				} else {
					$this->flashbag->add('error', "Entry '{$entry_str}' creation in Map {$mapdescr} failed");
					return new RedirectResponse($this->mapAddEntryUrl);
				}
			} else {
				$this->fileLogger->warning("User {$this->username} tried to add map in " . $this->request->getPathInfo() . " with wrong model {$model} or non admin rights");
				$this->flashbag->add('error', 'Error in map');
				return new RedirectResponse($this->mapShowUrl);
			}
		}

		return new Response($this->twig->render('map_add.twig', [
			'qidform' => $qidform->createView(),
			'mapselectform' => $mapCombinedSelectForm->createView(),
			//'mapselectgenericform' => $mapGenericSelectForm->createView(),
			'mapselectcustomform' => $mapCustomSelectForm->createView(),
			'mapdescr' => $mapdescr,
			'mapform' => $mapform->createView(),
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

	// works for both MapCombined/MapCustom
	public function editMapEntry(string $map, int $id): Response {
		$this->initMapUrls();

		if (empty($map)) {
			$this->flashbag->add('error', 'No map selected');
			return new RedirectResponse($this->mapsUrl);
		}

		if (empty($id) || !is_int($id)) {
			$this->flashbag->add('error', 'Invalid map entry id');
			return new RedirectResponse($this->mapsUrl);
		}

		// enable form rendering support
		$this->twigFormView($this->request);

		// generate and handle qid form
		$qidform = QidForm::create($this->formFactory, $this->request);
		if ($response = QidForm::check_form($qidform, $this->urlGenerator, $this->is_admin)) {
			// form submitted and valid
			return $response;
		}

		[$mapCombinedSelectForm, $response] = $this->handleMapSelectForm('MapCombined');
		if ($response !== null) {
			return $response;
		}

		[$mapCustomSelectForm, $response] = $this->handleMapSelectForm('MapCustom');
		if ($response !== null) {
			return $response;
		}

		// redo with map
		$this->initMapUrls($map);

		// Fetch config for the selected map
		//$config = MapInventory::getMapConfigs($map) ?? null;
		$config = MapInventory::getAvailableMapConfigs($this->getRole(), $map) ?? null;

		if (!$config || !array_key_exists('map_form', $config)) {
			$this->fileLogger->warning("User {$this->username} tried to edit map entry in " . $this->request->getPathInfo() . " without admin authorization");
			$this->flashbag->add('error', 'Invalid map selected');
			return new RedirectResponse($this->mapsUrl);
		}

		$mapdescr = $config['description'];
		$fields = $config['fields'] ?? [];
		$model = $config['model'];

		if ($model === 'MapCombined') {
			$map_entry = MapCombined::find($id);
		} else if ($this->getIsAdmin() && ($model === 'MapCustom')) {
			$map_entry = MapCustom::find($id);
		} else {
			$this->fileLogger->warning("User {$this->username} tried to edit entry in " . $this->request->getPathInfo() . " without admin authorization");
			$this->flashbag->add('error', 'Invalid map selected');
			return new RedirectResponse($this->mapsUrl);
		}

		// Dynamically call the correct form class's `create()` method
		$mapFormClass = $config['map_form'];
		if (!is_callable([$mapFormClass, 'create'])) {
			throw new \RuntimeException("Form class $mapFormClass does not have a static create() method");
		}

		$options = [
			'role' => $this->getRole(),
		];

		if ($model === 'MapCustom') {
			$options['map'] = $map;
			$options['is_edit'] = true;
		}

		if (!$this->getIsAdmin()) {
			// override user form fields. rcpt_to drop down based on user email and aliases
			$options = [
				'user_emails' => $this->getUserEmailAddresses(),
			];
		}

		$mapform = $mapFormClass::create($this->formFactory, $this->request, $map_entry->toArray(), $options);

		if ($mapform->isSubmitted() && $mapform->isValid()) {
			$data = $mapform->getData();

			if (empty($data)) {
				$this->flashbag->add('error', "Empty map data");
				return new RedirectResponse($this->mapAddEntryUrl);
			}

			// generate entry string for logs/flashbag
			$pairs = [];
			if ($model === 'MapCustom') {
				$field_db = MapService::getCustomField($options['map']);
				$data['pattern'] = trim($data[$field_db['field_name']]);
				unset($data[$field_db['field_name']]);
				$pairs[] = $field_db['field_label'] . ": " . $data['pattern'];
			} else {
				foreach ($fields as $field) {
					$pairs[] = MapInventory::getFieldDefinitions($field)['description'] . ": " . $data[$field];
				}
			}
			$entry_str = implode(', ', $pairs);

			$service = new MapService($this->getFileLogger(), $this->session);

			if ($this->getIsAdmin()) {
				$map_edit_url = $this->url(RouteName::ADMIN_MAP_EDIT_ENTRY, [ 'map' => $map, 'id' => $id ]);
			} else {
				$map_edit_url = $this->url(RouteName::MAP_EDIT_ENTRY, [ 'map' => $map, 'id' => $id ]);
			}

			// add entry
			if ($model === 'MapCombined') {
				// has applyUseScope
				if ($service->updateMapCombinedEntry($map, $fields, $data)) {
					$this->flashbag->add('success', "Entry '{$entry_str}' updated in Map '{$mapdescr}'");
					return new RedirectResponse($this->mapShowUrl);
				} else {
					$this->flashbag->add('error', "Entry '{$entry_str}' update in Map {$mapdescr} failed");
					return new RedirectResponse($map_edit_url);
				}
			} elseif ($this->getIsAdmin() && $model === 'MapCustom') {
				//if($service->updateMapCustomEntry($map, $data[$fields[0]])) {
				if($service->updateMapCustomEntry($map, $data)) {
					$this->flashbag->add('success', "Entry '{$entry_str}' updated in Map '{$mapdescr}'");
					return new RedirectResponse($this->mapShowUrl);
				} else {
					$this->flashbag->add('error', "Entry '{$entry_str}' update in Map {$mapdescr} failed");
					return new RedirectResponse($map_edit_url);
				}
			} else {
				$this->fileLogger->warning("User {$this->username} tried to update map in " . $this->request->getPathInfo() . " with wrong model {$model} or non admin rights");
				$this->flashbag->add('error', 'Error in map');
				return new RedirectResponse($this->mapShowUrl);
			}
		}

		return new Response($this->twig->render('map_edit.twig', [
			'qidform' => $qidform->createView(),
			'mapselectform' => $mapCombinedSelectForm->createView(),
			//'mapselectgenericform' => $mapGenericSelectForm->createView(),
			'mapselectcustomform' => $mapCustomSelectForm->createView(),
			'mapdescr' => $mapdescr,
			'mapform' => $mapform->createView(),
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

	public function delCustomMap(int $id): Response {

		if (!is_null($id) and is_int($id)) {
			$custom_map = CustomMapConfig::find($id);
			if (is_null($custom_map)) {
				$this->flashbag->add('error', 'Custom map not found!');
			}
			$service = new MapService($this->getFileLogger(), $this->session);
			if ($service->delCustomMap($id)) {
				$this->flashbag->add('success', "Custom Map '{$custom_map->map_name}' deleted.");
			} else {
				$this->flashbag->add('error', "Failed to delete custom map '{$custom_map->map_name}'.");
			}
		} else {
			$this->flashbag->add('error', 'Bad custom map id');
		}

		$this->initMapUrls();
		return new RedirectResponse($this->showCustomMapsConfigUrl);
	}

	public function delMapEntry(string $map, int $id): Response {
		if (!is_null($id) and is_int($id)) {

			$config = MapInventory::getAvailableMapConfigs($this->getRole(), $map);
			$model = $config['model'];

			// we need the entry details for flashbag
			if ($model === 'MapCombined') {
				$map_entry = MapCombined::find($id);
			/* deprecated
			} else if ($this->getIsAdmin() && ($model === 'MapGeneric')) {
				$map_entry = MapGeneric::find($id);
			*/
			} else if ($this->getIsAdmin() && ($model === 'MapCustom')) {
				$map_entry = MapCustom::find($id);
			} else {
				$this->fileLogger->warning("User {$this->username} tried to del entry in " . $this->request->getPathInfo() . " without admin authorization");
				$this->flashbag->add('error', 'Invalid map selected');
				$this->initMapUrls();
				return new RedirectResponse($this->mapsUrl);
			}

			if (!empty($map_entry)) {
				$mapdescr = $config['description'] ?? null;
				$fields = $config['fields'] ?? null;
				$pairs = [];
				$entry_str = '';
				if ($fields) {
					foreach ($fields as $field) {
						/* deprecated
						if ($model === 'MapGeneric') {
							$pairs[] = MapInventory::getFieldDefinitions($field)['description'] . ": " . $map_entry->pattern;
						} elseif ($model === 'MapCustom') {
						*/
						if ($model === 'MapCustom') {
							$field_db = MapService::getCustomField($map_entry->map_name);
							$pairs[] = $field_db['field_label'] . ": " . $map_entry->pattern;
						} else {
							$pairs[] = MapInventory::getFieldDefinitions($field)['description'] . ": " . $map_entry->$field;
						}
					}
					$entry_str = implode(', ', $pairs);
				}

				$service = new MapService($this->getFileLogger(), $this->session);

				if ($fields) {
					// has applyUseScope for MapCombined
					$delete = $service->delMapEntry($model, $map, $fields, $id);
					if ($delete) {
						$this->flashbag->add('success', "Map entry '{$entry_str}' deleted from Map '{$mapdescr}'");
					} else {
						$this->flashbag->add('error', "Map entry {$entry_str} failed to be deleted from Map {$mapdescr}");
					}
				} else { // if no fields it failed the role in getAvailableMapConfigs()
					$this->fileLogger->warning("User '{$this->username}' tried to access " . $this->request->getPathInfo() . " without admin authorization");
					$this->flashbag->add('error', "Permission denied");
					$this->initMapUrls();
					return new RedirectResponse($this->mapsUrl);
				}
			} else {
				$this->flashbag->add('error', "Map entry not found");
			}
		} else {
			$this->flashbag->add('error', "Bad map entry id");
		}

		if (!empty($map)) {
			$this->initMapUrls($map);
			$url = $this->mapShowUrl;
		} else {
			$this->initMapUrls();
			$url = $this->mapsUrl;
		}
		return new RedirectResponse($url);
	}

	public function delMapAllEntries(string $map): Response {

		$config = MapInventory::getAvailableMapConfigs($this->getRole(), $map);

		if (empty($config) || empty($config['model'])) {
			$this->fileLogger->warning("User {$this->username} tried to del all entries in " . $this->request->getPathInfo() . " for an invalid map or without authorization");
			$this->flashbag->add('error', 'Invalid map selected');
			$this->initMapUrls();
			return new RedirectResponse($this->mapsUrl);
		}

		$model = $config['model'];

		if (empty($map) || (($model !== 'MapCombined') && ($model !== 'MapCustom'))) {
			$this->fileLogger->warning("User {$this->username} tried to del all entries in " . $this->request->getPathInfo() . " for an invalid map");
			$this->flashbag->add('error', 'Invalid map selected');
			$this->initMapUrls();
			return new RedirectResponse($this->mapsUrl);
		}

		if (!$this->getIsAdmin() && ($model === 'MapCustom')) {
			$this->fileLogger->warning("User {$this->username} tried to del all entries in " . $this->request->getPathInfo() . " without admin authorization");
			$this->flashbag->add('error', 'Permission denied');
			$this->initMapUrls();
			return new RedirectResponse($this->mapsUrl);
		}

		$mapdescr = $config['description'] ?? null;
		$fields = $config['fields'] ?? null;

		$service = new MapService($this->getFileLogger(), $this->session);

		if ($fields) {
			// has applyUseScope for MapCombined
			$delete = $service->delMapAllEntries($model, $map, $fields);
			if ($delete) {
				$this->flashbag->add('success', "All entries from Map '{$mapdescr}' deleted");
			} else {
				$this->flashbag->add('error', "All entries failed to be deleted from Map {$mapdescr}");
			}
		} else { // if no fields it failed the role in getAvailableMapConfigs()
			$this->fileLogger->warning("User '{$this->username}' tried to access " . $this->request->getPathInfo() . " without admin authorization");
			$this->flashbag->add('error', "Permission denied");
			$this->initMapUrls();
			return new RedirectResponse($this->mapsUrl);
		}

		if (!empty($map)) {
			$this->initMapUrls($map);
			$url = $this->mapShowUrl;
		} else {
			$this->initMapUrls();
			$url = $this->mapsUrl;
		}
		return new RedirectResponse($url);
	}

	public function searchMapEntry(): Response {
		// enable form rendering support
		$this->twigFormView($this->request);

		// generate and handle qid form
		$qidform = QidForm::create($this->formFactory, $this->request);
		if ($response = QidForm::check_form($qidform, $this->urlGenerator, $this->is_admin)) {
			// form submitted and valid
			return $response;
		}

		[$mapCombinedSelectForm, $response] = $this->handleMapSelectForm('MapCombined');
		if ($response !== null) {
			return $response;
		}
		[$mapCustomSelectForm, $response] = $this->handleMapSelectForm('MapCustom');
		if ($response !== null) {
			return $response;
		}

		//$map_search_form = $request->request->all('map_search_form');
		$map_search_form = $this->request->get('map_search_form');

		$this->initMapUrls();

		if (empty($map_search_form['model'])) {
			$this->flashbag->add('error', 'Search Model empty');
			return new RedirectResponse($this->mapsUrl);
		}

		$model = $map_search_form['model'];
		if ($model !== 'MapCombined' && $model !== 'MapCustom') {
			$this->flashbag->add('error', 'Wrong search Model');
			return new RedirectResponse($this->mapsUrl);
		}

		if (empty($map_search_form['field'])) {
			$this->flashbag->add('error', 'Search field empty');
			return new RedirectResponse($this->mapsUrl);
		}
		$search = $map_search_form['field'];

		$map_name = null;
		if (!empty($map_search_form['map_name'])) {
			$map_name = $map_search_form['map_name'];
		}

		$page = $this->request->query->getInt('page', 1);

		$service = new MapService($this->getFileLogger(), $this->session);

		$configs = MapInventory::getAvailableMapConfigs($this->getRole()) ?? null;

		$field_definitions = MapInventory::getFieldDefinitions() ?? null;
		$field_descriptions = [];
		foreach ($field_definitions as $field => $definition) {
			$field_descriptions[$field] = $definition['description'];
		}

		$this->initMapUrls();

		if ($this->getIsAdmin() and $model === 'MapCustom') {
			$map_comb_entries = null;
			$map_comb_total = null;
			$map_gen_entries = null;
			$map_gen_total = null;
			$filter_maps = null;
			$map_custom_entries = $service->searchPaginatedMapCustom($page, $this->mapSearchEntryUrl, $search, $map_name);
			$map_custom_total = $map_custom_entries->total();

			if (empty($map_custom_entries)) {
				$this->flashbag->add('info', 'No map entries exist');
				return new RedirectResponse($this->mapsUrl);
			}
			foreach ($map_custom_entries as $key => $map_entry) {
				// add map description
				$field = $configs[$map_entry->map_name]['fields'][0];
				$map_name = $map_entry->map_name;
				$field_description = MapService::getCustomField($map_name)['field_label'];
				$map_custom_entries[$key]->map_description = $configs[$map_entry->map_name]['description'];
				$map_custom_entries[$key]->field = $field;
				$map_custom_entries[$key]->field_description = $field_description;
			}
		} else {
			$map_gen_entries = null;
			$map_gen_total = null;
			$map_custom_entries = null;
			$map_custom_total = null;
			$filter_maps = MapInventory::getMapsByModel($model, $configs);

			// has applyUserRcptToScope and filter maps on model
			$map_comb_entries = $service->searchPaginatedMapCombined($page, $this->mapSearchEntryUrl, $filter_maps, $search, $map_name);

			if (empty($map_comb_entries)) {
				$this->flashbag->add('info', 'No map entries exist');
				return new RedirectResponse($this->mapsUrl);
			}

			foreach ($map_comb_entries as $key => $map_entry) {
				// add map description
				$map_comb_entries[$key]->map_description = $configs[$map_entry->map_name]['description'];
				$map_comb_entries[$key]->map_username = $this->getMapUser($map_entry->user);
				$map_comb_entries[$key]->user_can_delete = $this->getUserCanDelete($this->username, $map_comb_entries[$key]->map_username);
			}
			$map_comb_total = $map_comb_entries->total();
		}

		$sf_data = [ 'model' => $model ];
		$mapSearchForm = MapSearchForm::create($this->formFactory, $this->request, $this->urlGenerator, $sf_data);

		return new Response($this->twig->render('maps_all_paginated.twig', [
			'qidform' => $qidform->createView(),
			'mapsearchform' => $mapSearchForm->createView(),
			'mapselectform' => $mapCombinedSelectForm->createView(),
			//'mapselectgenericform' => $mapGenericSelectForm->createView(),
			'mapselectcustomform' => $mapCustomSelectForm->createView(),
			'map_comb_entries' => $map_comb_entries,
			'map_comb_total' => $map_comb_total,
			'map_gen_entries' => $map_gen_entries,
			'map_gen_total' => $map_gen_total,
			'map_custom_entries' => $map_custom_entries,
			'map_custom_total' => $map_custom_total,
			'field_descriptions' => $field_descriptions,
			'items_per_page' => $this->items_per_page,
			'runtime' => $this->getRuntime(),
			'flashes' => $this->flashbag->all(),
			'is_admin' => $this->session->get('is_admin'),
			'username' => $this->session->get('username'),
			'auth_provider' => $this->session->get('auth_provider'),
			'current_route' => $this->request->getPathInfo(),
			'rspamd_stats' => $this->getRspamdStat(),
			'maps_url_base' => $this->getMapsUrlBase(),
			'map_name' => $map_search_form['map_name'],
			'search_map' => true,
		]));
	}

	public function toggleMapEntry(string $map, int $id): Response {
		if (!is_null($id) and is_int($id)) {

			$config = MapInventory::getAvailableMapConfigs($this->getRole(), $map);
			$model = $config['model'];

			// we need the entry details for flashbag
			if ($model === 'MapCombined') {
				$map_entry = MapCombined::find($id);
			/* deprecated
			} else if ($this->getIsAdmin() && ($model === 'MapGeneric')) {
				$map_entry = MapGeneric::find($id);
			*/
			} else if ($this->getIsAdmin() && ($model === 'MapCustom')) {
				$map_entry = MapCustom::find($id);
			} else {
				$this->fileLogger->warning("User {$this->username} tried to toggle entry in " . $this->request->getPathInfo() . " without admin authorization");
				$this->flashbag->add('error', 'Invalid map selected');
				$this->initMapUrls();
				return new RedirectResponse($this->mapsUrl);
			}

			if (!empty($map_entry)) {
				$mapdescr = $config['description'] ?? null;
				$fields = $config['fields'] ?? null;
				$pairs = [];
				$entry_str = '';
				if ($fields) {
					foreach ($fields as $field) {
						/* deprecated
						if ($model === 'MapGeneric') {
							$pairs[] = MapInventory::getFieldDefinitions($field)['description'] . ": " . $map_entry->pattern;
						} elseif ($model === 'MapCustom') {
						*/
						if ($model === 'MapCustom') {
							$field_db = MapService::getCustomField($map_entry->map_name);
							$pairs[] = $field_db['field_label'] . ": " . $map_entry->pattern;
						} else {
							$pairs[] = MapInventory::getFieldDefinitions($field)['description'] . ": " . $map_entry->$field;
						}
					}
					$entry_str = implode(', ', $pairs);
				}

				$service = new MapService($this->getFileLogger(), $this->session);

				if ($fields) {
					// has applyUseScope for MapCombined
					$what_toggle = $map_entry->disabled ? "enabled" : "disabled";
					$toggle = $service->toggleMapEntry($model, $map, $fields, $id);
					if ($toggle) {
						$this->flashbag->add('success', "Map entry '{$entry_str}' {$what_toggle} in Map '{$mapdescr}'");
					} else {
						$this->flashbag->add('error', "Map entry {$entry_str} failed to be {$what_toggle} in Map {$mapdescr}");
					}
				} else { // if no fields it failed the role in getAvailableMapConfigs()
					$this->fileLogger->warning("User '{$this->username}' tried to access " . $this->request->getPathInfo() . " without admin authorization");
					$this->flashbag->add('error', "Permission denied");
					$this->initMapUrls();
					return new RedirectResponse($this->mapsUrl);
				}
			} else {
				$this->flashbag->add('error', "Map entry not found");
			}
		} else {
			$this->flashbag->add('error', "Bad map entry id");
		}

		if (!empty($map)) {
			$this->initMapUrls($map);
			$url = $this->mapShowUrl;
		} else {
			$this->initMapUrls();
			$url = $this->mapsUrl;
		}
		return new RedirectResponse($url);
	}

	private function getUserRcptAddresses(): array {
		if (!$this->username) {
			return [];
		}
		$user = User::where('username', $this->username)->first();

		if (!$user) {
			return [];
		}

		if (!$this->email) {
			return [];
		}
		$aliases = MailAlias::where('user_id', $user->id)->pluck('alias')->toArray();
		$aliases[] = $this->email;
		return $aliases;
	}

	private function getUserCanDelete(string $username, ?string $map_creator = ''): bool {
		// user is admin, allow
		if ($this->getIsAdmin()) {
			return true;
		}

		// user is the map entry creator, allow
		if (($username === $map_creator) && ($map_creator != 'Deleted user')) {
			return true;
		}

		// user is not the map entry creator and not admin

		// allow if USER_CAN_DEL_ADMIN_MAP_ENTRIES is true
		if (Config::get('USER_CAN_DEL_ADMIN_MAP_ENTRIES')) {
			return true;
		}

		return false;
	}

	private function getMapUser(?User $user = null): string {
		if (!empty($user) and !empty($user->username)) {
			return $user->username;
		}
		return 'Deleted user';
	}

	private function handleMapSelectForm(string $model): array {
		if ($model === 'MapCombined') {
			$form_name = 'map_combined_form';
		/* deprecated
		} elseif ($model === 'MapGeneric') {
			$form_name = 'map_generic_form';
		*/
		} elseif ($model === 'MapCustom') {
			$form_name = 'map_custom_form';
		} else {
			throw new \RuntimeException("Wrong model {$model} requested");
		}

		$this->initMapUrls();

		$options = [
			'role' => $this->getRole(),
			'model' => $model,
			'form_name' => $form_name,
			'action' => $this->mapsUrl,
		];

		$form = MapSelectForm::create($this->formFactory, $this->request, null, $options);
		$response = MapSelectForm::check_form_show($form, $this->urlGenerator, $this->is_admin);

		return [$form, $response];
	}

	public function getMapsUrlBase(): string {
		return $this->maps_url_base;
	}

}
