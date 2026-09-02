<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Core\Auth;

use App\Core\Cache\CacheInterface;
use App\Utils\Helper;
use Psr\Log\LoggerInterface;

/**
 * Per-IP login throttling.
 *
 * Two independent durations:
 *
 *  LOGIN_WINDOW          time window to check for failed logins
 *  LOGIN_MAX_ATTEMPTS_IP max login failures for IP to trigger block
 *  LOGIN_BLOCK_DURATION  how long the IP stays blocked
 *
 * Two keys are used: a counter (TTL = window) and a block flag
 * (TTL = block duration). Keeping them separate lets a short detection window
 * be paired with a long block, or vice versa, without one constraining the
 * other.
 *
 * Deliberately does NOT count per username: that would let anyone who knows a
 * username lock that account out at will. Per-username failures are still
 * logged by the caller for detection purposes.
 *
 * Keys are NOT namespaced per web host - all frontends share throttle
 * state, otherwise an attacker simply rotates hosts.
 *
 * Needs a cache backend. When the cache is unavailable every method is a
 * no-op, so login keeps working (unthrottled) rather than failing:
 * an unreachable cache must not lock everybody out of the application.
 */
final class LoginThrottle
{
	private const COUNT_PREFIX = 'rqwatch_throttle:count:';
	private const BLOCK_PREFIX = 'rqwatch_throttle:block:';

	private readonly int $maxAttempts;
	private readonly int $window;
	private readonly int $blockDuration;
	private readonly bool $enabled;
	private readonly array $whitelist;

	/**
	 * $cache is null when REDIS_ENABLE is false or the connection failed at
	 * boot; throttling is then silently disabled.
	 */
	public function __construct(
		private readonly string $clientIp,
		private readonly ?CacheInterface $cache,
		private readonly LoggerInterface $logger,
	) {
		$this->maxAttempts   = max(1, (int)($_ENV['LOGIN_MAX_ATTEMPTS_IP'] ?? 10));
		$this->window        = max(1, (int)($_ENV['LOGIN_WINDOW'] ?? 600));
		$this->blockDuration = max(1, (int)($_ENV['LOGIN_BLOCK_DURATION'] ?? 900));

		$this->enabled =
			Helper::env_bool('REDIS_ENABLE', false) &&
			Helper::env_bool('LOGIN_THROTTLE_ENABLE', false);

		if ($this->enabled && $this->cache === null) {
			$this->logger->notice(
				'[LoginThrottle] cache unavailable; per-IP login throttling is disabled'
			);
		}

		$this->whitelist = array_values(array_filter(
			array_map('trim', explode(',', (string)($_ENV['LOGIN_IPS_WHITELIST'] ?? ''))),
			static fn(string $ip): bool => $ip !== ''
		));
	}

	/*
	 * True while this IP is blocked.
	 */
	public function isBlocked(): bool {
		if (!$this->active()) {
			return false;
		}

		return $this->cache->has($this->blockKey());
	}

	/*
	 * Record one failed login for this IP. Trips the block once the counter
	 * reaches LOGIN_MAX_ATTEMPTS_IP inside the current window.
	 *
	 * Returns true when this failure caused the IP to become blocked.
	 */
	public function registerFailure(): bool {
		if (!$this->active()) {
			return false;
		}

		$count = $this->cache->incr($this->countKey());

		if ($count === null) {
			// cache error, already logged by the cache layer
			return false;
		}

		// Set the expiry on the first failure only, so repeated attempts
		// cannot keep pushing the window forward indefinitely.
		if ($count === 1) {
			$this->cache->expire($this->countKey(), $this->window);
		}

		if ($count < $this->maxAttempts) {
			return false;
		}

		$this->cache->set($this->blockKey(), 1, $this->blockDuration);

		// Drop the counter so that when the block expires this IP starts from
		// a clean window instead of being re-blocked on its next failure.
		$this->cache->delete($this->countKey());

		return true;
	}

	/*
	 * Clear counter and block after a successful login, so that earlier typos
	 * do not count against a legitimate user.
	 */
	public function clear(): void {
		if (!$this->active()) {
			return;
		}

		$this->cache->delete($this->countKey());
		$this->cache->delete($this->blockKey());
	}

	/*
	 * Seconds until the block lifts, or null when not blocked.
	 */
	public function retryAfter(): ?int {
		if (!$this->active()) {
			return null;
		}

		return $this->cache->ttl($this->blockKey());
	}

	public function getMaxAttempts(): int {
		return $this->maxAttempts;
	}

	public function getBlockDuration(): int {
		return $this->blockDuration;
	}

	/**
	 * 'UNKNOWN' is the sentinel used when REMOTE_ADDR is missing; sharing a
	 * single counter between all such requests would let them block each other.
	 * IPs in LOGIN_IPS_WHITELIST are never throttled.
	 */
	private function active(): bool {
		return $this->enabled
			&& $this->cache !== null
			&& $this->clientIp !== ''
			&& $this->clientIp !== 'UNKNOWN'
			&& !in_array($this->clientIp, $this->whitelist, true);
	}

	private function countKey(): string {
		return self::COUNT_PREFIX . $this->clientIp;
	}

	private function blockKey(): string {
		return self::BLOCK_PREFIX . $this->clientIp;
	}
}
