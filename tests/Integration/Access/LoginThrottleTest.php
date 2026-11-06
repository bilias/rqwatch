<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Per-IP login throttle (LoginThrottle), switched on through .env for each
 test. With Redis the IP is blocked after LOGIN_MAX_ATTEMPTS_IP failures, the
 right password included, until an admin clears it, and never when the IP is
 in LOGIN_IPS_WHITELIST; without Redis it fails open. Every client is
 127.0.0.1, so the admin logs in before the first failure. A block left by a
 failed assertion is inert: the restored .env has LOGIN_THROTTLE_ENABLE=false.
*/

namespace Tests\Integration\Access;

use PHPUnit\Framework\Attributes\TestDox;
use Tests\Integration\IntegrationTestCase;
use Tests\WebClient;

final class LoginThrottleTest extends IntegrationTestCase
{
	#[TestDox('With Redis: blocked after LOGIN_MAX_ATTEMPTS_IP failures, right password included, until an admin clears it')]
	public function testBlockAndClear(): void {
		if (getenv('INTEGRATION_REDIS_ENABLED') !== 'true') {
			$this->markTestSkipped('Redis leg only');
		}
		$admin = self::client('admin');
		self::withEnv(['LOGIN_THROTTLE_ENABLE' => 'true', 'LOGIN_MAX_ATTEMPTS_IP' => '3'], function () use ($admin): void {
			$client = self::anonymous();
			for ($i = 1; $i <= 2; $i++) {
				$this->assertSame(401, self::attempt($client, 'wrong')['status'], "failure {$i}");
			}
			$page = $admin->get('/admin/throttles')['body'];
			$this->assertStringContainsString('Blocked IPs: 0', $page);
			$this->assertStringContainsString('2 / 3', $page, 'counter after two failures');

			$this->assertSame(401, self::attempt($client, 'wrong')['status'], 'failure 3');
			$response = self::attempt($client, 'pw-alice');
			$this->assertSame([302, '/login'], [$response['status'], self::target($response)], 'right password while blocked');
			$this->assertStringContainsString('Max wrong login attempts reached', $client->get('/login')['body']);

			$page = $admin->get('/admin/throttles')['body'];
			$this->assertStringContainsString('Blocked IPs: 1', $page);
			$this->assertStringContainsString('IPs with failed attempts: 0', $page, 'counter dropped by the block');
			$clear = WebClient::match('#href="(/admin/throttles/clear/127\.0\.0\.1\?_token=[^"]+)"#', $page);
			$this->assertSame('/admin/throttles', self::target($admin->get($clear)));
			$this->assertStringContainsString('Blocked IPs: 0', $admin->get('/admin/throttles')['body']);

			// throws if the login is still refused
			self::client('alice');
		});
	}

	#[TestDox('With Redis: an IP in LOGIN_IPS_WHITELIST is never counted or blocked')]
	public function testWhitelistedIpIsExempt(): void {
		if (getenv('INTEGRATION_REDIS_ENABLED') !== 'true') {
			$this->markTestSkipped('Redis leg only');
		}
		$admin = self::client('admin');
		self::withEnv(['LOGIN_THROTTLE_ENABLE' => 'true', 'LOGIN_MAX_ATTEMPTS_IP' => '2',
				'LOGIN_IPS_WHITELIST' => '"192.0.2.1, 127.0.0.1"'], function () use ($admin): void {
			$client = self::anonymous();
			for ($i = 1; $i <= 3; $i++) {
				$this->assertSame(401, self::attempt($client, 'wrong')['status'], "failure {$i}");
			}
			$page = $admin->get('/admin/throttles')['body'];
			$this->assertStringContainsString('Blocked IPs: 0', $page);
			$this->assertStringContainsString('IPs with failed attempts: 0', $page);
			self::client('alice');
		});
	}

	#[TestDox('Without Redis: LOGIN_THROTTLE_ENABLE has no effect, failures never block')]
	public function testWithoutRedisFailsOpen(): void {
		if (getenv('INTEGRATION_REDIS_ENABLED') === 'true') {
			$this->markTestSkipped('Leg without Redis only');
		}
		self::withEnv(['LOGIN_THROTTLE_ENABLE' => 'true', 'LOGIN_MAX_ATTEMPTS_IP' => '2'], function (): void {
			$client = self::anonymous();
			for ($i = 1; $i <= 3; $i++) {
				$this->assertSame(401, self::attempt($client, 'wrong')['status'], "failure {$i}");
			}
			self::client('alice');
			$this->assertStringContainsString('Redis is not available', self::client('admin')->get('/admin/throttles')['body']);
		});
	}

	/**
	 * One login POST as alice with a fresh form token.
	 * @return array{status: int, location: string, body: string, headers: array<string, string>}
	 */
	private static function attempt(WebClient $client, string $password): array {
		$token = WebClient::match('/name="login_form\[_token\]"[^>]*value="([^"]+)"/', $client->get('/login')['body']);
		return $client->post('/login', [
			'login_form[username]' => 'alice',
			'login_form[password]' => $password,
			'login_form[_token]' => $token,
		]);
	}
}
