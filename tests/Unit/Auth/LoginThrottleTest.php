<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Per-IP login throttling on an in-memory cache: the counter's window is
 set by the first failure only, the block starts at LOGIN_MAX_ATTEMPTS_IP
 and drops the counter, IPv6 clients share their /64 (IPv4, loopback and
 IPv4-mapped addresses stay exact), and nothing is counted while the
 throttle is off, without a cache, or for an unknown or whitelisted IP.
*/

namespace Tests\Unit\Auth;

use App\Core\Auth\LoginThrottle;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Unit\CountingCache;
use Tests\Unit\UnitTestCase;

final class LoginThrottleTest extends UnitTestCase
{
	private const string COUNT = 'rqwatch_throttle:count:';
	private const string BLOCK = 'rqwatch_throttle:block:';

	private CountingCache $cache;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->cache = new CountingCache();
		$this->setEnv('REDIS_ENABLE', 'true');
		$this->setEnv('LOGIN_THROTTLE_ENABLE', 'true');
		$this->setEnv('LOGIN_MAX_ATTEMPTS_IP', '3');
		$this->setEnv('LOGIN_WINDOW', '600');
		$this->setEnv('LOGIN_BLOCK_DURATION', '900');
		$this->setEnv('LOGIN_IPS_WHITELIST', '192.0.2.200, 2001:db8::200');
	}

	public function testBlocksAtMaxAttempts(): void {
		$throttle = $this->throttle('192.0.2.1');
		$this->assertFalse($throttle->registerFailure());
		$this->assertFalse($throttle->registerFailure());
		$this->assertFalse($throttle->isBlocked());
		$this->assertSame('2', $this->cache->values[self::COUNT . '192.0.2.1']);
		// the window starts at the first failure; later ones do not push it forward
		$this->assertSame([[self::COUNT . '192.0.2.1', 600]], $this->cache->expires);

		$this->assertTrue($throttle->registerFailure());
		$this->assertTrue($throttle->isBlocked());
		$this->assertSame(900, $throttle->retryAfter());
		$this->assertSame(900, $this->cache->ttls[self::BLOCK . '192.0.2.1']);
		// a fresh window once the block lifts
		$this->assertArrayNotHasKey(self::COUNT . '192.0.2.1', $this->cache->values);
	}

	public function testOtherIpsUnaffected(): void {
		$throttle = $this->throttle('192.0.2.1');
		for ($i = 0; $i < 3; $i++) {
			$throttle->registerFailure();
		}
		$this->assertTrue($throttle->isBlocked());
		$this->assertFalse($this->throttle('192.0.2.2')->isBlocked());
		$this->assertNull($this->throttle('192.0.2.2')->retryAfter());
	}

	// LOGIN_MAX_ATTEMPTS_IP below 1 counts as 1
	public function testMaxAttemptsAtLeastOne(): void {
		$this->setEnv('LOGIN_MAX_ATTEMPTS_IP', '0');
		$throttle = $this->throttle('192.0.2.1');
		$this->assertSame(1, $throttle->getMaxAttempts());
		$this->assertTrue($throttle->registerFailure());
	}

	/** @return array<string, array{string, string}> client IP, key */
	public static function keyCases(): array {
		return [
			'IPv4 exact' => ['192.0.2.1', '192.0.2.1'],
			'IPv6 on its /64' => ['2001:db8:1:2::1', '2001:db8:1:2::'],
			'IPv6 upper case, on its /64' => ['2001:DB8:1:2:FFFF::9', '2001:db8:1:2::'],
			'another /64' => ['2001:db8:1:3::1', '2001:db8:1:3::'],
			'loopback exact' => ['::1', '::1'],
			'IPv4-mapped exact' => ['::ffff:192.0.2.1', '::ffff:192.0.2.1'],
		];
	}

	#[DataProvider('keyCases')]
	public function testKey(string $ip, string $key): void {
		$this->throttle($ip)->registerFailure();
		$this->assertSame([self::COUNT . $key => '1'], $this->cache->values);
	}

	// one host normally holds a whole /64 and can rotate through it
	public function testIpv6SlashSixtyFourSharesTheBlock(): void {
		for ($i = 1; $i <= 3; $i++) {
			$this->throttle("2001:db8:1:2::{$i}")->registerFailure();
		}
		$this->assertTrue($this->throttle('2001:db8:1:2:abcd::1')->isBlocked());
		$this->assertFalse($this->throttle('2001:db8:1:3::1')->isBlocked());
	}

	/** @return array<string, array{string, non-empty-string|null, string|null}> client IP, .env key, value */
	public static function inactiveCases(): array {
		return [
			'throttle off' => ['192.0.2.1', 'LOGIN_THROTTLE_ENABLE', 'false'],
			'Redis off' => ['192.0.2.1', 'REDIS_ENABLE', 'false'],
			'throttle unset' => ['192.0.2.1', 'LOGIN_THROTTLE_ENABLE', null],
			'no client IP' => ['', null, null],
			'unknown client IP' => ['UNKNOWN', null, null],
			'whitelisted IPv4' => ['192.0.2.200', null, null],
			'whitelisted IPv6' => ['2001:db8::200', null, null],
		];
	}

	/** @param non-empty-string|null $env */
	#[DataProvider('inactiveCases')]
	public function testInactive(string $ip, ?string $env, ?string $value): void {
		if ($env !== null) {
			$this->setEnv($env, $value);
		}
		$throttle = $this->throttle($ip);
		for ($i = 0; $i < 3; $i++) {
			$this->assertFalse($throttle->registerFailure());
		}
		$this->assertFalse($throttle->isBlocked());
		$this->assertNull($throttle->retryAfter());
		$this->assertSame([], $this->cache->values);
	}

	// enabled but no cache (Redis down at boot): fails open, and says so
	public function testWithoutCache(): void {
		$throttle = new LoginThrottle('192.0.2.1', null, $this->log);
		$this->assertFalse($throttle->registerFailure());
		$this->assertFalse($throttle->isBlocked());
		$this->assertLogged('per-IP login throttling is disabled');
	}

	private function throttle(string $ip): LoginThrottle {
		return new LoginThrottle($ip, $this->cache, $this->log);
	}
}
