<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The user, profile and mail alias forms: a username is letters, digits and
 ._+- with an optional @domain.tld; an e-mail or alias is an address of
 6 to 128 characters; a password, when given, is 8 to 128
 characters and typed twice the same; names are at most 64 characters; the
 notifications choice maps Default, Yes and No to null, false and true.
 Each length is checked at its bounds. An empty password passes the form:
 the controller refuses it on add. Password fields are password inputs and
 never part of the form data; the Default notifications label follows
 notifications_default; the options take booleans only.
*/

namespace Tests\Unit\Forms;

use App\Forms\MailAliasForm;
use App\Forms\ProfileForm;
use App\Forms\UserForm;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Forms;
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;
use Symfony\Component\Validator\Validation;
use Tests\Unit\UnitTestCase;

final class UserFormsTest extends UnitTestCase
{
	private const array USER = [
		'username' => 'alice',
		'email' => 'alice@example.org',
		'password' => ['first' => 'long enough', 'second' => 'long enough'],
		'firstname' => 'Alice',
		'lastname' => 'Liddell',
		'disable_notifications' => 'default',
	];

	/** @return array<string, array{array<string, mixed>, list<string>}> changes to USER, fields with errors */
	public static function userCases(): array {
		return [
			'valid' => [[], []],
			'username with a domain' => [['username' => 'alice@example.org'], []],
			'username ._+-' => [['username' => 'a.l_i+c-e'], []],
			'username trimmed' => [['username' => " alice\n"], []],
			'username too short' => [['username' => 'a'], ['username']],
			'username at its minimum' => [['username' => 'ab'], []],
			'username at its maximum' => [['username' => str_repeat('a', 128)], []],
			'username too long' => [['username' => str_repeat('a', 129)], ['username']],
			'username with a space' => [['username' => 'al ice'], ['username']],
			'username domain without a dot' => [['username' => 'alice@localhost'], ['username']],
			'username empty' => [['username' => ''], ['username']],
			'email too short' => [['email' => 'a@b.c'], ['email']],
			'email at its minimum' => [['email' => 'a@b.cd'], []],
			'email at its maximum' => [['email' => str_repeat('a', 116) . '@example.org'], []],
			'email too long' => [['email' => str_repeat('a', 117) . '@example.org'], ['email']],
			'email empty' => [['email' => ''], ['email']],
			'email not an address' => [['email' => 'not an address'], ['email']],
			'email without a local part' => [['email' => '@example.org'], ['email']],
			'passwords differ' => [['password' => ['first' => 'long enough', 'second' => 'long enougH']], ['password']],
			'password too short' => [['password' => ['first' => 'short', 'second' => 'short']], ['password']],
			'password one short' => [['password' => ['first' => '1234567', 'second' => '1234567']], ['password']],
			'password at its minimum' => [['password' => ['first' => '12345678', 'second' => '12345678']], []],
			'password at its maximum' => [['password' => ['first' => str_repeat('x', 128), 'second' => str_repeat('x', 128)]], []],
			'password too long' => [['password' => ['first' => str_repeat('x', 129), 'second' => str_repeat('x', 129)]], ['password']],
			'no password' => [['password' => ['first' => '', 'second' => '']], []],
			'names at their maximum' => [['firstname' => str_repeat('x', 64), 'lastname' => str_repeat('x', 64)], []],
			'first name too long' => [['firstname' => str_repeat('x', 65)], ['firstname']],
			'last name too long' => [['lastname' => str_repeat('x', 65)], ['lastname']],
			'unknown notifications choice' => [['disable_notifications' => 'maybe'], ['disable_notifications']],
		];
	}

	/**
	 * @param array<string, mixed> $changes
	 * @param list<string> $errors
	 */
	#[DataProvider('userCases')]
	public function testUserForm(array $changes, array $errors): void {
		$form = self::factory()->create(UserForm::class);
		$form->submit(array_merge(self::USER, $changes));
		$this->assertSame($errors, self::errorFields($form), (string) $form->getErrors(true));
	}

	/** @return array<string, array{array<string, mixed>, list<string>}> profile data, fields with errors */
	public static function profileCases(): array {
		return [
			'names only' => [['firstname' => 'Alice', 'lastname' => ''], []],
			'new password' => [['current_password' => 'old', 'password' => ['first' => 'long enough', 'second' => 'long enough']], []],
			'passwords differ' => [['password' => ['first' => 'long enough', 'second' => 'other one!']], ['password']],
			'password too short' => [['password' => ['first' => 'short', 'second' => 'short']], ['password']],
			'password one short' => [['password' => ['first' => '1234567', 'second' => '1234567']], ['password']],
			'password at its minimum' => [['password' => ['first' => '12345678', 'second' => '12345678']], []],
			'password at its maximum' => [['password' => ['first' => str_repeat('x', 128), 'second' => str_repeat('x', 128)]], []],
			'password too long' => [['password' => ['first' => str_repeat('x', 129), 'second' => str_repeat('x', 129)]], ['password']],
			'names at their maximum' => [['firstname' => str_repeat('x', 64), 'lastname' => str_repeat('x', 64)], []],
			'first name too long' => [['firstname' => str_repeat('x', 65)], ['firstname']],
			'last name too long' => [['lastname' => str_repeat('x', 65)], ['lastname']],
		];
	}

	/**
	 * @param array<string, mixed> $data
	 * @param list<string> $errors
	 */
	#[DataProvider('profileCases')]
	public function testProfileForm(array $data, array $errors): void {
		$form = self::factory()->create(ProfileForm::class);
		$form->submit($data + ['disable_notifications' => 'default']);
		$this->assertSame($errors, self::errorFields($form), (string) $form->getErrors(true));
	}

	/** @return array<string, array{string, bool|null}> */
	public static function notificationCases(): array {
		return [
			'default' => ['default', null],
			'yes' => ['on', false],
			'no' => ['off', true],
		];
	}

	// stored as disable_notifications: null follows the default
	#[DataProvider('notificationCases')]
	public function testNotificationsChoice(string $choice, ?bool $disabled): void {
		$form = self::factory()->create(ProfileForm::class);
		$form->submit(['disable_notifications' => $choice]);
		$this->assertTrue($form->isValid(), (string) $form->getErrors(true));
		$this->assertSame($disabled, $form->get('disable_notifications')->getData());
	}

	/** @return array<string, array{string, string, list<string>}> username, alias, fields with errors */
	public static function aliasCases(): array {
		return [
			'valid' => ['alice', 'alice.smith@example.org', []],
			'username with a domain' => ['alice@example.org', 'a.smith@example.org', []],
			'username with a slash' => ['alice/x', 'alice.smith@example.org', ['username']],
			'username too short' => ['a', 'alice.smith@example.org', ['username']],
			'username at its minimum' => ['ab', 'alice.smith@example.org', []],
			'username at its maximum' => [str_repeat('a', 128), 'alice.smith@example.org', []],
			'username too long' => [str_repeat('a', 129), 'alice.smith@example.org', ['username']],
			'alias too short' => ['alice', 'a@b.c', ['alias']],
			'alias at its minimum' => ['alice', 'a@b.cd', []],
			'alias at its maximum' => ['alice', str_repeat('a', 116) . '@example.org', []],
			'alias too long' => ['alice', str_repeat('a', 117) . '@example.org', ['alias']],
			'alias not an address' => ['alice', 'not an address', ['alias']],
			'both empty' => ['', '', ['username', 'alias']],
		];
	}

	/** @param list<string> $errors */
	#[DataProvider('aliasCases')]
	public function testMailAliasForm(string $username, string $alias, array $errors): void {
		$form = self::factory()->create(MailAliasForm::class);
		$form->submit(['username' => $username, 'alias' => $alias]);
		$this->assertSame($errors, self::errorFields($form), (string) $form->getErrors(true));
	}

	public function testUserPasswordHiddenAndUnmapped(): void {
		$form = self::factory()->create(UserForm::class);
		$this->assertPasswordInput($form->get('password')->get('first'));
		$this->assertPasswordInput($form->get('password')->get('second'));
		$form->submit(self::USER);
		$this->assertTrue($form->isValid(), (string) $form->getErrors(true));
		$data = $form->getData();
		$this->assertIsArray($data);
		$this->assertArrayNotHasKey('password', $data);
	}

	public function testProfilePasswordsHiddenAndUnmapped(): void {
		$form = self::factory()->create(ProfileForm::class);
		$this->assertPasswordInput($form->get('current_password'));
		$this->assertPasswordInput($form->get('password')->get('first'));
		$this->assertPasswordInput($form->get('password')->get('second'));
		$form->submit([
			'current_password' => 'old one',
			'password' => ['first' => 'long enough', 'second' => 'long enough'],
			'disable_notifications' => 'default',
		]);
		$this->assertTrue($form->isValid(), (string) $form->getErrors(true));
		$data = $form->getData();
		$this->assertIsArray($data);
		$this->assertArrayNotHasKey('current_password', $data);
		$this->assertArrayNotHasKey('password', $data);
	}

	/** @return array<string, array{class-string<UserForm|ProfileForm>, array<string, bool>, string}> form, options, label */
	public static function notificationDefaults(): array {
		return [
			'user, unset' => [UserForm::class, [], 'Default (Yes)'],
			'user, off' => [UserForm::class, ['notifications_default' => false], 'Default (No)'],
			'profile, unset' => [ProfileForm::class, [], 'Default (Yes)'],
			'profile, off' => [ProfileForm::class, ['notifications_default' => false], 'Default (No)'],
		];
	}

	/**
	 * @param class-string<UserForm|ProfileForm> $type
	 * @param array<string, bool> $options
	 */
	#[DataProvider('notificationDefaults')]
	public function testNotificationsDefaultLabel(string $type, array $options, string $label): void {
		$choices = self::factory()->create($type, null, $options)->get('disable_notifications')->getConfig()->getOption('choices');
		$this->assertIsArray($choices);
		$this->assertSame($label, array_search('default', $choices, true));
	}

	public function testUserFormAddsUnlessEditing(): void {
		$this->assertSame('Add User', self::factory()->create(UserForm::class)->get('add')->getConfig()->getOption('label'));
		$this->assertSame('Update User',
			self::factory()->create(UserForm::class, null, ['is_edit' => true])->get('add')->getConfig()->getOption('label'));
	}

	/** @return array<string, array{class-string<UserForm|ProfileForm>, string}> form, option */
	public static function boolOptions(): array {
		return [
			'user is_edit' => [UserForm::class, 'is_edit'],
			'user notifications_default' => [UserForm::class, 'notifications_default'],
			'profile notifications_default' => [ProfileForm::class, 'notifications_default'],
		];
	}

	/** @param class-string<UserForm|ProfileForm> $type */
	#[DataProvider('boolOptions')]
	public function testOptionTakesABoolOnly(string $type, string $option): void {
		$this->expectException(InvalidOptionsException::class);
		self::factory()->create($type, null, [$option => 'yes']);
	}

	/** @param FormInterface<mixed> $field */
	private function assertPasswordInput(FormInterface $field): void {
		$this->assertInstanceOf(PasswordType::class, $field->getConfig()->getType()->getInnerType(), $field->getName());
	}

	private static function factory(): FormFactoryInterface {
		return Forms::createFormFactoryBuilder()
			->addExtension(new ValidatorExtension(Validation::createValidator()))
			->getFormFactory();
	}

	/**
	 * the top-level fields holding an error, in form order; a repeated
	 * field's errors count for the field itself
	 * @param FormInterface<mixed> $form
	 * @return list<string>
	 */
	private static function errorFields(FormInterface $form): array {
		$fields = [];
		foreach ($form as $name => $child) {
			if ($child->getErrors(true)->count() > 0) {
				$fields[] = $name;
			}
		}
		if ($form->getErrors()->count() > 0) {
			$fields[] = '(form)';
		}
		return $fields;
	}
}
