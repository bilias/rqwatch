<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis
 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 A site's url maps (custom maps; the rules: tests/EndToEnd/rspamd/local.d/
 multimap-url.conf). The map form stores an IP host as rspamd reads a URL's
 host: canonical, without brackets (MapInventory::canonicalUrlHostEntries()).
 These tests hold rspamd to that: the entries in tests/EndToEnd/fixtures.sql
 are the form's output for what an admin types, and a mail with the URL,
 written in any of its forms, must hit them, the symbol naming the stored
 text and the hit credited to the entry. A full-URL map (filter = "full")
 compares the URL as rspamd rewrites it: an IP host canonical, an IPv6 one
 in brackets, so its entries are stored that way too. If rspamd ever reads
 hosts or URLs differently, these fail.
*/

namespace Tests\EndToEnd\Import;

use App\Inventory\MapInventory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\EndToEnd\EndToEndTestCase;

final class UrlMapsTest extends EndToEndTestCase
{
	// MapHitService::ENTRY_CUSTOM
	private const ENTRY_CUSTOM = 2;

	/** @return array<string, array{string, string}> what an admin types, as the form stores it (a fixture) */
	public static function typed(): array {
		return [
			'IPv6, copied from a browser' => ['[::ffff:d93c:ed72]', '::ffff:217.60.237.114'],
			'IPv4 in brackets' => ['[192.0.2.44]', '192.0.2.44'],
			'full URL, copied from a browser' => ['http://[::ffff:d93c:ed72]/q9x-oml?lang=el', 'http://[::ffff:217.60.237.114]/q9x-oml?lang=el'],
		];
	}

	#[TestDox('The fixtures are what the map form stores')]
	#[DataProvider('typed')]
	public function testFixturesAreTheFormsOutput(string $typed, string $stored): void {
		$this->assertSame($stored, MapInventory::canonicalUrlHostEntries($typed));
		$this->assertSame(1, self::valueInt('SELECT COUNT(*) FROM maps_custom WHERE pattern = ?', [$stored]), "fixture: {$stored}");
	}

	/** @return array<string, array{string, string}> a URL in a mail body, the host rspamd matches */
	public static function hosts(): array {
		return [
			'IPv6 hex, as a browser writes it' => ['http://[::ffff:d93c:ed72]/a', '::ffff:217.60.237.114'],
			'IPv6 expanded, upper case' => ['http://[0000:0000:0000:0000:0000:FFFF:D93C:ED72]/b', '::ffff:217.60.237.114'],
			'IPv6 dotted' => ['http://[::ffff:217.60.237.114]/c', '::ffff:217.60.237.114'],
			'IPv4' => ['http://192.0.2.44/d', '192.0.2.44'],
		];
	}

	#[TestDox('url map, host: rspamd reads the URL host as rqwatch stores it, hit credited')]
	#[DataProvider('hosts')]
	public function testHostMatched(string $url, string $host): void {
		[, $reply, $row] = $this->import('clean', body: "Please see {$url} today.");
		$this->assertSame([$host], self::options($reply, 'RQWATCH_BODY_URL_HOST'), 'the host rspamd matched');
		$this->assertHit(self::colInt($row, 'id'), 'RQWATCH_BODY_URL_HOST', self::ENTRY_CUSTOM, self::entry('body_url_host', $host));
	}

	/** @return array<string, array{string}> the URL in a mail body, written as a browser or a sender may */
	public static function fullUrls(): array {
		return [
			'IPv6 hex' => ['http://[::ffff:d93c:ed72]/q9x-oml?lang=el'],
			'IPv6 expanded, upper case' => ['http://[0000:0000:0000:0000:0000:FFFF:D93C:ED72]/q9x-oml?lang=el'],
			'IPv6 dotted' => ['http://[::ffff:217.60.237.114]/q9x-oml?lang=el'],
		];
	}

	#[TestDox('url map, full: the URL as rspamd rewrites it, hit credited')]
	#[DataProvider('fullUrls')]
	public function testFullUrlMatched(string $url): void {
		$stored = 'http://[::ffff:217.60.237.114]/q9x-oml?lang=el';
		[, $reply, $row] = $this->import('clean', body: "Please see {$url} today.");
		$this->assertSame([$stored], self::options($reply, 'RQWATCH_BODY_URL_FULL'), 'the URL rspamd matched');
		$this->assertHit(self::colInt($row, 'id'), 'RQWATCH_BODY_URL_FULL', self::ENTRY_CUSTOM, self::entry('body_url_full', $stored));
	}

	#[TestDox('url map, host: another host does not match')]
	public function testOtherHostNotMatched(): void {
		[, $reply] = $this->import('clean', body: 'Please see http://[2001:db8::7]/x today.');
		$this->assertArrayNotHasKey('RQWATCH_BODY_URL_HOST', self::symbols($reply));
		$this->assertArrayNotHasKey('RQWATCH_BODY_URL_FULL', self::symbols($reply));
	}

	/**
	 * a symbol's options in a scan reply, failing when the symbol is missing
	 * @param array<array-key, mixed> $reply
	 * @return list<string>
	 */
	private static function options(array $reply, string $symbol): array {
		$symbols = self::symbols($reply);
		if (!isset($symbols[$symbol])) {
			self::fail("{$symbol} not in rspamd's reply: " . json_encode(
				array_map(static fn (array $s): mixed => $s['options'] ?? null, $symbols),
				JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
			));
		}
		/** @var mixed $options */
		$options = $symbols[$symbol]['options'] ?? [];
		if (!is_array($options)) {
			self::fail("{$symbol}: options not a list");
		}
		$values = [];
		/** @var mixed $option */
		foreach ($options as $option) {
			if (is_scalar($option)) {
				$values[] = (string) $option;
			}
		}
		return $values;
	}

	private static function entry(string $map, string $pattern): int {
		return self::valueInt('SELECT id FROM maps_custom WHERE map_name = ? AND pattern = ?', [$map, $pattern]);
	}
}
