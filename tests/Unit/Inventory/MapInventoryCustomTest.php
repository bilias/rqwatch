<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 MapInventory with the custom maps of custom_map_config, on
 RecordingDatabase: each is an admin-only MapCustom map of its one field,
 edited with MapWithCustomFieldForm, after the built-in maps; a name of
 digits only is refused. One map's config, for a role: none for a map
 not known or not the role's; a user's two-field map with RCPT To as its
 second field gets the user's form (the user's own addresses only), an
 admin's the admin's. Without a map, the role's maps; by model, their
 names.
*/

namespace Tests\Unit\Inventory;

use App\Forms\MapIpForm;
use App\Forms\MapMailFromRcptToForm;
use App\Forms\MapMailFromRcptToUserForm;
use App\Forms\MapMimeFromRcptToForm;
use App\Forms\MapMimeFromRcptToUserForm;
use App\Forms\MapWithCustomFieldForm;
use App\Inventory\MapInventory;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Unit\Services\MapDatabaseTestCase;

final class MapInventoryCustomTest extends MapDatabaseTestCase
{
	private const string LIST = 'select `id`, `map_name`, `map_description`, `field_name`, `field_label`, `created_at` from `custom_map_config` order by `map_name` asc, `updated_at` desc';

	private const array USER_MAPS = ['mail_from_rcpt_to_whitelist', 'mail_from_rcpt_to_blacklist', 'mime_from_rcpt_to_whitelist', 'mime_from_rcpt_to_blacklist'];

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->answers[] = ['from `custom_map_config`', [
			['id' => 1, 'map_name' => 'phish', 'map_description' => 'Phishing URLs', 'field_name' => 'url', 'field_label' => 'URL', 'created_at' => null],
			['id' => 2, 'map_name' => 'domains', 'map_description' => 'Bad domains', 'field_name' => 'domain', 'field_label' => 'Domain', 'created_at' => null],
		]];
	}

	public function testCustomMapsAfterBuiltIn(): void {
		$configs = MapInventory::getMapConfigs();
		$this->assertSame([[self::LIST, []]], $this->db->selects);
		$this->assertSame(array_merge(array_keys(MapInventory::getMapConfigs(null, false)), ['phish', 'domains']), array_keys($configs));
		$this->assertSame([
			'model' => 'MapCustom',
			'description' => 'Phishing URLs',
			'fields' => ['url'],
			'map_form' => MapWithCustomFieldForm::class,
			'access' => ['admin'],
		], $configs['phish']);
		$this->assertSame('Bad domains', MapInventory::getMapConfigs('domains')['description'] ?? null);
		$this->assertNull(MapInventory::getMapConfigs('nosuch'));
	}

	public function testNoCustomMaps(): void {
		$this->answers = [];
		$this->assertSame(MapInventory::getMapConfigs(null, false), MapInventory::getMapConfigs());
	}

	public function testDigitsOnlyNameRefused(): void {
		$this->answers = [['from `custom_map_config`', [
			['id' => 1, 'map_name' => '2024', 'map_description' => 'Year', 'field_name' => 'url', 'field_label' => 'URL', 'created_at' => null],
		]]];
		$this->expectExceptionObject(new RuntimeException("Custom map '2024' has a name of digits only, which cannot be used: rename it in custom_map_config"));
		MapInventory::getMapConfigs();
	}

	/** @return array<string, array{string, string, class-string|null}> role, map, its form (null: no config) */
	public static function oneMap(): array {
		return [
			'user, mail from/rcpt to' => ['user', 'mail_from_rcpt_to_whitelist', MapMailFromRcptToUserForm::class],
			'user, mime from/rcpt to' => ['user', 'mime_from_rcpt_to_blacklist', MapMimeFromRcptToUserForm::class],
			'admin, mail from/rcpt to' => ['admin', 'mail_from_rcpt_to_whitelist', MapMailFromRcptToForm::class],
			'admin, mime from/rcpt to' => ['admin', 'mime_from_rcpt_to_blacklist', MapMimeFromRcptToForm::class],
			'admin, ip' => ['admin', 'ip_blacklist', MapIpForm::class],
			'admin, custom' => ['admin', 'phish', MapWithCustomFieldForm::class],
			'user, admin only' => ['user', 'ip_blacklist', null],
			'user, custom' => ['user', 'phish', null],
			'admin, unknown' => ['admin', 'nosuch', null],
			'other role' => ['guest', 'mail_from_rcpt_to_whitelist', null],
		];
	}

	/** @param class-string|null $form */
	#[DataProvider('oneMap')]
	public function testOneMap(string $role, string $map, ?string $form): void {
		$config = MapInventory::getAvailableMapConfigs($role, $map);
		$this->assertSame($form, $config['map_form'] ?? null);
		if ($config !== null) {
			$this->assertSame(MapInventory::getMapConfigs($map)['fields'] ?? null, $config['fields']);
		}
	}

	public function testRoleMaps(): void {
		$this->assertSame(self::USER_MAPS, array_keys(MapInventory::getAvailableMapConfigs('user')));
		$admin = MapInventory::getAvailableMapConfigs();
		$this->assertSame(array_keys(MapInventory::getMapConfigs()), array_keys($admin));
		$this->assertSame(MapMailFromRcptToForm::class, $admin['mail_from_rcpt_to_whitelist']['map_form']);
		$this->assertSame([], MapInventory::getAvailableMapConfigs('guest'));
	}

	public function testRoleMapsByModel(): void {
		$this->assertSame(['phish', 'domains'], MapInventory::getRoleMapsByModel('MapCustom', 'admin'));
		$this->assertSame([], MapInventory::getRoleMapsByModel('MapCustom', 'user'));
		$this->assertSame(self::USER_MAPS, MapInventory::getRoleMapsByModel('MapCombined', 'user'));
		$this->assertSame([], MapInventory::getRoleMapsByModel('', 'admin'));
	}
}
