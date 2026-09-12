<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Services;

use App\Configuration\Config;

use App\Core\App;

use App\Utils\Helper;

use App\Models\MailAlias;
use App\Models\MapCombined;

use App\Inventory\MapInventory;

use Exception;

use Psr\Log\LoggerInterface;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class MailAliasService
{
	private LoggerInterface $logger;

	private int $items_per_page;
	private int $max_items;

	public function __construct() {
		$this->logger = App::fileLogger();

		$this->items_per_page = Config::get('items_per_page');
		$this->max_items = Config::get('max_items');
	}

	public static function getSqlFromQuery(Builder $query): string {
		return vsprintf(str_replace('?', '"%s"', $query->toSql()), $query->getBindings());
	}

	public function getSearchQuery(int $limit=null): Builder {
		if ($limit) {
			$query = MailAlias::with('user')
									  ->join('users', 'mail_aliases.user_id', '=', 'users.id')
									  ->orderBy('mail_aliases.updated_at', 'desc')
									  ->orderBy('users.username', 'asc')
									  ->orderBy('mail_aliases.alias', 'asc')
									  ->select(['mail_aliases.*',
												   'users.username as username',
													'users.email as email'])
								     ->limit($limit);
		} else {
			$query = MailAlias::with('user')
									  ->join('users', 'mail_aliases.user_id', '=', 'users.id')
									  ->orderBy('mail_aliases.updated_at', 'desc')
									  ->orderBy('users.username', 'asc')
									  ->orderBy('mail_aliases.alias', 'asc')
									  ->select(['mail_aliases.*',
												   'users.username as username',
													'users.email as email'
												  ]);
		}

		return $query;
	}

	public function aliasExists(int $user_id, string $alias): bool {
		$query = self::getSearchQuery();
		$query = $query->where('user_id', $user_id)
							->where('alias', $alias);

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$this->logger->info(self::getSqlFromQuery($query));
		}

		return $query->exists();
	}


	/*
	 Map entries the user created for this alias go with it: the address is
	 not theirs any more. Scoped by user_id as well as rcpt_to, so a shared
	 alias only loses the entries of the user giving it up, and restricted
	 to maps a user may manage so admin-only entries are never touched.
	*/
	public function aliasDel(MailAlias $alias, string $actingUsername): bool {
		$address = strtolower(trim((string) $alias->alias));
		$user_id = $alias->user_id;
		$userMaps = MapInventory::getRoleMapsByModel('MapCombined', 'user');

		try {
			return App::capsule()->connection()->transaction(
				function () use ($alias, $address, $user_id, $userMaps, $actingUsername) {
					if ($address !== '' && !empty($userMaps)) {
						$deleted = MapCombined::where('user_id', $user_id)
							->whereIn('map_name', $userMaps)
							->where('rcpt_to', $address)
							->delete();

						if ($deleted > 0) {
							$this->logger->info(
								"aliasDel: deleted {$deleted} map entries for alias"
								. " '{$address}' by '{$actingUsername}'"
							);
						}
					}

					return (bool) $alias->delete();
				}
			);
		} catch (Exception $e) {
			$this->logger->error(
				"aliasDel error: delete of '{$address}' by '{$actingUsername}'"
				. " failed: " . $e->getMessage()
			);

			return false;
		}
	}

	public function getPaginatedAll(string $url, int $page = 1): LengthAwarePaginator {
		$fields = MailAlias::SELECT_FIELDS;

		$query = self::getSearchQuery();

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$this->logger->info(self::getSqlFromQuery($query));
		}

		try {
			$aliases = $query
				->paginate($this->items_per_page, $fields, 'page', $page)
				->withPath($url);
		} catch (Exception $e) {
			$this->logger->error("Query error: " . $e->getMessage() . PHP_EOL);
			Helper::failRequest("Query error");
		}

		return $aliases;
	}

	public function searchPaginatedAll(string $url, string $search, int $page = 1): LengthAwarePaginator {
		$fields = MailAlias::SELECT_FIELDS;

		$query = self::getSearchQuery();
		$query->where('username', 'LIKE', "%{$search}%")
		      ->orWhere('email', 'LIKE', "%{$search}%")
		      ->orWhere('alias', 'LIKE', "%{$search}%");

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$this->logger->info(self::getSqlFromQuery($query));
		}

		try {
			$aliases = $query
				->paginate($this->items_per_page, $fields, 'page', $page)
				->withPath($url);
		} catch (Exception $e) {
			$this->logger->error("Query error: " . $e->getMessage() . PHP_EOL);
			Helper::failRequest("Query error");
		}

		return $aliases;
	}

}
