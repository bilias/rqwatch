<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Services;

use App\Core\Config;
use App\Utils\Helper;
use App\Utils\FormHelper;

use App\Models\MapCombined;
//use App\Models\MapGeneric;
use App\Models\MapCustom;
use App\Models\CustomMapConfig;
use App\Models\MapActivityLog;

use App\Inventory\MapInventory;

use Psr\Log\LoggerInterface;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;

use Symfony\Component\HttpFoundation\Session\Session;

class MapService
{
	private LoggerInterface $logger;
	private ?bool $is_admin = null;
	private ?string $username = null;
	private ?int $user_id = null;
	private ?string $email = null;
	private ?array $user_aliases = null;

	public function __construct(LoggerInterface $logger, ?Session $session = null) {
		$this->logger = $logger;

		if (!empty($session)) {
			$this->is_admin = $session->get('is_admin');
			$this->username = $session->get('username');
			$this->user_id = $session->get('user_id');
			$this->email = $session->get('email');
			$this->user_aliases = $session->get('user_aliases');
		}

		$this->items_per_page = Config::get('items_per_page');
		$this->max_items = Config::get('max_items');
	}

	protected function applyUserRcptToScope($query): Builder {
		if (defined('CLI_MODE') && CLI_MODE) {
			return $query;
		}

		// admin no restrictions
		if($this->is_admin) {
			return $query;
		}

		if (empty($this->email)) {
			return $query->where('id', null);
		}

		// user does not see his entries, created by admin and not him
		if (!Config::get('USER_CAN_SEE_ADMIN_MAP_ENTRIES')) {
			$query = $query->where('user_id', $this->user_id);
		}

		// user has no aliases
		if (empty($this->user_aliases)) {
			return $query->where('rcpt_to', $this->email);
		}

		// Combine primary email with mail aliases
		$emails = array_unique(array_filter(array_merge([$this->email], $this->user_aliases ?? [])));
		return $query->whereIn('rcpt_to', $emails);
	}

	public static function getSqlFromQuery(Builder $query): string {
		return vsprintf(str_replace('?', '"%s"', $query->toSql()), $query->getBindings());
	}

	public function getMapCombinedBasicQuery(string $map_name, array $map_fields = []): Builder {
		$select_fields = array_merge(MapCombined::SELECT_FIELDS, $map_fields);

		$query = MapCombined::select($select_fields)
								  ->where('map_name', $map_name)
								  ->orderBy('updated_at', 'DESC');

		foreach ($map_fields as $field) {
			$query = $query->whereNotNull($field);
		}

		return $query;
	}

	public function getMapCombinedQuery(string $map_name, array $map_fields): Builder {
		$query = $this->getMapCombinedBasicQuery($map_name, $map_fields);

		$query = $query->with(['user' => function ($query) {
							  $query->select('id', 'username', 'email');
							}]);

		$query = $this->applyUserRcptToScope($query);

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$this->logger->info(self::getSqlFromQuery($query));
		}
		return $query;
	}

	// deprecated
	public function getMapGenericQuery(string $map_name): Builder {
		$query = MapGeneric::select(MapGeneric::SELECT_FIELDS)
								  ->where('map_name', $map_name)
								  ->orderBy('updated_at', 'DESC');

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$this->logger->info(self::getSqlFromQuery($query));
		}

		return $query;
	}

	public function getMapCustomQuery(string $map_name): Builder {
		$query = MapCustom::select(MapCustom::SELECT_FIELDS)
								  ->where('map_name', $map_name)
								  ->orderBy('updated_at', 'DESC');

		return $query;
	}

	public static function getCustomMapConfigs(): Collection {
		$query = CustomMapConfig::select(CustomMapConfig::SELECT_FIELDS)
								  ->orderBy('map_name', 'ASC')
								  ->orderBy('updated_at', 'DESC');

		try {
			$maps = $query
				->get();
		} catch (\Exception $e) {
			$this->logger->error("Query error: " . $e->getMessage() . PHP_EOL);
			exit("Query error");
		}

		return $maps;
	}

	public static function getCustomField(string $map_name): array {
		$query = CustomMapConfig::select('field_name', 'field_label')
								  ->where('map_name', $map_name);

		try {
			$field = $query
				->first()->toArray();
		} catch (\Exception $e) {
			$this->logger->error("Query error: " . $e->getMessage() . PHP_EOL);
			exit("Query error");
		}

		return $field;
	}

	public function searchPaginatedMapCombined(
		int $page = 1,
		string $url,
		array $filter_maps,
		$search,
		$map_name
	): ?LengthAwarePaginator {

		$query = MapCombined::select('*')
								  ->with(['user' => function ($query) {
										$query->select('id', 'username', 'email');
								    }])
								  ->orderBy('updated_at', 'DESC');

		// filter maps
		if (!$this->is_admin && $filter_maps) {
			$query = $query->whereIn('map_name', $filter_maps);
		}

		$query = $this->applyUserRcptToScope($query);

		if (!empty($map_name)) {
			$query = $query->where('map_name', $map_name);
		}

		$search = trim($search);
		if (!empty($search)) {
			$query->where(function($q) use ($search) {
				foreach (MapCombined::SEARCH_FIELDS as $field) {
					$q->orWhere($field, 'LIKE', "%{$search}%");
				}
			});
		}

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$this->logger->info(self::getSqlFromQuery($query));
		}

		try {
			$map_entries = $query
				->paginate($this->items_per_page, ['*'], 'page', $page)
				->withPath($url);
		} catch (\Exception $e) {
			$this->logger->error("Query error: " . $e->getMessage() . PHP_EOL);
			exit("Query error");
		}

		return $map_entries;
	}

	public function showPaginatedAllMapCombined(int $page = 1, string $url, array $filter_maps): ?LengthAwarePaginator {
		$query = MapCombined::select('*')
								  ->with(['user' => function ($query) {
										$query->select('id', 'username', 'email');
								    }])
								  ->orderBy('updated_at', 'DESC');

		// filter maps
		if (!$this->is_admin && $filter_maps) {
			$query = $query->whereIn('map_name', $filter_maps);
		}

		$query = $this->applyUserRcptToScope($query);

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$this->logger->info(self::getSqlFromQuery($query));
		}

		try {
			$map_entries = $query
				->paginate($this->items_per_page, ['*'], 'page', $page)
				->withPath($url);
		} catch (\Exception $e) {
			$this->logger->error("Query error: " . $e->getMessage() . PHP_EOL);
			exit("Query error");
		}

		return $map_entries;
	}

	// deprecated
	public function showPaginatedAllMapGeneric(int $page = 1, string $url): ?LengthAwarePaginator {
		$query = MapGeneric::select('*')
								  ->orderBy('updated_at', 'DESC');

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$this->logger->info(self::getSqlFromQuery($query));
		}

		try {
			$map_entries = $query
				->paginate($this->items_per_page, ['*'], 'page', $page)
				->withPath($url);
		} catch (\Exception $e) {
			$this->logger->error("Query error: " . $e->getMessage() . PHP_EOL);
			exit("Query error");
		}

		return $map_entries;
	}

	public function searchPaginatedMapCustom(
		int $page = 1,
		string $url,
		string $search,
		?string $map_name
	): ?LengthAwarePaginator {

		if (!empty($map_name)) {
			$query = $this->getMapCustomQuery($map_name);
		} else {
			$query = MapCustom::select(MapCustom::SELECT_FIELDS);
		}

		$search = trim($search);
		if (!empty($search)) {
			$query = $query->where('pattern', 'LIKE', "%{$search}%")
								->orderBy('updated_at', 'DESC');
		}

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$this->logger->info(self::getSqlFromQuery($query));
		}

		try {
			$map_entries = $query
				->paginate($this->items_per_page, ['*'], 'page', $page)
				->withPath($url);
		} catch (\Exception $e) {
			$this->logger->error("Query error: " . $e->getMessage() . PHP_EOL);
			exit("Query error");
		}

		return $map_entries;
	}

	public function showPaginatedAllMapCustom(int $page = 1, string $url): ?LengthAwarePaginator {
		$query = MapCustom::select('*')
								  ->orderBy('updated_at', 'DESC');

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$this->logger->info(self::getSqlFromQuery($query));
		}

		try {
			$map_entries = $query
				->paginate($this->items_per_page, ['*'], 'page', $page)
				->withPath($url);
		} catch (\Exception $e) {
			$this->logger->error("Query error: " . $e->getMessage() . PHP_EOL);
			exit("Query error");
		}

		return $map_entries;
	}

	public function showPaginatedCustomMapConfigs(int $page = 1, string $url): ?LengthAwarePaginator {
		$query = CustomMapConfig::select('*')
								  //->orderBy('map_name', 'ASC')
								  ->orderBy('updated_at', 'DESC');

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$this->logger->info(self::getSqlFromQuery($query));
		}

		try {
			$map_configs = $query
				->paginate($this->items_per_page, ['*'], 'page', $page)
				->withPath($url);
		} catch (\Exception $e) {
			$this->logger->error("Query error: " . $e->getMessage() . PHP_EOL);
			exit("Query error");
		}

		return $map_configs;
	}

	public function showMapCustom(string $map_name): Collection {
		$query = $this->getMapCustomQuery($map_name);

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$this->logger->info(self::getSqlFromQuery($query));
		}

		try {
			$map = $query
				->get();
		} catch (\Exception $e) {
			$this->logger->error("Query error: " . $e->getMessage() . PHP_EOL);
			exit("Query error");
		}

		return $map;
	}

	public function showPaginatedMapCustom(string $map_name, int $page = 1, string $url): ?LengthAwarePaginator {
		$query = $this->getMapCustomQuery($map_name);

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$this->logger->info(self::getSqlFromQuery($query));
		}

		try {
			$map = $query
				->paginate($this->items_per_page, ['*'], 'page', $page)
				->withPath($url);
		} catch (\Exception $e) {
			$this->logger->error("Query error: " . $e->getMessage() . PHP_EOL);
			exit("Query error");
		}

		return $map;
	}

	// deprecated
	public function showMapGeneric(string $map_name): Collection {
		$query = $this->getMapGenericQuery($map_name);

		try {
			$map = $query
				->get();
		} catch (\Exception $e) {
			$this->logger->error("Query error: " . $e->getMessage() . PHP_EOL);
			exit("Query error");
		}

		return $map;
	}

	// deprecated
	public function showPaginatedMapGeneric(string $map_name, int $page = 1, string $url): ?LengthAwarePaginator {
		$query = $this->getMapGenericQuery($map_name);

		try {
			$map = $query
				->paginate($this->items_per_page, ['*'], 'page', $page)
				->withPath($url);
		} catch (\Exception $e) {
			$this->logger->error("Query error: " . $e->getMessage() . PHP_EOL);
			exit("Query error");
		}

		return $map;
	}

	public function showMapCombined(string $map_name, array $map_fields): Collection {
		$query = $this->getMapCombinedQuery($map_name, $map_fields);

		try {
			$map = $query
				->get();
		} catch (\Exception $e) {
			$this->logger->error("Query error: " . $e->getMessage() . PHP_EOL);
			exit("Query error");
		}

		return $map;
	}

	public function showPaginatedMapCombined(string $map_name, array $map_fields, int $page = 1, string $url): ?LengthAwarePaginator {
		$query = $this->getMapCombinedQuery($map_name, $map_fields);

		try {
			$map = $query
				->paginate($this->items_per_page, ['*'], 'page', $page)
				->withPath($url);
		} catch (\Exception $e) {
			$this->logger->error("Query error: " . $e->getMessage() . PHP_EOL);
			exit("Query error");
		}

		return $map;
	}

	public function mapEntryExists(string $model, string $map_name, array $map_fields, array $data): bool {
		if ($model === 'MapCombined') {
			$query = $this->getMapCombinedBasicQuery($map_name, $map_fields);
			foreach ($map_fields as $field) {
				$query = $query->where($field, $data[$field]);
			}
		/* deprecated
		} else if ($model === 'MapGeneric') {
			$query = $this->getMapGenericQuery($map_name);
			$query = $query->where('pattern', $data[$map_fields[0]]);
		*/
		} else if ($model === 'MapCustom') {
			$query = $this->getMapCustomQuery($map_name);
			//$query = $query->where('pattern', $data[$map_fields[0]]);
			// handle multi line entries
			$lines = preg_split('/\r\n|\r|\n/', trim($data[$map_fields[0]]));
			$values = array_unique(array_filter(array_map('trim', $lines)));
			$query = $query->whereIn('pattern', $values);
		} else {
			throw new \RuntimeException("Unknown map model");
		}

		// XXX strtolower might break some maps???
		$data = self::trimLower($data);

		$query = $this->applyUserRcptToScope($query);

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$this->logger->info(self::getSqlFromQuery($query));
		}

		return $query->exists();
	}

	public function mapExists(string $map_name): bool {
		$map_configs = MapInventory::getMapConfigs();

		foreach ($map_configs as $map => $map_config) {
			if (strtolower(trim($map)) === strtolower(trim($map_name))) {
				return true;
			}
			// this name works as a link for management. deny
			if (strtolower(trim($map_name)) === 'manage_custom_maps') {
				return true;
			}
		}

		return false;
	}

	public static function delMapActivityLog(string $map_name): bool {
		$log = MapActivityLog::find($map_name);

		if (!$log) {
			return true;
		}
		if ($log->delete($map_name)) {
			return true;
		}
		return false;
	}

	// deletes entrys from activity log if not found in config
	// delete map files if not found in config
	public function syncMaps(): void {
		// Get maps from config. Source of truth
		$configs = MapInventory::getMapConfigs();
		$validMapNames = array_keys($configs);

		// Get maps from activity logs (DB)
		$dbMapNames = MapActivityLog::pluck('map_name')->toArray();

		// Add missing maps from config into activity log and create map file
		$missingInDb = array_diff($validMapNames, $dbMapNames);
		foreach ($missingInDb as $mapName) {
			$this->updateMapActivityLog($mapName, date("Y-m-d H:i:s"));
		}

		// Delete DB entries not in config
		$extraInDb = array_diff($dbMapNames, $validMapNames);
		if (!empty($extraInDb)) {
			MapActivityLog::whereIn('map_name', $extraInDb)->delete();
		}

		// Delete leftovers map files not in config
		$mapDir = rtrim(Config::get('MAP_DIR'), DIRECTORY_SEPARATOR);
		if (!is_dir($mapDir)) {
			throw new \RuntimeException("Map directory not found: {$mapDir}");
		}

		$files = glob($mapDir . DIRECTORY_SEPARATOR . '*.txt');
		foreach ($files as $filePath) {
			$fileName = pathinfo($filePath, PATHINFO_FILENAME); // map name
			if (!in_array($fileName, $validMapNames, true)) {
				unlink($filePath);
			}
		}
	}

	public function updateMapActivityLog(string $map_name, string $last_update): bool {
		$map_activity_log = MapActivityLog::firstOrNew(['map_name' => $map_name]);
		$map_activity_log->last_changed_at = $last_update;

		if ($map_activity_log->save()) {
			return true;
		}
		return false;
	}

	 public static function delMapFile(string $map_name): bool {
		$map_dir = Config::get('MAP_DIR');
		$map_file = rtrim($map_dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $map_name . ".txt";

		if (!file_exists($map_file)) {
			return true;
		}

		if (unlink($map_file)) {
			return true;
		}
		return false;
	 }

	 public function updateMapFile(
		string $model,
		string $map_name,
		string $last_update,
		?array $map_fields = null
	): bool {

		$map_dir = Config::get('MAP_DIR');

		$tmpfile = tempnam($map_dir, "{$map_name}_");

		if (!$fp = fopen($tmpfile, "w")) {
			return false;
		}

		$lastModified = strtotime($last_update);
		$header = '# Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastModified) . " GMT";
		$lines = [];
		array_unshift($lines, $header); // Add header at the top

		if ($model === 'MapCombined') {
			$query = $this->getMapCombinedBasicQuery($map_name, $map_fields);
			$query = $query->where('disabled', 0);
			$map_entries = $query->get()->toArray();
			foreach ($map_entries as $row) {
				$values = array_map(fn($field) => $row[$field] ?? '', $map_fields);
				// Skip the line if any value is empty
				if (in_array('', $values, true)) {
					continue;
				}
				$lines[] = implode('|', $values);
			}
		/* deprecated
		} elseif ($model === 'MapGeneric') {
			$query = $this->getMapGenericQuery($map_name);
			$map_entries = $query->get()->toArray();
			foreach ($map_entries as $row) {
				// Skip if 'pattern' is missing or empty
				if (empty($row['pattern'])) {
					continue;
				}
				$pattern = $row['pattern'];
				$score = $row['score'] ?? '';
				$lines[] = trim("$pattern $score");
			}
		*/
		} elseif ($model === 'MapCustom') {
			$query = $this->getMapCustomQuery($map_name);
			$query = $query->where('disabled', 0);
			$map_entries = $query->get()->toArray();
			foreach ($map_entries as $row) {
				// Skip if 'pattern' is missing or empty
				if (empty($row['pattern'])) {
					continue;
				}
				$pattern = $row['pattern'];
				$score = $row['score'] ?? '';
				$lines[] = trim("$pattern $score");
			}
		} else {
			return false;
		}

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$this->logger->info(self::getSqlFromQuery($query));
		}

		$contents = implode(PHP_EOL, $lines);

		fwrite($fp, $contents . PHP_EOL);
		fflush($fp);
		fclose($fp);

		$map_file = rtrim($map_dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $map_name . ".txt";
		if (!rename($tmpfile, $map_file)) {
		   unlink($tmpfile);
			return false;
		}

		chmod($map_file, Config::get('MAP_FILE_PERM'));
		// Set mtime
		touch($map_file, $lastModified);

		return true;
	}

	private static function trimLower(array $data): array {
		// trim and strtolower entries
		$data = array_map(function ($value) {
			return is_string($value) ? strtolower(trim($value)) : $value;
		}, $data);

		return $data;
	}

	public function addMapCombinedEntry(string $map_name, array $map_fields, array $data): bool {
		if (empty($data)) {
			$this->logger->error("Empty map data");
			return false;
		}

		if (empty($this->user_id)) {
			$this->logger->warning("[updateMapCombinedEntry] empty user_id");
			return false;
		}

		// XXX strtolower might break some maps???
		$data = self::trimLower($data);

		// applyUseScope
		if (!$this->is_admin && !defined('CLI_MODE') && in_array('rcpt_to', $map_fields)) {
			if (empty($data['rcpt_to'])) {
				$this->logger->warning("[addMapCombinedEntry] Missing rcpt_to in data for user {$this->username}");
				return false;
			}
			$allowedRcptTo = array_unique(array_filter(array_merge([$this->email], $this->user_aliases ?? [])));
			if (!in_array($data['rcpt_to'], $allowedRcptTo)) {
				$this->logger->warning("rcpt_to value '{$data['rcpt_to']}' is not allowed for user {$this->username}");
				return false;
			}
		}

		// update map table in DB
		$data['map_name'] = $map_name;
		$data['user_id'] = $this->user_id;
		$mapcombined = new MapCombined();
		$mapcombined->fill($data);

		try {
			$success = $mapcombined->save();

			if (!$success) {
				// Insert did not throw an error, but still failed
				$this->logger->error("[addMapCombinedEntry] Query save failed");
				return false;
			}
		} catch (\Throwable $e) {
			$this->logger->error("[addMapCombinedEntry] Query save error: " . $e->getMessage() . PHP_EOL);
			return false;
		}
		/*
		if (!$mapcombined->save()) {
			return false;
		}
		*/

		$last_update = date("Y-m-d H:i:s");

		// update map file
		if (!self::updateMapFile('MapCombined', $map_name, $last_update, $map_fields)) {
			return false;
		}

		// update Activity log table in DB
		if (!self::updateMapActivityLog($map_name, $last_update)) {
			return false;
		}

		return true;
	}

	public function updateMapCombinedEntry(string $map_name, array $map_fields, array $data): bool {
		if (empty($data) || empty($data['map_name']) || $map_name !== $data['map_name']) {
			$this->logger->error("Empty or invalid map data");
			return false;
		}

		if (empty($this->user_id)) {
			$this->logger->warning("[updateMapCombinedEntry] empty user_id");
			return false;
		}

		// XXX strtolower might break some maps???
		$data = self::trimLower($data);

		// applyUseScope
		if (!$this->is_admin && !defined('CLI_MODE') && in_array('rcpt_to', $map_fields)) {
			if (empty($data['rcpt_to'])) {
				$this->logger->warning("[updateMapCombinedEntry] Missing rcpt_to in data for user {$this->username}");
				return false;
			}
			$allowedRcptTo = array_unique(array_filter(array_merge([$this->email], $this->user_aliases ?? [])));
			if (!in_array($data['rcpt_to'], $allowedRcptTo)) {
				$this->logger->warning("rcpt_to value '{$data['rcpt_to']}' is not allowed for user {$this->username}");
				return false;
			}
		}

		if (!$this->is_admin && $this->user_id !== $data['user_id']) {
			$this->logger->warning("[updateMapCombinedEntry] {$this->username} tried to update map entry {$data['id']} for map {$map_name} without permission");
			return false;
		}

		$new_data = [];
		foreach ($map_fields as $field) {
			$new_data[$field] = $data[$field];
		}
		if (empty($new_data)) {
			$this->logger->warning("[updateMapCombinedEntry] Empty new data");
			return false;
		}

		try {
			$success = MapCombined::where('id', $data['id'])
			                      ->update($new_data);

			if (!$success) {
				// Insert did not throw an error, but still failed
				$this->logger->error("[addMapCombinedEntry] Query save failed");
				return false;
			}
		} catch (\Throwable $e) {
			$this->logger->error("[addMapCombinedEntry] Query save error: " . $e->getMessage() . PHP_EOL);
			return false;
		}

		$last_update = date("Y-m-d H:i:s");

		// update map file
		if (!self::updateMapFile('MapCombined', $map_name, $last_update, $map_fields)) {
			return false;
		}

		// update Activity log table in DB
		if (!self::updateMapActivityLog($map_name, $last_update)) {
			return false;
		}

		return true;
	}

	// deprecated
	public function addMapGenericEntry(string $map_name, string $pattern): bool {
		if (empty($pattern)) {
			$this->logger->error("Empty map pattern");
			return false;
		}

		// XXX strtolower might break some maps???
		$pattern = strtolower(trim($pattern));

		// update map table in DB
		$mapgeneric = new MapGeneric();
		$data = array(
			'map_name' => $map_name,
			'pattern' => $pattern
		);

		$mapgeneric->fill($data);
		if (!$mapgeneric->save()) {
			return false;
		}

		$last_update = date("Y-m-d H:i:s");

		// update map file
		if (!self::updateMapFile('MapGeneric', $map_name, $last_update)) {
			return false;
		}

		// update Activity log table in DB
		if (!self::updateMapActivityLog($map_name, $last_update)) {
			return false;
		}

		return true;
	}

	public function addMapCustomEntry(string $map_name, string $pattern): bool {
		if (empty($pattern)) {
			$this->logger->error("Empty map pattern");
			return false;
		}

		/* one line textfield code
		$pattern = trim($pattern);

		// update map table in DB
		$mapcustom = new MapCustom();
		$data = array(
			'map_name' => $map_name,
			'pattern' => $pattern
		);

		$mapcustom->fill($data);
		if (!$mapcustom->save()) {
			return false;
		}
		*/

		// handle multiple lines
		$lines = preg_split('/\r\n|\r|\n/', trim($pattern));

		// Remove empty lines and trim whitespace from each
		$values = array_filter(array_map('trim', $lines));

		if (count($values) === 0) {
			return false;
		}

		$rows = [];
		foreach ($values as $value) {
			$disabled = 0;
			// If first character is #, insert as disabled entry
			if (strlen($value) > 0 && $value[0] === '#') {
				$value = substr($value, 1); // remove #
				$disabled = 1;
			}
			$rows[] = [
				'map_name' => $map_name,
				'pattern'  => $value,
				'disabled'  => $disabled,
			];
		}

		try {
			$success = MapCustom::insert($rows);

			if (!$success) {
				// Insert did not throw an error, but still failed
				$this->logger->error("[addMapCustomEntry] Query insert failed");
				return false;
			}

		} catch (\Throwable $e) {
			$this->logger->error("[addMapCustomEntry] Query insert error: " . $e->getMessage() . PHP_EOL);
			return false;
		}
		/*
		if (!MapCustom::insert($rows)) {
			return false;
		}
		*/

		$last_update = date("Y-m-d H:i:s");

		// update map file
		if (!self::updateMapFile('MapCustom', $map_name, $last_update)) {
			return false;
		}

		// update Activity log table in DB
		if (!self::updateMapActivityLog($map_name, $last_update)) {
			return false;
		}

		return true;
	}

	public function updateMapCustomEntry(string $map_name, array $data): bool {
		if (empty($data) || empty($data['map_name']) || $map_name !== $data['map_name']) {
			$this->logger->error("Empty or invalid map data");
			return false;
		}

		$disabled = $data['disabled'];

		$value = $data['pattern'];
		if (strlen($value) > 0) {
			// If first character is #, insert as disabled entry
			if($value[0] === '#') {
				$value = substr($value, 1); // remove #
				$disabled = 1;
			} else {
				$disabled = 0;
			}
		}

		try {
			$success = MapCustom::where('id', $data['id'])
			                    ->update([
			                       'pattern' => $value,
			                       'disabled' => $disabled,
									    ]);

			if (!$success) {
				// Update did not throw an error, but still failed
				$this->logger->error("[updateMapCustomEntry] Query update failed");
				return false;
			}

		} catch (\Throwable $e) {
			$this->logger->error("[updateMapCustomEntry] Query update error: " . $e->getMessage() . PHP_EOL);
			return false;
		}

		$last_update = date("Y-m-d H:i:s");

		// update map file
		if (!self::updateMapFile('MapCustom', $map_name, $last_update)) {
			return false;
		}

		// update Activity log table in DB
		if (!self::updateMapActivityLog($map_name, $last_update)) {
			return false;
		}

		return true;
	}

	public function addCustomMapConfig(array $data): bool {
		if (empty($data)) {
			$this->logger->error("Empty map data in addCustomMapConfig");
			return false;
		}

		if (empty($data['map_name'])) {
			$this->logger->error("Empty map_name in addCustomMapConfig");
			return false;
		}
		$map_name = $data['map_name'];

		$customMapConfig = new CustomMapConfig();

		$customMapConfig->fill($data);
		if (!$customMapConfig->save()) {
			return false;
		}

		$last_update = date("Y-m-d H:i:s");

		// update map file
		if (!self::updateMapFile('MapCustom', $map_name, $last_update)) {
			$this->logger->error("Error updating map file for '{$map_name}' in addCustomMapConfig");
			return false;
		}

		// update Activity log table in DB
		if (!self::updateMapActivityLog($map_name, $last_update)) {
			$this->logger->error("Error updating map activity log for '{$map_name}' in addCustomMapConfig");
			return false;
		}

		return true;
	}

	public function updateCustomMapConfig(array $data): bool {
		if (empty($data)) {
			$this->logger->error("Empty map data in updateCustomMapConfig");
			return false;
		}

		if (empty($data['map_name'])) {
			$this->logger->error("Empty map_name in updateCustomMapConfig");
			return false;
		}
		$map_name = $data['map_name'];

		$customMapConfig = CustomMapConfig::find($data['id']);

		if (!$customMapConfig) {
			$this->logger->error("[updateCustomMapConfig] CustomMapConfig with id '{$data['id']}' not found");
			return false;
		}

		$org_map_name = $customMapConfig->map_name;

		// Update custom_map_config
		$customMapConfig->fill($data);
		try {
			$success = $customMapConfig->save();

			if (!$success) {
				// Update did not throw an error, but still failed
				$this->logger->error("[updateCustomMapConfig] Query update failed");
				return false;
			}
		} catch (\Throwable $e) {
			$this->logger->error("[updateCustomMapConfig] Query update error: " . $e->getMessage() . PHP_EOL);
			return false;
		}

		// map_name change
		if ($org_map_name !== $map_name) {

			$this->logger->info("Map '{$org_map_name}' rename to '{$map_name}' requested. Updating MapCustom entries and MapActivityLog");
			// Update map_name in maps_custom entries
			MapCustom::where('map_name', $org_map_name)
			         ->update(['map_name' => $map_name]);

			// Delete old Activity log table in DB
			MapActivityLog::where('map_name', $org_map_name)->delete();

			// old map file will be deleted by syncMaps() from CronUpdateMapFiles
		}

		$last_update = date("Y-m-d H:i:s");

		// 3. update map file
		if (!self::updateMapFile('MapCustom', $map_name, $last_update)) {
			$this->logger->error("Error updating map file for '{$map_name}' in updateCustomMapConfig");
			return false;
		}

		// 4. update Activity log table in DB
		if (!self::updateMapActivityLog($map_name, $last_update)) {
			$this->logger->error("Error updating map activity log for '{$map_name}' in addCustomMapConfig");
			return false;
		}

		return true;
	}

	public function delCustomMap(int $id): bool {
		if (is_null($id) or !is_int($id)) {
			return false;
		}

		$custom_map = CustomMapConfig::find($id);
		if (is_null($custom_map)) {
			return false;
		}

		// delete map entries
		foreach ($custom_map->MapsCustom as $map_entry) {
			// delete map entry from db
			if (!$map_entry->delete()) {
				return false;
			}
		}

		// delete map from activity log
		if (!self::delMapActivityLog($custom_map->map_name)) {
			return false;
		}

		// XXX test for left overs
		// delete map file if it exists
		//if (!self::delMapFile($custom_map->map_name)) {
		//	return false;
		//}

		if (!$custom_map->delete()) {
			return false;
		}

		return true;
	}

	public function delMapEntry(string $model, string $map_name, array $map_fields, int $id): bool {
		if (is_null($id) or !is_int($id)) {
			return false;
		}

		if ($model === 'MapCombined') {
			$query = $this->getMapCombinedBasicQuery($map_name, $map_fields);
			$query = $this->applyUserRcptToScope($query);
			$query = $query->where('id', $id);

			/* XXX
		   if USER_CAN_SEE_ADMIN_MAP_ENTRIES is false
			applyUserRcptToScope() will limit the query and even if
			USER_CAN_DEL_ADMIN_MAP_ENTRIES is true,
			user will not be able to delete the entry
			*/
			if (!$this->is_admin && !Config::get('USER_CAN_DEL_ADMIN_MAP_ENTRIES')) {
				$query = $query->where('user_id', $this->user_id);
			}

		/* deprecated
		} else if ($model === 'MapGeneric') {
			$query = $this->getMapGenericQuery($map_name);
			$query = $query->where('id', $id);
		*/
		} else if ($model === 'MapCustom') {
			$query = $this->getMapCustomQuery($map_name);
			$query = $query->where('id', $id);
		} else {
			return false;
		}

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$this->logger->info(self::getSqlFromQuery($query));
		}

		$map_entry = $query->first();

		// entry not found
		if (!$map_entry) {
			return false;
		}

		// delete map entry from db
		if (!$map_entry->delete()) {
			return false;
		}

		$last_update = date("Y-m-d H:i:s");

		// update map file
		if (!self::updateMapFile($model, $map_name, $last_update, $map_fields)) {
			return false;
		}

		// update Activity log table in DB
		if (!self::updateMapActivityLog($map_name, $last_update)) {
			return false;
		}

		return true;
	}

	public function delMapAllEntries(string $model, string $map_name, array $map_fields): bool {

		if ($model === 'MapCombined') {
			$query = $this->getMapCombinedBasicQuery($map_name, $map_fields);
			$query = $this->applyUserRcptToScope($query);

			/* XXX
		   if USER_CAN_SEE_ADMIN_MAP_ENTRIES is false
			applyUserRcptToScope() will limit the query and even if
			USER_CAN_DEL_ADMIN_MAP_ENTRIES is true,
			user will not be able to delete the entry
			*/
			if (!$this->is_admin && !Config::get('USER_CAN_DEL_ADMIN_MAP_ENTRIES')) {
				$query = $query->where('user_id', $this->user_id);
			}

		} else if ($model === 'MapCustom') {
			$query = $this->getMapCustomQuery($map_name);
		} else {
			return false;
		}

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$this->logger->info(self::getSqlFromQuery($query));
		}

		// delete all entries matched by the query
		$deletedRows = $query->delete();

		if ($deletedRows === 0) {
			return false;
		}

		$last_update = date("Y-m-d H:i:s");

		// update map file
		if (!self::updateMapFile($model, $map_name, $last_update, $map_fields)) {
			return false;
		}

		// update Activity log table in DB
		if (!self::updateMapActivityLog($map_name, $last_update)) {
			return false;
		}

		return true;
	}

	public function toggleMapEntry(string $model, string $map_name, array $map_fields, int $id): bool {
		if (is_null($id) or !is_int($id)) {
			return false;
		}

		if ($model === 'MapCombined') {
			$query = $this->getMapCombinedBasicQuery($map_name, $map_fields);
			$query = $this->applyUserRcptToScope($query);
			$query = $query->where('id', $id);

			/* XXX
		   if USER_CAN_SEE_ADMIN_MAP_ENTRIES is false
			applyUserRcptToScope() will limit the query and even if
			USER_CAN_DEL_ADMIN_MAP_ENTRIES is true,
			user will not be able to delete the entry
			*/
			if (!$this->is_admin && !Config::get('USER_CAN_DEL_ADMIN_MAP_ENTRIES')) {
				$query = $query->where('user_id', $this->user_id);
			}

		/* deprecated
		} else if ($model === 'MapGeneric') {
			$query = $this->getMapGenericQuery($map_name);
			$query = $query->where('id', $id);
		*/
		} else if ($model === 'MapCustom') {
			$query = $this->getMapCustomQuery($map_name);
			$query = $query->where('id', $id);
		} else {
			return false;
		}

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$this->logger->info(self::getSqlFromQuery($query));
		}

		$map_entry = $query->first();

		// entry not found
		if (!$map_entry) {
			return false;
		}

		// toggle disabled field in db
		$map_entry->disabled = !$map_entry->disabled;
		if (!$map_entry->save()) {
			return false;
		}

		$last_update = date("Y-m-d H:i:s");

		// update map file
		if (!self::updateMapFile($model, $map_name, $last_update, $map_fields)) {
			return false;
		}

		// update Activity log table in DB
		if (!self::updateMapActivityLog($map_name, $last_update)) {
			return false;
		}

		return true;
	}

}
