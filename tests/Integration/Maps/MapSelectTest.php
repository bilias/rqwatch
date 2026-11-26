<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The map pickers on the maps page (MapSelectForm, whose custom choices come
 from the database; tests/Unit/Forms/MapSelectFormTest covers where a choice
 leads). After the placeholder, an admin's custom picker offers "Manage
 Custom Maps" and "All Map Entries", then every custom map by description,
 not by name; the basic picker "All Map Entries", then the built-in maps by
 description, no custom one. A user gets only the basic picker, with the
 four maps a user may keep. The test adds two custom maps and removes them.
*/

namespace Tests\Integration\Maps;

use PDO;
use PHPUnit\Framework\Attributes\TestDox;
use RuntimeException;
use Tests\Integration\IntegrationTestCase;

final class MapSelectTest extends IntegrationTestCase
{
	// named against the order of their descriptions
	private const array MAPS = ['mapselect-a' => 'Zeta map select test', 'mapselect-b' => 'Alpha map select test'];
	private const string CUSTOM = 'map_custom_form[map_name]';
	private const string BASIC = 'map_combined_form[map_name]';

	private bool $added = false;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$insert = self::db()->prepare("INSERT INTO custom_map_config (map_name, map_description, field_name, field_label) VALUES (?, ?, 'domain', 'Domain')");
		foreach (self::MAPS as $name => $description) {
			$insert->execute([$name, $description]);
		}
		$this->added = true;
	}

	#[\Override]
	protected function tearDown(): void {
		if (!$this->added) {
			parent::tearDown();
			return;
		}
		$delete = self::db()->prepare('DELETE FROM custom_map_config WHERE map_name = ?');
		foreach (array_keys(self::MAPS) as $name) {
			$delete->execute([$name]);
		}
		parent::tearDown();
	}

	#[TestDox('Admin: custom maps by description after Manage and All; built-in maps by description, no custom one')]
	public function testAdminPickers(): void {
		$page = self::client('admin')->get('/admin/maps');
		$this->assertSame(200, $page['status']);

		// every custom map, as the form keys them: by description, sorted
		$rows = self::db()->query('SELECT map_description, map_name FROM custom_map_config')
			?: throw new RuntimeException('custom_map_config query failed');
		$custom = [];
		/** @var mixed $row */
		foreach ($rows->fetchAll(PDO::FETCH_NUM) as $row) {
			self::assertIsArray($row);
			[$description, $name] = $row;
			self::assertIsString($description);
			self::assertIsString($name);
			$custom[$description] = $name;
		}
		ksort($custom);
		$options = self::options($page['body'], self::CUSTOM);
		$this->assertSame(
			['' => '---', 'manage_custom_maps' => 'Manage Custom Maps', 'all' => 'All Map Entries'] + array_flip($custom),
			$options
		);
		$this->assertLessThan(
			array_search('mapselect-a', array_keys($options), true),
			array_search('mapselect-b', array_keys($options), true),
			'Alpha before Zeta'
		);

		$basic = self::options($page['body'], self::BASIC);
		$this->assertSame(['' => '---', 'all' => 'All Map Entries'], array_slice($basic, 0, 2, true));
		$maps = array_slice($basic, 2, null, true);
		$labels = array_values($maps);
		$sorted = $labels;
		sort($sorted);
		$this->assertSame($sorted, $labels, 'by description');
		$this->assertArrayHasKey('ip_blacklist', $maps);
		$this->assertArrayHasKey('mail_from_rcpt_to_whitelist', $maps);
		$this->assertSame([], array_intersect(array_keys($maps), $custom), 'no custom map');
	}

	#[TestDox('User: only the basic picker, with the four maps a user may keep')]
	public function testUserPicker(): void {
		$page = self::client('alice')->get('/user/maps');
		$this->assertSame(200, $page['status']);
		$this->assertSame([
			'' => '---',
			'all' => 'All Map Entries',
			'mime_from_rcpt_to_blacklist' => 'MIME From/RCPT_TO Blacklist',
			'mime_from_rcpt_to_whitelist' => 'MIME From/RCPT_TO Whitelist',
			'mail_from_rcpt_to_blacklist' => 'Mail From/RCPT_TO Blacklist',
			'mail_from_rcpt_to_whitelist' => 'Mail From/RCPT_TO Whitelist',
		], self::options($page['body'], self::BASIC));
		$this->assertStringNotContainsString('name="' . self::CUSTOM . '"', $page['body'], 'no custom picker');
	}

	/**
	 * The options of the <select> named $name, value => label, in page order
	 * @return array<string, string>
	 */
	private function options(string $body, string $name): array {
		$this->assertSame(1, preg_match('/<select\b[^>]*\bname="' . preg_quote($name, '/') . '"[^>]*>(.*?)<\/select>/s', $body, $select), "select {$name}");
		preg_match_all('/<option\b[^>]*\bvalue="([^"]*)"[^>]*>(.*?)<\/option>/s', $select[1], $found, PREG_SET_ORDER);
		$options = [];
		foreach ($found as [, $value, $label]) {
			$options[html_entity_decode($value, ENT_QUOTES | ENT_HTML5)] = html_entity_decode(trim($label), ENT_QUOTES | ENT_HTML5);
		}
		return $options;
	}
}
