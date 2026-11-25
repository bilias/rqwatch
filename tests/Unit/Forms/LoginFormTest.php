<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The login form: a username of 2 to 128 letters, digits and ._+- with an
 optional @domain.tld, and a password of 1 to 128 characters, both
 required. The password is a password input and not part of the form data.
*/

namespace Tests\Unit\Forms;

use App\Forms\LoginForm;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Validator\Validation;
use Tests\Unit\UnitTestCase;

final class LoginFormTest extends UnitTestCase
{
	/** @return array<string, array{string, string, string|null}> username, password, the field with an error */
	public static function cases(): array {
		return [
			'valid' => ['alice', 'secret', null],
			'username with a domain' => ['alice@example.org', 'secret', null],
			'username empty' => ['', 'secret', 'username'],
			'username too short' => ['a', 'secret', 'username'],
			'username at its minimum' => ['ab', 'secret', null],
			'username at its maximum' => [str_repeat('a', 128), 'secret', null],
			'username too long' => [str_repeat('a', 129), 'secret', 'username'],
			'username with a space' => ['al ice', 'secret', 'username'],
			'password empty' => ['alice', '', 'password'],
			'password of one character' => ['alice', 'x', null],
			'password at its maximum' => ['alice', str_repeat('x', 128), null],
			'password too long' => ['alice', str_repeat('x', 129), 'password'],
		];
	}

	#[DataProvider('cases')]
	public function testLoginForm(string $username, string $password, ?string $field): void {
		$form = self::factory()->create(LoginForm::class);
		$form->submit(['username' => $username, 'password' => $password]);
		$this->assertSame($field === null, $form->isValid(), (string) $form->getErrors(true));
		if ($field !== null) {
			$this->assertGreaterThan(0, $form->get($field)->getErrors()->count(), $field);
		}
	}

	public function testPasswordHiddenAndUnmapped(): void {
		$form = self::factory()->create(LoginForm::class);
		$this->assertInstanceOf(PasswordType::class, $form->get('password')->getConfig()->getType()->getInnerType());
		$form->submit(['username' => 'alice', 'password' => 'secret']);
		$this->assertTrue($form->isValid(), (string) $form->getErrors(true));
		$this->assertSame('secret', $form->get('password')->getData());
		$data = $form->getData();
		$this->assertIsArray($data);
		$this->assertArrayNotHasKey('password', $data);
	}

	private static function factory(): FormFactoryInterface {
		return Forms::createFormFactoryBuilder()
			->addExtension(new ValidatorExtension(Validation::createValidator()))
			->getFormFactory();
	}
}
