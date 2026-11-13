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
 An empty password passes the form: the controller refuses it on add.
*/

namespace Tests\Unit\Forms;

use App\Forms\MailAliasForm;
use App\Forms\ProfileForm;
use App\Forms\UserForm;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Forms;
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
			'username with a space' => [['username' => 'al ice'], ['username']],
			'username domain without a dot' => [['username' => 'alice@localhost'], ['username']],
			'username empty' => [['username' => ''], ['username']],
			'email too short' => [['email' => 'a@b.c'], ['email']],
			'email empty' => [['email' => ''], ['email']],
			'email not an address' => [['email' => 'not an address'], ['email']],
			'email without a local part' => [['email' => '@example.org'], ['email']],
			'passwords differ' => [['password' => ['first' => 'long enough', 'second' => 'long enougH']], ['password']],
			'password too short' => [['password' => ['first' => 'short', 'second' => 'short']], ['password']],
			'password too long' => [['password' => ['first' => str_repeat('x', 129), 'second' => str_repeat('x', 129)]], ['password']],
			'no password' => [['password' => ['first' => '', 'second' => '']], []],
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
			'first name too long' => [['firstname' => str_repeat('x', 65)], ['firstname']],
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
			'alias too short' => ['alice', 'a@b.c', ['alias']],
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
