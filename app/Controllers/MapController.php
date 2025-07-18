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
use App\Forms\MapSelectForm;
use App\Forms\MapSmtpFromRcptToWhitelistForm;

use App\Inventory\MapInventory;
use App\Services\MapService;

use App\Models\MapCombined;
use App\Models\MapActivityLog;
use App\Models\User;
use App\Models\MailAlias;

class MapController extends ViewController
{
	protected $refresh_rate;
	protected $items_per_page;
	protected $max_items;
	protected bool $mapUrlsInitialized = false;
	protected string $mapsUrl;
	protected string $mapShowAllUrl;
	protected string $mapShowUrl;
	protected string $mapAddEntryUrl;

	public function __construct() {
	//	parent::__construct();

		$this->items_per_page = Config::get('items_per_page');
		$this->max_items = Config::get('max_items');
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

		/*
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
		[$mapGenericSelectForm, $response] = $this->handleMapSelectForm('MapGeneric');
		if ($response !== null) {
			return $response;
		}

		return new Response($this->twig->render('map_select.twig', [
			'qidform' => $qidform->createView(),
			'mapselectform' => $mapCombinedSelectForm->createView(),
			'mapselectform2' => $mapGenericSelectForm->createView(),
			'runtime' => $this->getRuntime(),
			'refresh_rate' => $this->refresh_rate,
			'flashes' => $this->flashbag->all(),
			'is_admin' => $this->session->get('is_admin'),
			'username' => $this->session->get('username'),
			'auth_provider' => $this->session->get('auth_provider'),
			'current_route' => $this->request->getPathInfo(),
		]));
	}

	public function showAllMaps(?string $model): Response {
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
		[$mapGenericSelectForm, $response] = $this->handleMapSelectForm('MapGeneric');
		if ($response !== null) {
			return $response;
		}

		$page = $this->request->query->getInt('page', 1);

		$service = new MapService($this->getFileLogger(), $this->session);

		$this->initUrls();

		$configs = MapInventory::getAvailableMapConfigs($this->getRole()) ?? null;

		$filter_maps = MapInventory::getMapsByModel("MapCombined", $configs);

		// has applyUserRcptToScope and filter maps on model
		$map_comb_entries = $service->showPaginatedAllMapCombined($page, $this->mapShowAllUrl, $filter_maps);

		if (empty($map_comb_entries)) {
			$this->flashbag->add('info', 'No map entries exist');
			return new RedirectResponse($this->mapsUrl);
		}

		$field_definitions = MapInventory::getFieldDefinitions() ?? null;
		$field_descriptions = [];
		foreach ($field_definitions as $field => $definition) {
			$field_descriptions[$field] = $definition['description'];
		}

		foreach ($map_comb_entries as $key => $map_entry) {
			// add map description
			$map_comb_entries[$key]->map_description = $configs[$map_entry->map_name]['description'];
			$map_comb_entries[$key]->map_username = $this->getMapUser($map_entry->user);
			$map_comb_entries[$key]->user_can_delete = $this->getUserCanDelete($this->username, $map_comb_entries[$key]->map_username);
		}

		return new Response($this->twig->render('maps_all_paginated.twig', [
			'qidform' => $qidform->createView(),
			'mapselectform' => $mapCombinedSelectForm->createView(),
			'mapselectform2' => $mapGenericSelectForm->createView(),
			'map_comb_entries' => $map_comb_entries,
			'field_descriptions' => $field_descriptions,
			'totalRecords' => $map_comb_entries->total(),
			'items_per_page' => $this->items_per_page,
			'runtime' => $this->getRuntime(),
			'flashes' => $this->flashbag->all(),
			'is_admin' => $this->session->get('is_admin'),
			'username' => $this->session->get('username'),
			'auth_provider' => $this->session->get('auth_provider'),
			'current_route' => $this->request->getPathInfo(),
		]));
	}

	public function showMap(string $map): Response {
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
		[$mapGenericSelectForm, $response] = $this->handleMapSelectForm('MapGeneric');
		if ($response !== null) {
			return $response;
		}

		$this->initUrls();

		if (empty($map)) {
			$this->flashbag->add('error', 'No map selected');
			return new RedirectResponse($this->mapsUrl);
		}

		// Fetch config for the selected map
		$config = MapInventory::getAvailableMapConfigs($this->getRole(), $map) ?? null;

		if (!$config || !array_key_exists('fields', $config)) {
			$this->fileLogger->warning("User {$this->username} tried to show map in " . $this->request->getPathInfo() . " without admin authorization");
			$this->flashbag->add('error', 'Invalid map selected');
			return new RedirectResponse($this->mapsUrl);
		}

		$fields = $config['fields'];
		$descriptions = [];
		foreach ($fields as $field) {
			$descriptions[] = MapInventory::getFieldDefinitions($field)['description'];
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

		} elseif($this->getIsAdmin() && $config['model'] === 'MapGeneric') {
			$model = 'MapGeneric';
			// without pagination
			//$map_entries = $service->showMapGeneric($map);
			$map_entries = $service->showPaginatedMapGeneric($map, $page, $this->mapShowUrl);
		} else {
			$this->fileLogger->warning("User {$this->username} tried to show map in " . $this->request->getPathInfo() . " with wrong model {$config['model']} or non admin rights");
			$this->flashbag->add('error', 'Error in map');
			return new RedirectResponse($this->mapsUrl);
		}

		return new Response($this->twig->render('map_paginated.twig', [
			'qidform' => $qidform->createView(),
			'mapselectform' => $mapCombinedSelectForm->createView(),
			'mapselectform2' => $mapGenericSelectForm->createView(),
			'map' => $map,
			'model' => $model,
			'mapdescr' => $mapdescr,
			'fields' => $fields,
			'descriptions' => $descriptions,
			'map_entries' => $map_entries,
			'totalRecords' => $map_entries->count(),
			'items_per_page' => $this->items_per_page,
			'runtime' => $this->getRuntime(),
			'flashes' => $this->flashbag->all(),
			'is_admin' => $this->session->get('is_admin'),
			'username' => $this->session->get('username'),
			'auth_provider' => $this->session->get('auth_provider'),
			'current_route' => $this->request->getPathInfo(),
		]));
	}

	public function addMapCombinedEntry(string $map): Response {
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
		[$mapGenericSelectForm, $response] = $this->handleMapSelectForm('MapGeneric');
		if ($response !== null) {
			return $response;
		}

		$this->initUrls();

		if (empty($map)) {
			$this->flashbag->add('error', 'No map selected');
			return new RedirectResponse($this->mapsUrl);
		}

		// Fetch config for the selected map
		//$config = MapInventory::getMapConfigs($map) ?? null;
		$config = MapInventory::getAvailableMapConfigs($this->getRole(), $map) ?? null;

		if (!$config || !array_key_exists('map_form', $config)) {
			$this->fileLogger->warning("User {$this->username} tried to add map entry in " . $this->request->getPathInfo() . " without admin authorization");
			$this->flashbag->add('error', 'Invalid map selected');
			return new RedirectResponse($this->mapsUrl);
		}

		$mapdescr = $config['description'];
		$fields = $config['fields'];

		// Dynamically call the correct form class's `create()` method
		$mapFormClass = $config['map_form'];
		if (!is_callable([$mapFormClass, 'create'])) {
			throw new \RuntimeException("Form class $mapFormClass does not have a static create() method");
		}

		if ($this->getIsAdmin()) {
			$mapform = $mapFormClass::create($this->formFactory, $this->request);
		} else {
			// override user form fields. rcpt_to drop down based on user email and aliases
			$options = [
				'role' => $this->getRole(),
				'user_emails' => $this->getUserEmailAddresses(),
			];
			$mapform = $mapFormClass::create($this->formFactory, $this->request, null, $options);
		}

		if ($mapform->isSubmitted() && $mapform->isValid()) {
			$data = $mapform->getData();

			$this->initMapUrls($map);
			$mapAddEntryUrl  = $this->mapAddEntryUrl;
			$mapShowUrl = $this->mapShowUrl;

			if (empty($data)) {
				$this->flashbag->add('error', "Empty map data");
				return new RedirectResponse($mapAddEntryUrl );
			}

			// generate entry string for logs/flashbag
			$pairs = [];
			foreach ($fields as $field) {
				$pairs[] = MapInventory::getFieldDefinitions($field)['description'] . ": " . $data[$field];
			}
			$entry_str = implode(', ', $pairs);

			$service = new MapService($this->getFileLogger(), $this->session);

			$model = $config['model'];
			// entry already exists
			// has applyUserRcptToScope
			if ($service->mapEntryExists($model, $map, $fields, $data)) {
				$this->flashbag->add('error', "Entry '{$entry_str}' already exists in Map {$mapdescr}");
				return new RedirectResponse($mapAddEntryUrl );
			}

			// add entry
			if ($model === 'MapCombined') {
				// has applyUseScope
				if ($service->addMapCombinedEntry($map, $fields, $data)) {
					$this->flashbag->add('success', "Entry '{$entry_str}' created in Map '{$mapdescr}'");
					return new RedirectResponse($mapShowUrl);
				} else {
					$this->flashbag->add('error', "Entry '{$entry_str}' creation in Map {$mapdescr} failed");
					return new RedirectResponse($mapAddEntryUrl);
				}
			} elseif ($this->getIsAdmin() && $model === 'MapGeneric') {
				if($service->addMapGenericEntry($map, $data[$fields[0]])) {
					$this->flashbag->add('success', "Entry '{$entry_str}' created in Map '{$mapdescr}'");
					return new RedirectResponse($mapShowUrl);
				} else {
					$this->flashbag->add('error', "Entry '{$entry_str}' creation in Map {$mapdescr} failed");
					return new RedirectResponse($mapAddEntryUrl);
				}
			} else {
				$this->fileLogger->warning("User {$this->username} tried to add map in " . $this->request->getPathInfo() . " with wrong model {$model} or non admin rights");
				$this->flashbag->add('error', 'Error in map');
				return new RedirectResponse($mapShowUrl);
			}
		}

		return new Response($this->twig->render('map_add.twig', [
			'qidform' => $qidform->createView(),
			'mapselectform' => $mapCombinedSelectForm->createView(),
			'mapselectform2' => $mapGenericSelectForm->createView(),
			'mapdescr' => $mapdescr,
			'mapform' => $mapform->createView(),
			'runtime' => $this->getRuntime(),
			'refresh_rate' => $this->refresh_rate,
			'flashes' => $this->flashbag->all(),
			'is_admin' => $this->session->get('is_admin'),
			'username' => $this->session->get('username'),
			'auth_provider' => $this->session->get('auth_provider'),
			'current_route' => $this->request->getPathInfo(),
		]));
	}

	public function delMapCombinedEntry(string $map, int $id): Response {
		if (!is_null($id) and is_int($id)) {

			// we need the entry details for flashbag
			$map_entry = MapCombined::find($id);

			if ($map_entry) {
				//$mapdescr = MapInventory::getMapConfigs($map)['description'];
				$mapdescr = MapInventory::getAvailableMapConfigs($this->getRole(), $map)['description'] ?? null;
				//$fields = MapInventory::getMapConfigs($map)['fields'];
				$fields = MapInventory::getAvailableMapConfigs($this->getRole(), $map)['fields'] ?? null;
				$pairs = [];
				$entry_str = '';
				if ($fields) {
					foreach ($fields as $field) {
						$pairs[] = MapInventory::getFieldDefinitions($field)['description'] . ": " . $map_entry->$field;
					}
					$entry_str = implode(', ', $pairs);
				}

				$service = new MapService($this->getFileLogger(), $this->session);

				if ($fields) {
					dd("delete here");
					// has applyUseScope
					if ($service->delMapCombinedEntry($map, $fields, $id)) {
						$this->flashbag->add('success', "Map entry '{$entry_str}' deleted from Map '{$mapdescr}'");
					} else {
						$this->flashbag->add('error', "Map entry {$entry_str} failed to be deleted from Map {$mapdescr}");
					}
				} else { // if no fields it failed the role in getAvailableMapConfigs()
					$this->fileLogger->warning("User '{$this->username}' tried to access " . $this->request->getPathInfo() . " without admin authorization");
					$this->flashbag->add('error', "Permission denied");
					$this->initUrls();
					$url = $this->mapsUrl;
					return new RedirectResponse($url);
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
			$this->initUrls();
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

	public function initMapUrls(string $map): void {
      if ($this->mapUrlsInitialized) {
         return;
      }

		if (!$this->urlsInitialized) {
			$this->initUrls();
		}

      if ($this->getIsAdmin()) {
			$this->mapShowUrl = $this->urlGenerator->generate('admin_map_show', [ 'map' => $map ]);
			$this->mapAddEntryUrl = $this->urlGenerator->generate('admin_map_add_entry', [ 'map' => $map ]);
      } else {
			$this->mapShowUrl = $this->urlGenerator->generate('map_show', [ 'map' => $map ]);
			$this->mapAddEntryUrl = $this->urlGenerator->generate('map_add_entry', [ 'map' => $map ]);
      }

      $this->mapUrlsInitialized = true;
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
		} elseif ($model === 'MapGeneric') {
			$form_name = 'map_generic_form';
		} else {
			throw new \RuntimeException("Wrong model {$model} requested");
		}

		$options = [
			'role' => $this->getRole(),
			'model' => $model,
			'form_name' => $form_name,
		];

		$form = MapSelectForm::create($this->formFactory, $this->request, null, $options);
		$response = MapSelectForm::check_form_show($form, $this->urlGenerator, $this->is_admin);

		return [$form, $response];
	}

}
