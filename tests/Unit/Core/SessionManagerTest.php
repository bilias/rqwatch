<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The web session's idle timeout: IDLE_TIMEOUT seconds (unset, empty or 0:
 none). A session idle longer loses its user and flash messages and says
 "Session expired"; no session at all (Redis unreachable) counts as
 expired. Sessions in Redis need the app's cache to be a RedisCache: else
 the problem is logged and no session is made. The cookie is Secure over
 https, 'auto' over http. A stored session stands in for a started one
 here; a real one is started only in a process of its own: an HttpOnly,
 SameSite=Lax, strict session cookie named RQWATCHSESSID that ends with the
 browser, the session in Redis under rqwatch_sess: for IDLE_TIMEOUT
 seconds, or in PHP's own storage without Redis.
*/

namespace Tests\Unit\Core;

use App\Core\App;
use App\Core\AppContainer;
use App\Core\Cache\RedisCache;
use App\Core\SessionManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Redis;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Tests\Unit\RecordingCache;
use Tests\Unit\UnitTestCase;

final class SessionManagerTest extends UnitTestCase
{
	#[\Override]
	protected function tearDown(): void {
		self::setStatic('session', null);
		self::setStatic('session_timeout', null);
		parent::tearDown();
	}

	private static function setStatic(string $property, mixed $value): void {
		(new ReflectionProperty(SessionManager::class, $property))->setValue(null, $value);
	}

	private static function timeout(): mixed {
		return (new ReflectionProperty(SessionManager::class, 'session_timeout'))->getValue();
	}

	// a session with alice in it and a flash message, last used $idle seconds ago
	private static function stored(int $idle, ?int $timeout): Session {
		$storage = new MockArraySessionStorage();
		$storage->setSessionData(['_sf2_meta' => ['u' => time() - $idle, 'c' => time() - 3600, 'l' => 0]]);
		$session = new Session($storage);
		$session->start();
		$session->set('username', 'alice');
		$session->getFlashBag()->add('notice', 'saved');
		self::setStatic('session', $session);
		self::setStatic('session_timeout', $timeout);
		return $session;
	}

	public function testNoSessionIsExpired(): void {
		$this->assertTrue(SessionManager::checkSessionExpired());
	}

	/** @return array<string, array{int, ?int, bool}> idle seconds, timeout, expired */
	public static function idle(): array {
		return [
			'no timeout' => [7200, null, false],
			'timeout 0' => [7200, 0, false],
			'within' => [50, 60, false],
			'past' => [61, 60, true],
		];
	}

	#[DataProvider('idle')]
	public function testExpired(int $idle, ?int $timeout, bool $expired): void {
		$session = self::stored($idle, $timeout);
		$this->assertSame($expired, SessionManager::checkSessionExpired());
		$this->assertSame($expired ? null : 'alice', $session->get('username'));
		$this->assertSame($expired ? [] : ['notice' => ['saved']], $session->getFlashBag()->peekAll());
	}

	public function testGetSession(): void {
		$session = self::stored(10, 60);
		$this->assertSame($session, SessionManager::getSession());
		$this->assertSame(['notice' => ['saved']], $session->getFlashBag()->peekAll());
	}

	public function testGetSessionExpired(): void {
		$session = self::stored(120, 60);
		$this->assertSame($session, SessionManager::getSession());
		$this->assertNull($session->get('username'));
		$this->assertSame(['error' => ['Session expired']], $session->getFlashBag()->peekAll());
	}

	public function testDestroyWithoutSession(): void {
		SessionManager::destroy();
		$this->assertTrue(SessionManager::checkSessionExpired());
	}

	/** @return array<string, array{?string, ?int}> */
	public static function idleTimeouts(): array {
		return ['unset' => [null, null], 'empty' => ['', null], 'zero' => ['0', null], 'set' => ['300', 300]];
	}

	// Redis sessions without a RedisCache: logged, no session
	#[DataProvider('idleTimeouts')]
	public function testRedisNeedsRedisCache(?string $idle, ?int $timeout): void {
		$this->setEnv('IDLE_TIMEOUT', $idle);
		$this->setEnv('REDIS_ENABLE', 'true');
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, null, null, new RecordingCache(), false));
		SessionManager::newSession();
		$this->assertSame($timeout, self::timeout());
		$this->assertTrue(SessionManager::checkSessionExpired(), 'no session');
		$this->assertSame(['error [SessionManager] Redis conection problem: RedisSessionHandler requires RedisCache'], $this->log->lines);
	}

	/** @return array<string, string> the session cookie's settings */
	private static function cookie(): array {
		$ini = [];
		foreach (['name', 'cookie_httponly', 'cookie_samesite', 'cookie_secure', 'cookie_lifetime', 'use_strict_mode'] as $key) {
			$ini[$key] = (string) ini_get("session.{$key}");
		}
		return $ini;
	}

	private const array COOKIE = [
		'name' => 'RQWATCHSESSID', 'cookie_httponly' => '1', 'cookie_samesite' => 'lax',
		'cookie_secure' => '1', 'cookie_lifetime' => '0', 'use_strict_mode' => '1',
	];

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testNativeSession(): void {
		$this->setEnv('WEB_SCHEME', 'https');
		$this->setEnv('REDIS_ENABLE', null);
		SessionManager::newSession();
		$session = SessionManager::getSession();
		$this->assertSame([true, self::COOKIE], [$session->isStarted(), self::cookie()]);
	}

	/** @return array<string, array{?string, int}> IDLE_TIMEOUT, the session's TTL in Redis */
	public static function redisTtls(): array {
		return ['set' => ['600', 600], 'unset' => [null, 0]];
	}

	// the session written to Redis under its prefix, for the idle timeout
	#[DataProvider('redisTtls')]
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testRedisSession(?string $idle, int $ttl): void {
		$this->setEnv('WEB_SCHEME', 'https');
		$this->setEnv('REDIS_ENABLE', 'true');
		$this->setEnv('IDLE_TIMEOUT', $idle);
		/** @var list<array{string, int, string}> $written */
		$written = [];
		$client = $this->createStub(Redis::class);
		$client->method('get')->willReturn(false);
		$client->method('setEx')->willReturnCallback(function (string $key, int $expire, string $value) use (&$written): bool {
			$written[] = [$key, $expire, $value];
			return true;
		});
		$cache = new RedisCache($this->log);
		(new ReflectionProperty(RedisCache::class, 'client'))->setValue($cache, $client);
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, null, null, $cache, false));
		SessionManager::newSession();
		$session = SessionManager::getSession();
		$this->assertSame([true, self::COOKIE], [$session->isStarted(), self::cookie()]);
		$session->set('username', 'alice');
		$session->save();
		$this->assertCount(1, $written);
		[$key, $expire, $value] = $written[0];
		$this->assertSame(['rqwatch_sess:' . $session->getId(), $ttl], [$key, $expire]);
		$this->assertStringContainsString('alice', $value);
		$this->assertSame([], $this->log->lines);
	}

	/** @return array<string, array{string, string}> */
	public static function schemes(): array {
		return ['http' => ['http', 'auto'], 'https' => ['https', '1']];
	}

	#[DataProvider('schemes')]
	public function testSecureCookie(string $scheme, string $secure): void {
		$this->setEnv('WEB_SCHEME', $scheme);
		$this->assertSame($secure, (new ReflectionMethod(SessionManager::class, 'checkSecureCookie'))->invoke(null));
	}
}
