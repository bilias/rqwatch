<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The admin's system actions, each back to the search page with a
 message: a user is refused (logged), as is a request without its CSRF
 token (logged with the address); without Redis there is nothing to do.
 The DNS cache flush deletes the resolver's keys and says how many; a
 failure is logged and said. The config reload needs the Redis cache: any
 other fails, logged and said (a reload that works needs Redis: the
 integration suite's).
*/

namespace Tests\Unit\Controllers;

use App\Configuration\Config;
use App\Controllers\SystemController;
use App\Core\Routing\RouteName;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

final class SystemControllerTest extends ControllerTestCase
{
	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->setEnv('REDIS_ENABLE', 'true');
		Config::set('dns_resolv_redis_key', 'rqwatch_dns');
	}

	/**
	 * @param array<string, mixed> $session
	 * @return array{Response, \Symfony\Component\HttpFoundation\Request}
	 */
	private function call(string $action, array $session = self::ADMIN, ?string $token = null): array {
		$request = self::request('/', $session);
		$id = $action === 'dnsFlush' ? 'dns_flush' : 'config_reload';
		$request->query->replace(['_token' => $token ?? self::csrf($request, $id)]);
		$controller = $this->controller(new SystemController(), $request);
		$response = $action === 'dnsFlush' ? $controller->dnsFlush() : $controller->redisConfigReload();
		return [$response, $request];
	}

	private function search(bool $admin = true): string {
		return $this->urls->generate(($admin ? RouteName::ADMIN_SEARCH : RouteName::SEARCH)->value);
	}

	public function testDnsFlush(): void {
		[$response, $request] = $this->call('dnsFlush');
		$this->assertRedirect($this->search(), $response);
		$this->assertSame(['info' => ['DNS cache flushed: 7 entries removed']], self::flashes($request));
		$this->assertSame(['rqwatch_dns'], $this->cache->prefixes);
		$this->assertSame(['info DNS cache flushed: 7 entries removed'], $this->log->lines);
	}

	public function testDnsFlushFails(): void {
		Config::set('dns_resolv_redis_key', ' ');
		[$response, $request] = $this->call('dnsFlush');
		$this->assertRedirect($this->search(), $response);
		$this->assertSame(['error' => ['dnsFlush failed']], self::flashes($request));
		$this->assertSame(['error DNS Flush failed: dns_resolv_redis_key is not set in config'], $this->log->lines);
	}

	public function testReloadNeedsRedisCache(): void {
		[$response, $request] = $this->call('redisConfigReload');
		$this->assertRedirect($this->search(), $response);
		$this->assertSame(['error' => ['Failed redisConfigReload: Redis cache is not available']], self::flashes($request));
		$this->assertSame(['error Failed redisConfigReload: Redis cache is not available'], $this->log->lines);
	}

	/** @return array<string, array{string, string}> action, what it is said to be */
	public static function actions(): array {
		return [
			'dns flush' => ['dnsFlush', 'flush DNS cache'],
			'config reload' => ['redisConfigReload', 'reload config in redis'],
		];
	}

	#[DataProvider('actions')]
	public function testUserRefused(string $action, string $what): void {
		[$response, $request] = $this->call($action, self::USER);
		$this->assertRedirect($this->search(false), $response);
		$this->assertSame(['error' => ['Permission denied']], self::flashes($request));
		$this->assertSame(["warning 'alice' tried to {$what} without admin authorization"], $this->log->lines);
		$this->assertSame([], $this->cache->prefixes);
	}

	#[DataProvider('actions')]
	public function testBadTokenRefused(string $action, string $what): void {
		[$response, $request] = $this->call($action, self::ADMIN, 'forged');
		$this->assertRedirect($this->search(), $response);
		$this->assertSame(['error' => ['Invalid or expired request. Please try again.']], self::flashes($request));
		$this->assertSame(["warning CSRF check failed on {$action} from 192.0.2.7"], $this->log->lines);
		$this->assertSame([], $this->cache->prefixes);
	}

	#[DataProvider('actions')]
	public function testNoTokenRefused(string $action, string $what): void {
		[$response, $request] = $this->call($action, self::ADMIN, '');
		$this->assertSame(['error' => ['Invalid or expired request. Please try again.']], self::flashes($request));
	}

	#[DataProvider('actions')]
	public function testRedisOff(string $action, string $what): void {
		$this->setEnv('REDIS_ENABLE', 'false');
		[$response, $request] = $this->call($action);
		$this->assertRedirect($this->search(), $response);
		$this->assertSame(['warning' => ['Redis is not enabled']], self::flashes($request));
		$this->assertSame([[], []], [$this->log->lines, $this->cache->prefixes]);
	}
}
