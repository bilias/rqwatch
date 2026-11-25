<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The custom map config form's map name: lowercase letters, digits, _ and
 -, but not digits only, which PHP would turn into an int config key. Every
 field is required and checked at its length bounds; the symbols field is
 there only with map_symbols, which takes a boolean and is off by default.
*/

namespace Tests\Unit\Forms;

use App\Forms\CustomMapConfigForm;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;
use Symfony\Component\Validator\Validation;
use Tests\Unit\UnitTestCase;

final class CustomMapConfigFormTest extends UnitTestCase
{
	private const array VALID = [
		'map_name' => 'blocked-senders',
		'map_description' => 'Config test',
		'field_name' => 'domain',
		'field_label' => 'Domain name',
	];

	/** @return array<string, array{string, string|null}> */
	public static function names(): array {
		$digits = 'A map name needs a letter, _ or -';
		return [
			'letters' => ['blocked-senders', null],
			'letter and digits' => ['a12', null],
			'digits and a dash' => ['1-2', null],
			'digits and an underscore' => ['12_', null],
			'digits only' => ['123', $digits],
			'long digits only' => ['20261110', $digits],
			'upper case' => ['Blocked', 'Only lowercase letters, numbers and _- are allowed'],
		];
	}

	#[DataProvider('names')]
	public function testMapName(string $name, ?string $error): void {
		$form = Forms::createFormFactoryBuilder()
			->addExtension(new ValidatorExtension(Validation::createValidator()))
			->getFormFactory()
			->create(CustomMapConfigForm::class, null, ['map_symbols' => false]);
		$form->submit(['map_name' => $name] + self::VALID);

		$errors = $form->get('map_name')->getErrors();
		$this->assertCount($error === null ? 0 : 1, $errors, (string) $errors);
		if ($error !== null) {
			$this->assertStringContainsString($error, (string) $errors);
		}
	}

	/** @return array<string, array{array<string, string>, string|null}> changes to VALID, the field with an error */
	public static function fields(): array {
		return [
			'valid' => [[], null],
			'map name empty' => [['map_name' => ''], 'map_name'],
			'map name too short' => [['map_name' => 'ab'], 'map_name'],
			'map name at its minimum' => [['map_name' => 'abc'], null],
			'map name at its maximum' => [['map_name' => str_repeat('a', 128)], null],
			'map name too long' => [['map_name' => str_repeat('a', 129)], 'map_name'],
			'description empty' => [['map_description' => ''], 'map_description'],
			'description too short' => [['map_description' => 'ab'], 'map_description'],
			'description at its minimum' => [['map_description' => 'abc'], null],
			'description at its maximum' => [['map_description' => str_repeat('a', 128)], null],
			'description too long' => [['map_description' => str_repeat('a', 129)], 'map_description'],
			'field name empty' => [['field_name' => ''], 'field_name'],
			'field name too short' => [['field_name' => 'a'], 'field_name'],
			'field name at its minimum' => [['field_name' => 'ab'], null],
			'field name at its maximum' => [['field_name' => str_repeat('a', 128)], null],
			'field name too long' => [['field_name' => str_repeat('a', 129)], 'field_name'],
			'field label empty' => [['field_label' => ''], 'field_label'],
			'field label too short' => [['field_label' => 'a'], 'field_label'],
			'field label at its minimum' => [['field_label' => 'ab'], null],
			'field label at its maximum' => [['field_label' => str_repeat('a', 128)], null],
			'field label too long' => [['field_label' => str_repeat('a', 129)], 'field_label'],
		];
	}

	/** @param array<string, string> $changes */
	#[DataProvider('fields')]
	public function testFields(array $changes, ?string $field): void {
		$form = self::factory()->create(CustomMapConfigForm::class);
		$form->submit($changes + self::VALID);
		$this->assertSame($field === null, $form->isValid(), (string) $form->getErrors(true));
		if ($field !== null) {
			$this->assertGreaterThan(0, $form->get($field)->getErrors()->count(), $field);
		}
	}

	public function testSymbolsOnlyWithMapSymbols(): void {
		$this->assertFalse(self::factory()->create(CustomMapConfigForm::class)->has('symbols'));
		$this->assertFalse(self::factory()->create(CustomMapConfigForm::class, null, ['map_symbols' => false])->has('symbols'));
		$this->assertTrue(self::factory()->create(CustomMapConfigForm::class, null, ['map_symbols' => true])->has('symbols'));
	}

	/** @return array<string, array{string, bool}> symbols, accepted */
	public static function symbols(): array {
		return [
			'none' => ['', true],
			'a list' => ['MAP_A, MAP_B', true],
			'at its maximum' => [str_repeat('A', 255), true],
			'too long' => [str_repeat('A', 256), false],
		];
	}

	#[DataProvider('symbols')]
	public function testSymbols(string $symbols, bool $accepted): void {
		$form = self::factory()->create(CustomMapConfigForm::class, null, ['map_symbols' => true]);
		$form->submit(['symbols' => $symbols] + self::VALID);
		$this->assertSame($accepted, $form->isValid(), (string) $form->getErrors(true));
	}

	public function testMapSymbolsTakesABoolOnly(): void {
		$this->expectException(InvalidOptionsException::class);
		self::factory()->create(CustomMapConfigForm::class, null, ['map_symbols' => 'yes']);
	}

	private static function factory(): FormFactoryInterface {
		return Forms::createFormFactoryBuilder()
			->addExtension(new ValidatorExtension(Validation::createValidator()))
			->getFormFactory();
	}
}
