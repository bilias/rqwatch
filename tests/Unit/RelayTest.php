<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Mail relays from Received headers (DNS and GeoIP off), and the
 local/reserved check that keeps GeoIP lookups off private addresses.
*/

namespace Tests\Unit;

use App\Utils\Helper;
use PHPUnit\Framework\Attributes\DataProvider;

final class RelayTest extends UnitTestCase
{
	private function ips(string $headers): array {
		return array_column(Helper::extract_mail_relays($headers), 'ip');
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

	public function testNoHeaders(): void {
		$this->assertSame([], Helper::extract_mail_relays(''));
	}

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
