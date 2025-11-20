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
use Symfony\Component\HttpFoundation\Session\Storage\NativeSessionStorage;

use App\Core\RedisFactory;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\RedisSessionHandler;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use App\Utils\Helper;
use Psr\Log\LoggerInterface;

use Throwable;

class SessionManager
{
	private static ?Session $session = null;
	private static ?int $session_timeout = null;
	private static ?LoggerInterface $logger = null;

	public static function setLogger(LoggerInterface $logger): void {
		self::$logger = $logger;
	}

	public static function getSession(): Session {
		if (self::$session === null) {
			self::newSession();
		}

		if (self::checkSessionExpired()) {
			if (self::$session) {
				self::$session->getFlashBag()->add('error', 'Session expired');
			} else { // redis not connectable
				exit('Session creation problem. Contact admin');
			}
		}
		return self::$session;
	}

	private static function setSessionTimeout(int $timeout): void {
		self::$session_timeout = $timeout;
	}

	public static function newSession(): void {
		if (array_key_exists('IDLE_TIMEOUT', $_ENV) && !empty($_ENV['IDLE_TIMEOUT'])) {
			self::setSessionTimeout((int)$_ENV['IDLE_TIMEOUT']);
		}

		if (Helper::env_bool('REDIS_ENABLE')) {
			// Redis Sentinel connection via phpredis or predis
			try {
				$redisConnection = RedisFactory::get();
				// Use RedisSessionHandler
				$handler = new RedisSessionHandler($redisConnection, [
					'ttl' => self::$session_timeout ?? 0,
					'prefix' => 'rqwatch_sess:',
				]);

				$storage = new NativeSessionStorage([
					'cookie_secure' => self::checkSecureCookie(),
					'cookie_httponly' => '1',
					'cookie_samesite' => Cookie::SAMESITE_STRICT,
					// not needed
					//'cookie_lifetime' => self::$session_timeout ?? 0,
					'cookie_lifetime' => 0,
					'name' => 'RQWATCHSESSID',
				], $handler);
			} catch (Throwable $e) {
				self::$logger->error("[SessionManager] Redis conection problem: " . $e->getMessage());
				return;
			}
		} else {
			$storage = new NativeSessionStorage([
				'cookie_secure' => self::checkSecureCookie(),
				'cookie_httponly' => '1',
				'cookie_samesite' => Cookie::SAMESITE_STRICT,
				// not needed
				//'cookie_lifetime' => self::$session_timeout ?? 0,
				'cookie_lifetime' => 0,
				'name' => 'RQWATCHSESSID',
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
		if (self::$session_timeout) {
			if (time() - self::$session->getMetadataBag()->getLastUsed() > self::$session_timeout) {
				self::destroy();
				return true;
			}
		}
		return false;
	}

	private static function checkSecureCookie(): string {
		if ($_ENV['WEB_SCHEME'] == 'http') {
			return "auto";
		}
		return "1";
	}
}
