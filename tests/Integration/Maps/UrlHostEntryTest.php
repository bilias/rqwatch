<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 MapWithCustomFieldForm on a custom map whose field is named url, in any
 case: what an admin types goes through MapInventory::canonicalUrlHostEntries()
 on add and on edit. An IP address, bare or in brackets as a browser shows
 it, is saved as rspamd reads a URL host (canonical, no brackets), a
 disabled one too; a full URL with an IP host as rspamd rewrites it; a
 domain and an address with more text as typed; the map file holds what was
 saved. A map with another field name keeps every entry as typed. The tests
 create their own custom map and remove it afterwards.
*/

namespace Tests\Integration\Maps;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Integration\IntegrationTestCase;
use Tests\WebClient;

final class UrlHostEntryTest extends IntegrationTestCase
{
	private const string MAP = 'url-host-test-map';
	private const string FORM = 'map_with_custom_field_form';

	#[\Override]
	protected function tearDown(): void {
		$db = self::db();
		foreach (['maps_custom', 'custom_map_config', 'map_activity_logs'] as $table) {
			$db->prepare("DELETE FROM {$table} WHERE map_name = ?")->execute([self::MAP]);
		}
		@unlink(self::mapFile());
		parent::tearDown();
	}

	/** @return array<string, array{string}> */
	public static function urlFieldNames(): array {
		return ['url' => ['url'], 'URL' => ['URL']];
	}

	#[DataProvider('urlFieldNames')]
	#[TestDox('A url field ($field): IP hosts saved as rspamd reports them, other entries as typed')]
	public function testAddCanonicalisesIpHosts(string $field): void {
		self::customMap($field);
		self::add($field, implode("\r\n", [
			'[::FFFF:d93c:ed72]',
			'[192.0.2.10]',
			'#[2001:DB8:0:0:0:0:0:1]',
			'http://[::ffff:d93c:ed72]/q9x-oml',
			'example.org',
			'[::1] note',
		]));
		$this->assertSame([
			'::ffff:217.60.237.114 0',
			'192.0.2.10 0',
			'2001:db8::1 1',
			'http://[::ffff:217.60.237.114]/q9x-oml 0',
			'example.org 0',
			'[::1] note 0',
		], self::entries());
		// the file lists the newest first: same-second entries in no fixed order
		$this->assertEqualsCanonicalizing(
			['::ffff:217.60.237.114', '192.0.2.10', 'http://[::ffff:217.60.237.114]/q9x-oml', 'example.org', '[::1] note'],
			self::mapFileEntries()
		);
	}

	#[TestDox('Another field name: entries saved as typed')]
	public function testOtherFieldKept(): void {
		self::customMap('domain');
		self::add('domain', "[::FFFF:d93c:ed72]\r\n[192.0.2.10]");
		$this->assertSame(['[::FFFF:d93c:ed72] 0', '[192.0.2.10] 0'], self::entries());
	}

	#[TestDox('A url field: an edited entry is saved as rspamd reports the host, disabled too')]
	public function testEditCanonicalises(): void {
		self::customMap('url');
		self::db()->prepare("INSERT INTO maps_custom (map_name, pattern, disabled, created_at, updated_at) VALUES (?, 'old.example.org', 0, NOW(), NOW())")
			->execute([self::MAP]);
		$id = (int) self::db()->lastInsertId();
		$admin = self::client('admin');
		$path = '/admin/map/' . self::MAP . "/edit/{$id}";
		foreach (['[0000:0000:0000:0000:0000:FFFF:D93C:ED72]' => '::ffff:217.60.237.114 0', '#[192.0.2.10]' => '192.0.2.10 1'] as $submitted => $saved) {
			$response = $admin->post($path, [self::FORM . '[url]' => $submitted, self::FORM . '[_token]' => self::token($admin, $path)]);
			$this->assertSame([302, '/admin/map/' . self::MAP], [$response['status'], self::target($response)], $submitted);
			$this->assertSame([$saved], self::entries(), $submitted);
		}
	}

	private static function customMap(string $field): void {
		self::db()->prepare("INSERT INTO custom_map_config (map_name, map_description, field_name, field_label) VALUES (?, 'URL host test', ?, 'URL')")
			->execute([self::MAP, $field]);
	}

	private function add(string $field, string $entries): void {
		$admin = self::client('admin');
		$path = '/admin/map/' . self::MAP . '/add';
		$response = $admin->post($path, [self::FORM . "[{$field}]" => $entries, self::FORM . '[_token]' => self::token($admin, $path)]);
		$this->assertSame([302, '/admin/map/' . self::MAP], [$response['status'], self::target($response)], 'added');
	}

	private static function token(WebClient $client, string $path): string {
		return WebClient::match('/name="' . self::FORM . '\[_token\]"[^>]*value="([^"]+)"/', $client->get($path)['body']);
	}

	/** @return list<string> each entry's pattern and disabled flag, in order */
	private static function entries(): array {
		$statement = self::db()->prepare('SELECT CONCAT(pattern, " ", disabled) FROM maps_custom WHERE map_name = ? ORDER BY id');
		$statement->execute([self::MAP]);
		/** @var list<string> */
		return $statement->fetchAll(\PDO::FETCH_COLUMN);
	}

	/** @return list<string> the map file's entries, without its # header lines */
	private static function mapFileEntries(): array {
		$lines = explode("\n", trim((string) file_get_contents(self::mapFile())));
		return array_values(array_filter($lines, fn (string $line): bool => !str_starts_with($line, '#')));
	}

	private static function mapFile(): string {
		return self::root() . '/web/maps/' . self::MAP . '.txt';
	}
}
