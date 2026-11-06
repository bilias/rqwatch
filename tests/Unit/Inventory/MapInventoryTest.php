<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 MapInventory's map config check: every config, built-in or custom from
 the DB, needs model, fields, map_form and access, a known model and a
 non-empty list of field names, or getMapConfigs() throws.
*/

namespace Tests\Unit\Inventory;

use App\Forms\MapWithCustomFieldForm;
use App\Inventory\MapInventory;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Tests\Unit\UnitTestCase;

final class MapInventoryTest extends UnitTestCase
{
	// a custom map's config, as getMapConfigs() builds it from the DB
	private const array CUSTOM = [
		'model' => 'MapCustom',
		'description' => 'Custom map',
		'fields' => ['domain'],
		'map_form' => MapWithCustomFieldForm::class,
		'access' => ['admin'],
	];

	public function testBuiltInConfigsPass(): void {
		$configs = MapInventory::getMapConfigs(null, false);
		$this->assertNotEmpty($configs);
		foreach ($configs as $name => $config) {
			$this->assertContains($config['model'], ['MapCombined', 'MapCustom'], $name);
			$this->assertIsList($config['fields'], $name);
		}
	}

	public function testCustomConfigPasses(): void {
		$this->expectNotToPerformAssertions();
		$this->check(['custom' => self::CUSTOM]);
	}

	/** @return array<string, array{array<string, mixed>, string}> */
	public static function badConfigs(): array {
		$without = static fn (string $key): array => array_diff_key(self::CUSTOM, [$key => true]);
		return [
			'no model' => [$without('model'), "Missing or null required field 'model' in map config 'm'"],
			'no fields' => [$without('fields'), "Missing or null required field 'fields' in map config 'm'"],
			'no map_form' => [$without('map_form'), "Missing or null required field 'map_form' in map config 'm'"],
			'null access' => [['access' => null] + self::CUSTOM, "Missing or null required field 'access' in map config 'm'"],
			'unknown model' => [['model' => 'MapFoo'] + self::CUSTOM, "Unknown model in map config 'm'"],
			'model not a string' => [['model' => 1] + self::CUSTOM, "Unknown model in map config 'm'"],
			'fields a string' => [['fields' => 'domain'] + self::CUSTOM, "Map config 'm' needs a non-empty list of fields"],
			'fields empty' => [['fields' => []] + self::CUSTOM, "Map config 'm' needs a non-empty list of fields"],
			'fields not a list' => [['fields' => [1 => 'domain']] + self::CUSTOM, "Map config 'm' needs a non-empty list of fields"],
			'empty field name' => [['fields' => ['domain', '']] + self::CUSTOM, "Map config 'm' has an invalid field name"],
			'field name not a string' => [['fields' => [5]] + self::CUSTOM, "Map config 'm' has an invalid field name"],
		];
	}

	/** @param array<string, mixed> $config */
	#[DataProvider('badConfigs')]
	public function testBadConfigThrows(array $config, string $message): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage($message);
		$this->check(['m' => $config]);
	}

	/** @param array<string, array<string, mixed>> $configs */
	private function check(array $configs): void {
		(new ReflectionMethod(MapInventory::class, 'checkMapConfigs'))->invoke(null, $configs);
	}
}
