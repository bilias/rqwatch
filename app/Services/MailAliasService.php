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

use App\Models\MailAlias;
use App\Models\User;

use Exception;

use Psr\Log\LoggerInterface;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;

use Symfony\Component\HttpFoundation\Session\Session;

class MailAliasService
{
	private ?string $username = null;
	private LoggerInterface $logger;

	public function __construct(LoggerInterface $logger, ?Session $session = null) {
		$this->logger = $logger;

		if (!empty($session)) {
			$this->username = $session->get('username');
		}

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


	public function showAll(): Collection {
		$query = self::getSearchQuery();

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$this->logger->info(self::getSqlFromQuery($query));
		}

		return $query->get();
	}

	public function showOne(int $id): ?MailAlias {
		$query = self::getSearchQuery();
		$query = $query->where('mail_aliases.id', $id);

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$this->logger->info(self::getSqlFromQuery($query));
		}

		return $query->first();
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

	public function showPaginatedAll(string $url, int $page = 1): ?LengthAwarePaginator {
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
			exit("Query error");
		}

		return $aliases;
	}

	public function searchPaginatedAll(string $url, string $search, int $page = 1): ?LengthAwarePaginator {
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
			exit("Query error");
		}

		return $aliases;
	}

	public function showPaginatedAliases(string $url, int $page = 1): ?LengthAwarePaginator {
		$fields = User::SELECT_FIELDS;

		if ($this->max_items) {
			$query = User::with('mailAliases')
				->select($fields)
				->orderBy('username', 'ASC')
				->limit($this->max_items);
		} else {
			$query = User::with('mailAliases')
				->select($fields)
				->orderBy('username', 'ASC');
		}

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$this->logger->info(self::getSqlFromQuery($query));
		}

		try {
			$logs = $query
				->paginate($this->items_per_page, $fields, 'page', $page)
				->withPath($url);
		} catch (Exception $e) {
			$this->logger->error("Query error: " . $e->getMessage() . PHP_EOL);
			exit("Query error");
		}

		return $logs;
	}

}
