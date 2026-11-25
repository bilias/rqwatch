<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Mail relays from Received headers (GeoIP off; DNS off, or answered from a
 seeded cache), the per-message DNS lookup budget, and the local/reserved
 check that keeps GeoIP lookups off private addresses.
*/

namespace Tests\Unit\Helpers;

use App\Configuration\AppConfig;
use App\Configuration\Config;
use App\Core\App;
use App\Core\AppContainer;
use App\Utils\Helper;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Unit\CountingCache;
use Tests\Unit\UnitTestCase;

final class RelayTest extends UnitTestCase
{
	/** @return list<string> */
	private function ips(string $headers): array {
		return array_column(Helper::extract_mail_relays($headers), 'ip');
	}

	/**
	 * DNS on, every answer from the cache, so no query leaves the test
	 * @param array<string, string> $answers cache key without the prefix => answer
	 */
	private function resolving(array $answers): void {
		Config::set('dns_resolv', true);
		Config::set('dns_resolv_redis_key', 'rq');
		$cache = new CountingCache();
		foreach ($answers as $key => $answer) {
			$cache->values["rq:{$key}"] = $answer;
		}
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, null, null, $cache, false));
	}

	public function testPostfixFoldedHop(): void {
		$h = "Received: from mail.example.org (mail.example.org [203.0.113.5])\r\n"
			. "\tby mx.example.net (Postfix) with ESMTPS id ABC\r\n"
			. "\tfor <a@example.net>; Mon, 1 Jan 2026 00:00:00 +0000\r\n";
		$this->assertSame(
			[['ip' => '203.0.113.5', 'host' => 'mail.example.org', 'country' => null]],
			Helper::extract_mail_relays($h)
		);
	}

	public function testAddressOnAContinuationLine(): void {
		$h = "Received: from mail.example.org\r\n"
			. "\t(mail.example.org [203.0.113.5])\r\n"
			. "\tby mx.example.net; Mon, 1 Jan 2026 00:00:00 +0000\r\n";
		$this->assertSame(
			[['ip' => '203.0.113.5', 'host' => 'mail.example.org', 'country' => null]],
			Helper::extract_mail_relays($h)
		);
	}

	public function testHostMatchedInAnyCaseAndLowercased(): void {
		$h = "Received: FROM Mail.Example.ORG (Mail.Example.ORG [203.0.113.5]) BY mx.example.net\n";
		$this->assertSame(['mail.example.org'], array_column(Helper::extract_mail_relays($h), 'host'));
	}

	public function testAddressesOfOneHopInReverseOrder(): void {
		$h = "Received: from a.example.org (helo [203.0.113.1]) ([203.0.113.2]) by mx.example\n";
		$this->assertSame(['203.0.113.2', '203.0.113.1'], $this->ips($h));
	}

	public function testAddressForms(): void {
		$h = "Received: from [2001:db8::25] (helo=client)\n\tby smtp.example.org with esmtpsa (Exim 4.96)\n"
			. "Received: from relay.example.com ([IPv6:2001:db8::7]) by x.example\n"
			. "Received: from host.example.com (198.51.100.9) by y.example\n";
		$relays = Helper::extract_mail_relays($h);
		$this->assertSame(['2001:db8::25', '2001:db8::7', '198.51.100.9'], array_column($relays, 'ip'));
		// "by" names the receiving host: never the sender of a hop with an address
		$this->assertSame([null, 'relay.example.com', 'host.example.com'], array_column($relays, 'host'));
	}

	public function testOnlyRealReceivedHeadersCount(): void {
		$h = "X-Fake: Received: from evil.example [192.0.2.66]\n"
			. "Subject: hi\n  Received: from folded.example [192.0.2.77]\n"
			. "Received: from ok.example.org (ok.example.org [203.0.113.8]) by mx.example\n";
		$this->assertSame(['203.0.113.8'], $this->ips($h));
	}

	public function testInvalidAndNonAddressBracketsIgnored(): void {
		$h = "Received: from a.example.org ([300.1.1.1]) by b.example\n"
			. "Received: from c.example.org (c.example.org [203.0.113.9 extra]) by d.example\n"
			. "Received: from e.example.org (helo [abc]) by f.example\n";
		$this->assertSame([], $this->ips($h));
	}

	public function testDeduplicatedByIpInOrder(): void {
		$h = "Received: from a.example.org ([203.0.113.1]) by mx.example\n"
			. "Received: from b.example.org ([203.0.113.2]) by a.example.org\n"
			. "Received: from a.example.org ([203.0.113.1]) by mx2.example\n";
		$this->assertSame(['203.0.113.1', '203.0.113.2'], $this->ips($h));
	}

	public function testHopWithoutAddressNeedsDns(): void {
		// resolution is off, so the host-only hop has no address to show
		$this->assertSame([], $this->ips("Received: by internal.example.org with local id X\n"));
	}

	public function testHopWithoutAddressResolvedFromBy(): void {
		$this->resolving(['fwd:internal.example.org' => '198.51.100.20']);
		$this->assertSame(
			[['ip' => '198.51.100.20', 'host' => 'internal.example.org', 'country' => null]],
			Helper::extract_mail_relays("Received: BY Internal.Example.ORG with local id X\n")
		);
	}

	// public addresses: my_gethostbyaddr() skips private and reserved ones
	public function testReverseLookupBudget(): void {
		$max = AppConfig::DNS_MAX_RELAYS;
		$h = '';
		$answers = [];
		$expected = [];
		for ($n = 1; $n <= $max + 1; $n++) {
			$h .= "Received: from h{$n}.example.org ([8.8.8.{$n}]) by mx.example\n";
			$answers["ptr:8.8.8.{$n}"] = "ptr{$n}.example.net";
			// past the budget the hop keeps its "from" name
			$expected[] = $n <= $max ? "ptr{$n}.example.net" : "h{$n}.example.org";
		}
		$this->resolving($answers);
		$this->assertSame($expected, array_column(Helper::extract_mail_relays($h), 'host'));
	}

	// a private address is never looked up, even with an answer in the cache
	public function testPrivateAddressNotLookedUp(): void {
		$this->resolving(['ptr:10.1.2.3' => 'ptr.example.net']);
		$h = "Received: from h.example.org ([10.1.2.3]) by mx.example\n";
		$this->assertSame(['h.example.org'], array_column(Helper::extract_mail_relays($h), 'host'));
	}

	public function testForwardLookupBudget(): void {
		$max = AppConfig::DNS_MAX_RELAYS;
		$h = '';
		$answers = [];
		$expected = [];
		for ($n = 1; $n <= $max + 1; $n++) {
			$h .= "Received: by h{$n}.example.org with local id X{$n}\n";
			$answers["fwd:h{$n}.example.org"] = "198.51.100.{$n}";
			// past the budget the hop has no address and is left out
			if ($n <= $max) {
				$expected[] = "198.51.100.{$n}";
			}
		}
		$this->resolving($answers);
		$this->assertSame($expected, $this->ips($h));
	}

	public function testNoHeaders(): void {
		$this->assertSame([], Helper::extract_mail_relays(''));
	}

	/** @return array<string, array{string, bool}> */
	public static function ipCases(): array {
		return [
			'public v4' => ['8.8.8.8', false],
			'public v6' => ['2a00:1450::1', false],
			'RFC 1918' => ['10.1.2.3', true],
			'loopback' => ['127.0.0.1', true],
			'link-local' => ['169.254.1.1', true],
			'documentation' => ['192.0.2.1', true],
			'CGNAT' => ['100.64.0.1', true],
			'v6 loopback' => ['::1', true],
			'v6 link-local' => ['fe80::1', true],
			'v6 ULA' => ['fd00::1', true],
			'v6 documentation' => ['2001:db8::1', true],
			'unknown' => ['unknown', true],
			'garbage' => ['garbage', true],
			'empty' => ['', true],
		];
	}

	#[DataProvider('ipCases')]
	public function testIsLocalOrReservedIp(string $ip, bool $expected): void {
		$this->assertSame($expected, Helper::isLocalOrReservedIp($ip));
	}
}
