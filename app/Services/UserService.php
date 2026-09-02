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

use App\Models\User;
use App\Models\MailAlias;

use Psr\Log\LoggerInterface;

use App\Core\Cache\RedisCache;
use App\Core\Auth\LoginThrottle;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

use Exception;

class UserService
{
	private ?string $username = null;
	private LoggerInterface $logger;

	public function __construct() {
		$this->logger = App::fileLogger();

		$this->items_per_page = Config::get('items_per_page');
		$this->max_items = Config::get('max_items');
	}

	public static function getSqlFromQuery(Builder $query): string {
		return vsprintf(str_replace('?', '"%s"', $query->toSql()), $query->getBindings());
	}

	public function getSearchQuery(array $fields, int $limit=null): Builder {
		if ($limit) {
			$query = User::select($fields)
				->orderBy('last_login', 'DESC')
				->limit($limit);
		} else {
			$query = User::select($fields)
				->orderBy('last_login', 'DESC');
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

		return $query->get();
	}

	public function showOne(int $id): ?User {
		$query = User::where('id', $id);

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$this->logger->info(self::getSqlFromQuery($query));
		}

		return $query->first();
	}

	public function showOneByUsername(string $username): ?User {
		$query = User::where('username', $username);

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$this->logger->info(self::getSqlFromQuery($query));
		}

		return $query->first();
	}

	public function showOneByEmail(string $email): ?User {
		$query = User::where('email', $email);

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$this->logger->info(self::getSqlFromQuery($query));
		}

		return $query->first();
	}

	public function profile(string $username): ?User {
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
			->where('username', $username);

		if (Helper::env_bool('DEBUG_SEARCH_SQL')) {
			$this->logger->info(self::getSqlFromQuery($query));
		}

		return $query->first();
	}

	public function showPaginatedAll(string $url, int $page = 1): ?LengthAwarePaginator {
		$fields = User::SELECT_FIELDS;

		$query = self::getSearchQuery($fields);

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

	public function searchPaginatedAll(int $page = 1, string $url, string $search): ?LengthAwarePaginator {
		$fields = User::SELECT_FIELDS;

		$query = self::getSearchQuery($fields);
		$query = $query->where('username', 'LIKE', "%{$search}%")
		               ->orWhere('email', 'LIKE', "%{$search}%")
		               ->orWhere('firstname', 'LIKE', "%{$search}%")
		               ->orWhere('lastname', 'LIKE', "%{$search}%");

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
		} catch (Exception $e) {
			$this->logger->error("Query error: " . $e->getMessage() . PHP_EOL);
			exit("Query error");
		}

		return $logs;
	}

	public function notificationsDisabledFor(string $email): bool {
		$email = strtolower(trim($email));

		$user = User::where('email', $email)->first();

		// check if email matches a user's email
		if ($user) {
			return (bool) $user->disable_notifications;
		}

		// check if email matches an alias
		$alias = MailAlias::with('user')->where('alias', $email)->first();

		if ($alias && $alias->user) {
			return (bool) $alias->user->disable_notifications;
		}

		// not found, notifications enabled by default
		return false;
	}

	public function userExists(string $user): bool {
		if (empty($user)) {
			return false;
		}

		return User::where('username', strtolower(trim($user)))->exists();
	}

	public function userAdd(array $data): bool {
		if (empty($data) || empty($data['username'])) {
			return false;
		}

		try {
			$user = new User;
			$data['username'] = strtolower(trim($data['username']));
			$user->fill($data);
			$user->password = $data['password'];
			$user->save();

			if ($user) {
				return true;
			}
			return false;
		} catch (Exception $e) {
			$this->logger->error("userAdd error: " . $e->getMessage() . PHP_EOL);
			return false;
		}

		return false;
	}

	public function userDel(int $id): bool {
      $user = User::find($id);

      if (!$user) {
			$this->logger->error('userDel error: User not found');
         return false;
      }

      if ($user->username === 'admin') {
			$this->logger->error("userDel error: User 'admin' cannot be deleted");
         return false;
      }

      $username = $user->username;

      if ($user->delete()) {
			return true;
      }

		return false;
   }

	public function getLoginThrottles(): array {
		$cache = App::cache();
		$cache = $cache instanceof RedisCache ? $cache : null;

		if ($cache === null) {
			return ['blocked' => [], 'counters' => []];
		}

		return [
			'blocked'  => LoginThrottle::listBlocked($cache),
			'counters' => LoginThrottle::listCounters($cache),
		];
	}

	public function clearLoginThrottle(string $ip): bool {
		$ip = trim($ip);

		if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
			$this->logger->warning("[clearLoginThrottle] invalid IP: '{$ip}'");
			return false;
		}

		$cache = App::cache();
		$cache = $cache instanceof RedisCache ? $cache : null;

		if ($cache === null) {
			return false;
		}

		if (!LoginThrottle::clearIp($cache, $ip)) {
			return false;
		}

		return true;
	}

	public function loginThrottleAvailable(): bool {
		return LoginThrottle::isEnabled() && App::cache() instanceof RedisCache;
	}


}
