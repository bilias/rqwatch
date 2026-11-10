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
 -, but not digits only, which PHP would turn into an int config key.
*/

namespace Tests\Unit\Forms;

use App\Forms\CustomMapConfigForm;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Forms;
use Symfony\Component\Validator\Validation;
use Tests\Unit\UnitTestCase;

final class CustomMapConfigFormTest extends UnitTestCase
{
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
		$form->submit([
			'map_name' => $name,
			'map_description' => 'Config test',
			'field_name' => 'domain',
			'field_label' => 'Domain name',
		]);

		$errors = $form->get('map_name')->getErrors();
		$this->assertCount($error === null ? 0 : 1, $errors, (string) $errors);
		if ($error !== null) {
			$this->assertStringContainsString($error, (string) $errors);
		}
	}
}
