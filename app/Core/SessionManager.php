<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Core;

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\Session\Storage\NativeSessionStorage;

use Symfony\Component\HttpFoundation\Session\Storage\Handler\RedisSessionHandler;

use App\Core\App;

use App\Core\Cache\RedisCache;

use App\Utils\Helper;

use Throwable;
use RuntimeException;

final class SessionManager
{
	private static ?Session $session = null;
	private static ?int $session_timeout = null;

	/*
	 Typed reads of what rqwatch stored in the session, as Config::getString()
	 and friends read the config: null when unset, an exception naming the key
	 when the stored value has another type. rqwatch writes these values
	 itself, so another type is a bug or a damaged store, and must not pass
	 as unset.
	*/
	public static function getString(SessionInterface $session, string $key): ?string {
		/** @var mixed $value */
		$value = $session->get($key);
		return $value === null || is_string($value) ? $value : throw self::wrongType($key, 'a string', $value);
	}

	public static function getInt(SessionInterface $session, string $key): ?int {
		/** @var mixed $value */
		$value = $session->get($key);
		return $value === null || is_int($value) ? $value : throw self::wrongType($key, 'an integer', $value);
	}

	public static function getBool(SessionInterface $session, string $key): ?bool {
		/** @var mixed $value */
		$value = $session->get($key);
		return $value === null || is_bool($value) ? $value : throw self::wrongType($key, 'true or false', $value);
	}

	/** @return list<string> the stored list, [] when unset */
	public static function getStringList(SessionInterface $session, string $key): array {
		/** @var mixed $value */
		$value = $session->get($key);
		if ($value === null) {
			return [];
		}
		if (!is_array($value)) {
			throw self::wrongType($key, 'a list of strings', $value);
		}
		$list = [];
		/** @var mixed $item */
		foreach ($value as $item) {
			$list[] = is_string($item) ? $item : throw self::wrongType($key, 'a list of strings', $item);
		}
		return $list;
	}

	private static function wrongType(string $key, string $expected, mixed $value): RuntimeException {
		return new RuntimeException("Session value '{$key}' must be {$expected}, not " . get_debug_type($value));
	}

	public static function getSession(): Session {
		if (self::$session === null) {
			self::newSession();
		}

		$session = self::$session;
		if ($session === null) { // redis not connectable
			exit('Session creation problem. Contact admin');
		}

		if (self::checkSessionExpired()) {
			$session->getFlashBag()->add('error', 'Session expired');
		}
		return $session;
	}

	private static function setSessionTimeout(int $timeout): void {
		self::$session_timeout = $timeout;
	}

	public static function newSession(): void {
		$idle = Helper::env_string('IDLE_TIMEOUT');
		if ($idle !== null && $idle !== '' && $idle !== '0') {
			self::setSessionTimeout((int) $idle);
		}

		if (Helper::env_bool('REDIS_ENABLE')) {
			// Redis Sentinel connection via phpredis or predis
			try {
				$redisCache = App::cache();

				if (!$redisCache instanceof RedisCache) {
					throw new RuntimeException(
						'RedisSessionHandler requires RedisCache'
					);
				}

				$redisConnection = $redisCache->getConnection();
				// Use RedisSessionHandler
				$handler = new RedisSessionHandler($redisConnection, [
					'ttl' => self::$session_timeout ?? 0,
					'prefix' => 'rqwatch_sess:',
				]);

				$storage = new NativeSessionStorage([
					'cookie_secure' => self::checkSecureCookie(),
					'cookie_httponly' => '1',
					'cookie_samesite' => Cookie::SAMESITE_LAX,
					// not needed
					//'cookie_lifetime' => self::$session_timeout ?? 0,
					'cookie_lifetime' => 0,
					'name' => 'RQWATCHSESSID',
					'use_strict_mode' => '1',
				], $handler);
			} catch (Throwable $e) {
				App::fileLogger()->error("[SessionManager] Redis conection problem: " . $e->getMessage());
				return;
			}
		} else {
			$storage = new NativeSessionStorage([
				'cookie_secure' => self::checkSecureCookie(),
				'cookie_httponly' => '1',
				'cookie_samesite' => Cookie::SAMESITE_LAX,
				// not needed
				//'cookie_lifetime' => self::$session_timeout ?? 0,
				'cookie_lifetime' => 0,
				'name' => 'RQWATCHSESSID',
				'use_strict_mode' => '1',
			]);
		}

		self::$session = new Session($storage);
		self::$session->start();
	}

	// clears username and flashbag from session
	public static function destroy(): void {
		if (self::$session) {
			self::$session->remove('username');
			self::$session->getFlashBag()->clear();
			// anything that was stored in the session is removed
			// we removed this because it breaks CSRF in login form
			// after IDLE_TIMEOUT on login page
			//self::$session->invalidate(); // session still exists
		}
		/*
		session_unset();
		session_destroy();
		self::$session = null;
		*/
	}

	public static function checkSessionExpired(): bool {
		//dump(self::$session->getMetadataBag()->getLifetime());
		//dump(date("Y-m-d H:i:s", self::$session->getMetadataBag()->getLastUsed()));
		// this can happen when redis is not connectable
		if (self::$session === null) {
			return true;
		}

		if (self::$session_timeout !== null && self::$session_timeout > 0
			&& time() - self::$session->getMetadataBag()->getLastUsed() > self::$session_timeout) {
			self::destroy();
			return true;
		}

		return false;
	}

	private static function checkSecureCookie(): string {
		if (Helper::env_required('WEB_SCHEME') == 'http') {
			return "auto";
		}
		return "1";
	}
}
