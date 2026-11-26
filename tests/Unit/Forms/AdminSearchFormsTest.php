<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The admin search boxes. Fuzzy, user and mail alias search take letters,
 digits and ._+-@ only, up to 128 characters, at least one (two for an
 alias); the map entry search takes anything not blank, its map in the
 hidden fields. An empty search is refused by all four.
*/

namespace Tests\Unit\Forms;

use App\Forms\FuzzySearchForm;
use App\Forms\MailAliasSearchForm;
use App\Forms\MapSearchForm;
use App\Forms\UserSearchForm;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Validator\Validation;
use Tests\Unit\UnitTestCase;

final class AdminSearchFormsTest extends UnitTestCase
{
	/** @return array<string, array{class-string<FormTypeInterface<array<string, mixed>>>, string, string, bool}> form, field, value, valid */
	public static function searches(): array {
		$cases = [];
		foreach ([FuzzySearchForm::class => 'search', UserSearchForm::class => 'user', MailAliasSearchForm::class => 'alias'] as $class => $field) {
			$name = (new ReflectionClass($class))->getShortName();
			$cases += [
				"{$name}: every allowed character" => [$class, $field, 'a.B_c+1@x-y', true],
				"{$name}: 128 characters" => [$class, $field, str_repeat('a', 128), true],
				"{$name}: 129 characters" => [$class, $field, str_repeat('a', 129), false],
				"{$name}: a space" => [$class, $field, 'a b', false],
				"{$name}: a slash" => [$class, $field, 'a/b', false],
				"{$name}: empty" => [$class, $field, '', false],
			];
		}
		return $cases + [
			'FuzzySearchForm: one character' => [FuzzySearchForm::class, 'search', 'a', true],
			'UserSearchForm: one character' => [UserSearchForm::class, 'user', 'a', true],
			'MailAliasSearchForm: one character' => [MailAliasSearchForm::class, 'alias', 'a', false],
			'MailAliasSearchForm: two characters' => [MailAliasSearchForm::class, 'alias', 'ab', true],
			'MapSearchForm: anything' => [MapSearchForm::class, 'field', '192.0.2.0/24 #x', true],
			'MapSearchForm: empty' => [MapSearchForm::class, 'field', '', false],
		];
	}

	/** @param class-string<FormTypeInterface<array<string, mixed>>> $class */
	#[DataProvider('searches')]
	public function testSearch(string $class, string $field, string $value, bool $valid): void {
		$form = Forms::createFormFactoryBuilder()
			->addExtension(new ValidatorExtension(Validation::createValidator()))
			->getFormFactory()
			->create($class);
		$form->submit([$field => $value]);
		$this->assertSame($valid, $form->isValid(), (string) $form->getErrors(true));
		if (!$valid) {
			$this->assertGreaterThan(0, $form->get($field)->getErrors()->count());
		}
	}

	public function testMapSearchKeepsItsMap(): void {
		$form = Forms::createFormFactoryBuilder()
			->addExtension(new ValidatorExtension(Validation::createValidator()))
			->getFormFactory()
			->create(MapSearchForm::class);
		$form->submit(['field' => 'spammer', 'model' => 'MapCustom', 'map_name' => 'senders']);
		$this->assertTrue($form->isValid(), (string) $form->getErrors(true));
		$this->assertSame(['field' => 'spammer', 'model' => 'MapCustom', 'map_name' => 'senders'], $form->getData());
	}
}
