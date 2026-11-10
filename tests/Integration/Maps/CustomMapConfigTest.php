<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The admin's custom map config pages over HTTP. Create: the name is stored
 trimmed, and the map's entry form shows the field label; the same name again,
 the reserved name manage_custom_maps and a name the form's pattern
 refuses (upper case) create nothing. Edit: the label changes. Each test
 removes what it made.
*/

namespace Tests\Integration\Maps;

use PHPUnit\Framework\Attributes\TestDox;
use Tests\Integration\IntegrationTestCase;
use Tests\WebClient;

final class CustomMapConfigTest extends IntegrationTestCase
{
	private const string MAP = 'cfg-test-map';
	private const string FORM = 'custom_map_config_form';

	#[\Override]
	protected function tearDown(): void {
		$db = self::db();
		foreach (['custom_map_config', 'map_activity_logs'] as $table) {
			$db->prepare("DELETE FROM {$table} WHERE map_name IN (?, ?)")->execute([self::MAP, 'manage_custom_maps']);
		}
		parent::tearDown();
	}

	#[TestDox('Admin creates a custom map: name stored trimmed, label shown; the same name again is refused')]
	public function testCreate(): void {
		$admin = self::client('admin');
		$response = self::submit($admin, '/admin/maps/custom/add', ['map_name' => '  ' . self::MAP . '  ']);
		$this->assertSame(302, $response['status']);
		$this->assertSame('/admin/maps/custom', self::target($response));
		$this->assertStringContainsString("Custom Map '" . self::MAP . "' created", self::text($admin->get('/admin/maps/custom')['body']));
		$this->assertSame(1, self::configs(self::MAP));
		$this->assertStringContainsString('Domain name:', $admin->get('/admin/map/' . self::MAP . '/add')['body'], "the entry form's label");

		$response = self::submit($admin, '/admin/maps/custom/add', ['map_name' => self::MAP]);
		$this->assertSame(302, $response['status']);
		$this->assertStringContainsString("Map '" . self::MAP . "' already exists!", self::text($admin->get('/admin/maps/custom/add')['body']));
		$this->assertSame(1, self::configs(self::MAP));
	}

	#[TestDox('The reserved name and a name with upper case create nothing')]
	public function testRefusedNames(): void {
		$admin = self::client('admin');
		$response = self::submit($admin, '/admin/maps/custom/add', ['map_name' => 'manage_custom_maps']);
		$this->assertSame(302, $response['status']);
		$this->assertStringContainsString("Map name 'manage_custom_maps' is not allowed!", self::text($admin->get('/admin/maps/custom/add')['body']));
		$this->assertSame(0, self::configs('manage_custom_maps'));

		$response = self::submit($admin, '/admin/maps/custom/add', ['map_name' => 'Cfg-Test-Map']);
		$this->assertSame(200, $response['status'], 'the form is shown again');
		$this->assertSame(0, self::valueInt("SELECT COUNT(*) FROM custom_map_config WHERE map_name LIKE 'cfg-test-map'"));
	}

	#[TestDox('Admin edits a custom map: the label changes, in the entry form too')]
	public function testEdit(): void {
		$admin = self::client('admin');
		$this->assertSame(302, self::submit($admin, '/admin/maps/custom/add', ['map_name' => self::MAP])['status']);
		$id = self::valueInt('SELECT id FROM custom_map_config WHERE map_name = ?', self::MAP);

		$response = self::submit($admin, "/admin/maps/custom/edit/{$id}", ['map_name' => self::MAP, 'field_label' => 'Sender domain']);
		$this->assertSame(302, $response['status']);
		$this->assertSame('/admin/maps/custom', self::target($response));
		$this->assertSame('Sender domain', self::valueString('SELECT field_label FROM custom_map_config WHERE id = ?', $id));
		$this->assertStringContainsString('Sender domain:', $admin->get('/admin/map/' . self::MAP . '/add')['body']);
	}

	/**
	 * Posts the config form at $path, after reading its token there.
	 * @param array<string, string> $values over a valid default
	 * @return array{status: int, location: string, body: string, headers: array<string, string>}
	 */
	private static function submit(WebClient $admin, string $path, array $values): array {
		$token = WebClient::match('/name="' . self::FORM . '\[_token\]"[^>]*value="([^"]+)"/', $admin->get($path)['body']);
		$fields = $values + [
			'map_description' => 'Config test',
			'field_name' => 'domain',
			'field_label' => 'Domain name',
		];
		$post = [self::FORM . '[submit]' => '', self::FORM . '[_token]' => $token];
		foreach ($fields as $name => $value) {
			$post[self::FORM . "[{$name}]"] = $value;
		}
		return $admin->post($path, $post);
	}

	private static function configs(string $map): int {
		return self::valueInt('SELECT COUNT(*) FROM custom_map_config WHERE map_name = ?', $map);
	}

	// a page's text as shown: Twig escapes the quotes in the flash messages
	private static function text(string $html): string {
		return html_entity_decode($html, ENT_QUOTES | ENT_HTML5);
	}
}
