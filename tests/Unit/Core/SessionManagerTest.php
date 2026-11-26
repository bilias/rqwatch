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
 here: starting one in PHPUnit would send headers.
*/

namespace Tests\Unit\Core;

use App\Core\App;
use App\Core\AppContainer;
use App\Core\SessionManager;
use PHPUnit\Framework\Attributes\DataProvider;
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
