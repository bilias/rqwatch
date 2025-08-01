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

use App\Models\User;
use App\Models\MailAlias;

use Psr\Log\LoggerInterface;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;

use Symfony\Component\HttpFoundation\Session\Session;

class UserService
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

	public function getSearchQuery(array $fields, int $limit=null): Builder {
		if ($limit) {
			$query = User::select($fields)
				->orderBy('id', 'DESC')
				->limit($limit);
		} else {
			$query = User::select($fields)
				->orderBy('id', 'DESC');
		}

		return $query;
	}


	public function showAll(): Collection {
		$fields = User::SELECT_FIELDS;

		$query = User::select($fields)
					->orderBy('id', 'DESC')
					->limit($this->max_items);

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$this->logger->info(self::getSqlFromQuery($query));
		}

		$logs = $query->get();

		return $logs;
	}

	public function showOne(int $id): ?User {
		$query = User::where('id', $id);

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$this->logger->info(self::getSqlFromQuery($query));
		}

		$user = $query->first();

		return $user;
	}

	public function showOneByUsername(string $username): ?User {
		$query = User::where('username', $username);

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$this->logger->info(self::getSqlFromQuery($query));
		}

		$user = $query->first();

		return $user;
	}

	public function showOneByEmail(string $email): ?User {
		$query = User::where('email', $email);

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$this->logger->info(self::getSqlFromQuery($query));
		}

		$user = $query->first();

		return $user;
	}

	public function profile(): ?User {
		$fields = [
			'id',
			'username',
			'email',
			'firstname',
			'lastname',
			'last_login',
			'auth_provider',
			'disable_notifications',
			'is_admin',
			'created_at',
			'updated_at',
		];

		$query = User::select($fields)
			// show profile only for DB Users
			//->where('auth_provider', 0)
			->where('username', $this->username);

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$this->logger->info(self::getSqlFromQuery($query));
		}

		$logs = $query->first();

		return $logs;
	}

	public function showPaginatedAll(int $page = 1, string $url): ?LengthAwarePaginator {
		$fields = User::SELECT_FIELDS;

		$query = self::getSearchQuery($fields);

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$this->logger->info(self::getSqlFromQuery($query));
		}

		try {
			$logs = $query
				->paginate($this->items_per_page, $fields, 'page', $page)
				->withPath($url);
		} catch (\Exception $e) {
			$this->logger->error("Query error: " . $e->getMessage() . PHP_EOL);
			exit("Query error");
		}

		return $logs;
	}

	public function showPaginatedAliases(int $page = 1, string $url): ?LengthAwarePaginator {
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
		} catch (\Exception $e) {
			$this->logger->error("Query error: " . $e->getMessage() . PHP_EOL);
			exit("Query error");
		}

		return $logs;
	}

	public function notificationsDisabledFor(string $email): bool {
		$user = User::where('email', $email)->first();

		// check if email matches a user's email
		if ($user) {
			return $user->disable_notifications;
		}

		// check if email matches an alias
		$alias = MailAlias::with('user')->where('alias', $email)->first();

		if ($alias && $alias->user) {
			return $alias->user->disable_notifications;
		}

		// not found, notifications enabled by default
		return false;
	}

}
