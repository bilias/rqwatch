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

namespace App\Services;

use App\Core\Config;
use App\Utils\Helper;
use App\Utils\FormHelper;

use App\Models\MapCombined;
use App\Models\MapActivityLog;

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

	public static function getSqlFromQuery(Builder $query): string {
		return vsprintf(str_replace('?', '"%s"', $query->toSql()), $query->getBindings());
	}

	public function getMapCombinedBasicQuery(string $map_name, array $map_fields): Builder {
		$select_fields = array_merge(MapCombined::SELECT_FIELDS, $map_fields);

		$query = MapCombined::select($select_fields)
								  ->where('map_name', $map_name);

		foreach ($map_fields as $field) {
			$query = $query->whereNotNull($field);
		}

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$this->logger->info(self::getSqlFromQuery($query));
		}

		return $query;
	}

	protected function applyUserScope($query): Builder {
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

	public function showMapCombined(string $map_name, array $map_fields): Collection {
		$query = $this->getMapCombinedBasicQuery($map_name, $map_fields);

		$query = $query->with(['user' => function ($query) {
							  $query->select('id', 'username', 'email');
							}]);

		$query = $this->applyUserScope($query);

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$this->logger->info(self::getSqlFromQuery($query));
		}

		$map = $query->get();
		return $map;
	}

	public function showPaginatedAllMapCombined(int $page = 1, string $url, ?array $maps): ?LengthAwarePaginator {
		$query = MapCombined::select('*')
								  ->with(['user' => function ($query) {
										$query->select('id', 'username', 'email');
								    }])
								  ->orderBy('map_name', 'ASC')
								  ->orderBy('updated_at', 'DESC');

		// filter maps
		if (!$this->is_admin && $maps) {
			$quuery = $query->whereIn('map_name', $maps);
		}

		$query = $this->applyUserScope($query);

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

	public function showPaginatedMapCombined(string $map_name, array $map_fields, int $page = 1, string $url): ?LengthAwarePaginator {

		$query = $this->getMapCombinedBasicQuery($map_name, $map_fields);
		$query = $query->with(['user' => function ($query) {
								 $query->select('id', 'username', 'email');
							  }]);

		$query = $this->applyUserScope($query);

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

	public function mapCombinedEntryExists(string $map_name, array $map_fields, array $data): bool {
		$query = $this->getMapCombinedBasicQuery($map_name, $map_fields);

		// XXX strtolower might break some maps???
		$data = self::trimLower($data);

		foreach ($map_fields as $field) {
			$query = $query->where($field, $data[$field]);
		}

		$query = $this->applyUserScope($query);

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$this->logger->info(self::getSqlFromQuery($query));
		}

		return $query->exists();
	}

	public function updateMapActivityLog(string $map_name, string $last_update): bool {
		$map_activity_log = MapActivityLog::firstOrNew(['map_name' => $map_name]);
		$map_activity_log->last_changed_at = $last_update;

		if ($map_activity_log->save()) {
			return true;
		}
		return false;
	}

	 public function updateMapFile(string $map_name, array $map_fields, string $last_update): bool {
		$map_dir = Config::get('MAP_DIR');

		$tmpfile = tempnam($map_dir, "{$map_name}_");

		if (!$fp = fopen($tmpfile, "w")) {
			return false;
		}

		$query = $this->getMapCombinedBasicQuery($map_name, $map_fields);

		$map_entries = $query->get()->toArray();

		$lastModified = strtotime($last_update);
		$header = '# Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastModified) . " GMT";
		$lines = [];
		array_unshift($lines, $header); // Add header at the top

		foreach ($map_entries as $row) {
			$values = array_map(fn($field) => $row[$field] ?? '', $map_fields);
			// Skip the line if any value is empty
			if (in_array('', $values, true)) {
				continue;
			}
			$lines[] = implode('|', $values);
		}
		$contents = implode(PHP_EOL, $lines);

		fwrite($fp, $contents);
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
		if (!$mapcombined->save()) {
			return false;
		}

		$last_update = date("Y-m-d H:i:s");

		// update map file
		if (!self::updateMapFile($map_name, $map_fields, $last_update)) {
			return false;
		}

		// update Activity log table in DB
		if (!self::updateMapActivityLog($map_name, $last_update)) {
			return false;
		}

		return true;
	}

	public function delMapCombinedEntry(string $map_name, array $map_fields, int $id): bool {
		if (is_null($id) or !is_int($id)) {
			return false;
		}

		$query = $this->getMapCombinedBasicQuery($map_name, $map_fields);

		$query = $this->applyUserScope($query);

		$query = $query->where('id', $id);

		/* XXX
		   if USER_CAN_SEE_ADMIN_MAP_ENTRIES is false
			applyUserScope() will limit the query and even if
			USER_CAN_DEL_ADMIN_MAP_ENTRIES is true,
			user will not be able to delete the entry
		*/
		if (!Config::get('USER_CAN_DEL_ADMIN_MAP_ENTRIES')) {
			$query = $query->where('user_id', $this->user_id);
		}

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$this->logger->info(self::getSqlFromQuery($query));
		}

		$map_entry = $query->first();

		// entry not found
		if (!$map_entry) {
			return false;
		}

		// deleted entry from map table db
		if (!$map_entry->delete()) {
			return false;
		}

		$last_update = date("Y-m-d H:i:s");

		// update map file
		if (!self::updateMapFile($map_name, $map_fields, $last_update)) {
			return false;
		}

		// update Activity log table in DB
		if (!self::updateMapActivityLog($map_name, $last_update)) {
			return false;
		}

		return true;
	}

}
