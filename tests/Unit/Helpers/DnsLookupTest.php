<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Reverse (PTR) and forward (A) lookups, answered by a stand-in for netdns2's
 query: the first PTR or A record, past a leading CNAME; the address or name
 itself when there is none or the query fails. A private address is not
 looked up. With a cache, a cached answer is used as is; otherwise the
 answer is cached for the configured TTL, a name not found for the negative
 TTL and a failed query for the short fail TTL. A cache that fails is
 passed by: the lookup is made and its answer returned.
*/

namespace Tests\Unit\Helpers;

use App\Configuration\Config;
use App\Core\App;
use App\Core\AppContainer;
use App\Utils\Helper;
use NetDNS2\ENUM\Error as DnsError;
use NetDNS2\Exception as DnsException;
use NetDNS2\Packet\Response;
use NetDNS2\RR;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionProperty;
use Tests\Unit\RecordingCache;
use Tests\Unit\UnitTestCase;

final class DnsLookupTest extends UnitTestCase
{
	private const string PTR_NAME = '4.4.8.8.in-addr.arpa';

	// 2001:4860:4860::8888, nibble by nibble from the last
	private const string PTR6_NAME = '8.8.8.8.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.6.8.4.0.6.8.4.1.0.0.2.ip6.arpa';

	/** @var array<string, list<string>|DnsException> "name/type" => its answer records, or what the query throws */
	private array $answers = [];

	/** @var list<string> "name/type" of every query made */
	private array $queries = [];

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		Config::set('dns_resolv', true);
		Config::set('dns_resolv_timeout', 1.0);
		Config::set('dns_resolv_redis_key', 'dns');
		Config::set('dns_resolv_redis_cache_ttl', 1000);
		Config::set('dns_resolv_redis_neg_cache_ttl', 200);
		Config::set('dns_resolv_redis_fail_cache_ttl', 30);
		$this->answers = [
			self::PTR_NAME . '/PTR' => [
				self::PTR_NAME . '. 300 IN CNAME 4.0-25.4.8.8.in-addr.arpa.',
				'4.0-25.4.8.8.in-addr.arpa. 300 IN PTR dns.example.org.',
			],
			self::PTR6_NAME . '/PTR' => [self::PTR6_NAME . '. 300 IN PTR dns6.example.org.'],
			'mail.example.org/A' => [
				'mail.example.org. 300 IN CNAME a.example.org.',
				'a.example.org. 300 IN A 192.0.2.7',
				'a.example.org. 300 IN A 192.0.2.8',
			],
			'nothing.example.org/A' => ['nothing.example.org. 300 IN TXT "no address"'],
			'nx.example.org/A' => new DnsException('no such name', DnsError::DNS_NXDOMAIN),
			'down.example.org/A' => new DnsException('server failure', DnsError::DNS_SERVFAIL),
			'9.9.9.9.in-addr.arpa/PTR' => new DnsException('no such name', DnsError::DNS_NXDOMAIN),
			'1.1.1.1.in-addr.arpa/PTR' => new DnsException('timed out', DnsError::INT_FAILED_SOCKET),
		];
		$this->queries = [];
		(new ReflectionProperty(Helper::class, 'dnsQuery'))->setValue(null, $this->query(...));
	}

	#[\Override]
	protected function tearDown(): void {
		(new ReflectionProperty(Helper::class, 'dnsQuery'))->setValue(null, null);
		parent::tearDown();
	}

	public function testReverse(): void {
		$this->assertSame('dns.example.org', Helper::my_gethostbyaddr('8.8.4.4'));
		$this->assertSame('dns6.example.org', Helper::my_gethostbyaddr('2001:4860:4860::8888'));
		$this->assertSame([self::PTR_NAME . '/PTR', self::PTR6_NAME . '/PTR'], $this->queries);
	}

	public function testReverseNoName(): void {
		$this->assertSame('8.8.8.8', Helper::my_gethostbyaddr('8.8.8.8'), 'no record');
		$this->assertSame('9.9.9.9', Helper::my_gethostbyaddr('9.9.9.9'), 'NXDOMAIN');
		$this->assertSame('1.1.1.1', Helper::my_gethostbyaddr('1.1.1.1'), 'failed');
	}

	public function testPrivateNotLookedUp(): void {
		$this->assertSame('10.0.0.1', Helper::my_gethostbyaddr('10.0.0.1'));
		$this->assertSame('not an ip', Helper::my_gethostbyaddr('not an ip'));
		$this->assertSame([], $this->queries);
	}

	public function testOff(): void {
		Config::set('dns_resolv', false);
		$this->assertSame('8.8.8.8', Helper::my_gethostbyaddr('8.8.8.8'));
		$this->assertSame('mail.example.org', Helper::my_gethostbyname('mail.example.org'));
		$this->assertSame([], $this->queries);
	}

	// the first address
	public function testForward(): void {
		$this->assertSame('192.0.2.7', Helper::my_gethostbyname('mail.example.org'));
		$this->assertSame(['mail.example.org/A'], $this->queries);
	}

	public function testForwardNoAddress(): void {
		$this->assertSame('nothing.example.org', Helper::my_gethostbyname('nothing.example.org'), 'no A record');
		$this->assertSame('nx.example.org', Helper::my_gethostbyname('nx.example.org'), 'NXDOMAIN');
		$this->assertSame('down.example.org', Helper::my_gethostbyname('down.example.org'), 'failed');
	}

	public function testCachedAnswerUsed(): void {
		$cache = $this->cache();
		$cache->values = ['dns:ptr:8.8.4.4' => 'cached.example.org', 'dns:fwd:mail.example.org' => '192.0.2.99'];
		$this->assertSame('cached.example.org', Helper::my_gethostbyaddr('8.8.4.4'));
		$this->assertSame('192.0.2.99', Helper::my_gethostbyname('mail.example.org'));
		$this->assertSame([], $this->queries);
	}

	/** @return array<string, array{string, string, string, int}> */
	public static function reverseCaching(): array {
		return [
			'found: the TTL' => ['8.8.4.4', 'dns:ptr:8.8.4.4', 'dns.example.org', 1000],
			'no record: the negative TTL' => ['8.8.8.8', 'dns:ptr:8.8.8.8', '8.8.8.8', 200],
			'NXDOMAIN: the negative TTL' => ['9.9.9.9', 'dns:ptr:9.9.9.9', '9.9.9.9', 200],
			'failed: the fail TTL' => ['1.1.1.1', 'dns:ptr:1.1.1.1', '1.1.1.1', 30],
		];
	}

	#[DataProvider('reverseCaching')]
	public function testReverseCached(string $ip, string $key, string $host, int $ttl): void {
		$cache = $this->cache();
		$this->assertSame($host, Helper::my_gethostbyaddr($ip));
		$this->assertSame([$key => $host], $cache->values);
		$this->assertSame([$key => $ttl], $cache->ttls);
	}

	/** @return array<string, array{string, string, string, int}> */
	public static function forwardCaching(): array {
		return [
			'found: the TTL' => ['mail.example.org', 'dns:fwd:mail.example.org', '192.0.2.7', 1000],
			'no record: the negative TTL' => ['nothing.example.org', 'dns:fwd:nothing.example.org', 'nothing.example.org', 200],
			'NXDOMAIN: the negative TTL' => ['nx.example.org', 'dns:fwd:nx.example.org', 'nx.example.org', 200],
			'failed: the fail TTL' => ['down.example.org', 'dns:fwd:down.example.org', 'down.example.org', 30],
		];
	}

	#[DataProvider('forwardCaching')]
	public function testForwardCached(string $host, string $key, string $ip, int $ttl): void {
		$cache = $this->cache();
		$this->assertSame($ip, Helper::my_gethostbyname($host));
		$this->assertSame([$key => $ip], $cache->values);
		$this->assertSame([$key => $ttl], $cache->ttls);
	}

	// unset TTLs: a day, an hour, a minute
	public function testDefaultTtls(): void {
		Config::init(['dns_resolv' => true, 'dns_resolv_timeout' => 1.0, 'dns_resolv_redis_key' => 'dns']);
		$cache = $this->cache();
		Helper::my_gethostbyaddr('8.8.4.4');
		Helper::my_gethostbyaddr('9.9.9.9');
		Helper::my_gethostbyname('down.example.org');
		$this->assertSame(['dns:ptr:8.8.4.4' => 86400, 'dns:ptr:9.9.9.9' => 3600, 'dns:fwd:down.example.org' => 60], $cache->ttls);
	}

	public function testFailingCachePassedBy(): void {
		$cache = $this->cache();
		$cache->failing = true;
		$this->assertSame('dns.example.org', Helper::my_gethostbyaddr('8.8.4.4'));
		$this->assertSame('192.0.2.7', Helper::my_gethostbyname('mail.example.org'));
		$this->assertSame(['dns:ptr:8.8.4.4', 'dns:fwd:mail.example.org'], $cache->reads);
		$this->assertSame([self::PTR_NAME . '/PTR', 'mail.example.org/A'], $this->queries);
	}

	private function cache(): RecordingCache {
		$cache = new RecordingCache();
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, null, null, $cache, false));
		return $cache;
	}

	// the stand-in for netdns2's query: an answer packet holding the records for "name/type"
	private function query(string $name, string $type): Response {
		$this->queries[] = "{$name}/{$type}";
		$answer = $this->answers["{$name}/{$type}"] ?? [];
		if ($answer instanceof DnsException) {
			throw $answer;
		}
		$response = (new ReflectionClass(Response::class))->newInstanceWithoutConstructor();
		$response->answer = array_map(RR::fromString(...), $answer);
		return $response;
	}
}
