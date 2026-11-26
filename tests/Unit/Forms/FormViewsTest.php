<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 What each form puts in the page, field by field: label, the required
 attribute and the other HTML attributes (class, title, autocomplete,
 autofocus), with the buttons and the repeated password's two inputs. The
 login username label follows login_username_label, and an edit form does
 not require the password; the hidden fields have no label. A change here
 is the prompt to review the page.
*/

namespace Tests\Unit\Forms;

use App\Configuration\Config;
use App\Forms\CustomMapConfigForm;
use App\Forms\FuzzySearchForm;
use App\Forms\LoginForm;
use App\Forms\MailAliasForm;
use App\Forms\MailAliasSearchForm;
use App\Forms\MailReleaseForm;
use App\Forms\MapMailFromForm;
use App\Forms\MapMailFromRcptToForm;
use App\Forms\MapMailFromRcptToUserForm;
use App\Forms\MapSearchForm;
use App\Forms\ProfileForm;
use App\Forms\QidForm;
use App\Forms\UserDeleteForm;
use App\Forms\UserForm;
use App\Forms\UserSearchForm;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\Form\Forms;
use Symfony\Component\Validator\Validation;
use Tests\Unit\UnitTestCase;

final class FormViewsTest extends UnitTestCase
{
	/**
	 * field => [label, required, attr] and, for a compound field, its fields
	 * @return array<string, array{class-string<FormTypeInterface<array<string, mixed>>>, array<string, mixed>, array<string, string>, array<string, mixed>}> form, options, config, fields
	 */
	public static function forms(): array {
		return [
			'user' => [UserForm::class, [], [], [
				'username' => ['Username: ', true, ['class' => 'username', 'title' => 'Enter user username', 'autocomplete' => 'new-username']],
				'email' => ['E-mail: ', true, ['class' => 'email', 'title' => 'Enter user e-mail']],
				'password' => [null, true, [], [
					'first' => ['Password:', true, ['class' => 'password', 'title' => 'Enter user password', 'autocomplete' => 'new-password']],
					'second' => ['Repeat Password:', true, ['class' => 'password', 'title' => 'Enter user password', 'autocomplete' => 'new-password']],
				]],
				'firstname' => ['First Name: ', false, ['class' => 'firstname', 'title' => 'Enter user firstname']],
				'lastname' => ['Last Name: ', false, ['class' => 'lastname', 'title' => 'Enter user lastname']],
				'is_admin' => ['Admin', false, ['class' => 'is_admin', 'title' => 'Check if user is Admin']],
				'add' => ['Add User', null, []],
				'disable_notifications' => ['Notifications', true, ['class' => 'disable_notifications', 'title' => 'Mail notifications for quarantined mails']],
			]],
			'user, editing' => [UserForm::class, ['is_edit' => true], [], [
				'username' => ['Username: ', true, ['class' => 'username', 'title' => 'Enter user username', 'autocomplete' => 'new-username']],
				'email' => ['E-mail: ', true, ['class' => 'email', 'title' => 'Enter user e-mail']],
				'password' => [null, false, [], [
					'first' => ['Password:', false, ['class' => 'password', 'title' => 'Enter user password', 'autocomplete' => 'new-password']],
					'second' => ['Repeat Password:', false, ['class' => 'password', 'title' => 'Enter user password', 'autocomplete' => 'new-password']],
				]],
				'firstname' => ['First Name: ', false, ['class' => 'firstname', 'title' => 'Enter user firstname']],
				'lastname' => ['Last Name: ', false, ['class' => 'lastname', 'title' => 'Enter user lastname']],
				'is_admin' => ['Admin', false, ['class' => 'is_admin', 'title' => 'Check if user is Admin']],
				'add' => ['Update User', null, []],
				'disable_notifications' => ['Notifications', true, ['class' => 'disable_notifications', 'title' => 'Mail notifications for quarantined mails']],
			]],
			'profile' => [ProfileForm::class, [], [], [
				'firstname' => ['First Name: ', false, ['class' => 'firstname', 'title' => 'Enter user firstname']],
				'lastname' => ['Last Name: ', false, ['class' => 'lastname', 'title' => 'Enter user lastname']],
				'current_password' => ['Current Password:', false, ['class' => 'password', 'title' => 'Required to set a new password', 'autocomplete' => 'current-password']],
				'password' => [null, false, [], [
					'first' => ['Password:', false, ['class' => 'password', 'title' => 'Enter user password', 'autocomplete' => 'new-password']],
					'second' => ['Repeat Password:', false, ['class' => 'password', 'title' => 'Enter user password', 'autocomplete' => 'new-password']],
				]],
				'add' => ['Update', null, []],
				'disable_notifications' => ['Notifications', true, ['class' => 'disable_notifications', 'title' => 'Mail notifications for quarantined mails']],
			]],
			'mail alias' => [MailAliasForm::class, [], [], [
				'username' => ['Username: ', true, ['class' => 'username', 'title' => 'Enter user\'s username']],
				'alias' => ['E-mail Alias: ', true, ['class' => 'email', 'title' => 'Enter user e-mail alias']],
				'add' => ['Add', null, []],
			]],
			'login' => [LoginForm::class, [], [], [
				'username' => ['E-mail:', true, ['autofocus' => true, 'class' => 'username', 'title' => 'Enter your username']],
				'password' => ['Password: ', true, ['class' => 'password', 'title' => 'Enter your password']],
				'submit' => ['Login', null, []],
			]],
			'login, username label set' => [LoginForm::class, [], ['login_username_label' => 'Username'], [
				'username' => ['Username:', true, ['autofocus' => true, 'class' => 'username', 'title' => 'Enter your username']],
				'password' => ['Password: ', true, ['class' => 'password', 'title' => 'Enter your password']],
				'submit' => ['Login', null, []],
			]],
			'qid' => [QidForm::class, [], [], [
				'qid' => ['Go to message: ', true, ['class' => 'qidfield', 'title' => 'Enter Mail Queue ID of the mail']],
				'go' => ['Go', null, []],
			]],
			'custom map config' => [CustomMapConfigForm::class, ['map_symbols' => true], [], [
				'map_name' => ['Map Name: ', true, ['autofocus' => true, 'class' => 'map_name', 'title' => 'Enter Map Name']],
				'map_description' => ['Map Description: ', true, ['class' => 'label', 'title' => 'Enter Map description']],
				'field_name' => ['Field Name: ', true, ['class' => 'field_name', 'title' => 'Enter Field Name']],
				'field_label' => ['Field Label: ', true, ['class' => 'label', 'title' => 'Enter Field description']],
				'symbols' => ['Rspamd Symbols: ', false, ['class' => 'label', 'title' => 'Enter rspamd symbol names']],
				'submit' => ['Submit', null, []],
			]],
			'one-field map' => [MapMailFromForm::class, [], [], [
				'mail_from' => ['Mail From:', true, ['class' => 'uniform-input']],
				'submit' => ['Submit', null, []],
			]],
			'two-field map' => [MapMailFromRcptToForm::class, [], [], [
				'mail_from' => ['Mail From:', true, ['class' => 'uniform-input']],
				'rcpt_to' => ['RCPT To:', true, ['class' => 'uniform-input']],
				'submit' => ['Submit', null, []],
			]],
			'two-field map, a user\'s' => [MapMailFromRcptToUserForm::class, ['user_emails' => [0 => 'alice@example.org']], [], [
				'mail_from' => ['Mail From:', true, ['class' => 'uniform-input']],
				'rcpt_to' => ['RCPT To:', true, ['class' => 'uniform-input']],
				'submit' => ['Submit', null, []],
			]],
			'fuzzy search' => [FuzzySearchForm::class, [], [], [
				'search' => ['Fuzzy search: ', true, ['class' => 'field', 'title' => 'QID, learned by, server or hash prefix']],
				'submit' => ['Search', null, []],
			]],
			'mail alias search' => [MailAliasSearchForm::class, [], [], [
				'alias' => ['Mail Alias search: ', true, ['class' => 'field', 'title' => 'Search mail alias']],
				'search' => ['Search', null, []],
			]],
			'user search' => [UserSearchForm::class, [], [], [
				'user' => ['User search: ', true, ['class' => 'field', 'title' => 'Search user']],
				'search' => ['Search', null, []],
			]],
			'map entry search' => [MapSearchForm::class, [], [], [
				'field' => ['Entry search: ', true, ['class' => 'field', 'title' => 'Search entry in maps']],
				'model' => [null, false, []],
				'map_name' => [null, false, []],
				'search' => ['Search', null, []],
			]],
			'mail release' => [MailReleaseForm::class, [], [], [
				'email' => ['E-mail: ', false, ['class' => 'email', 'title' => 'E-mail for release']],
				'email_alt' => ['Alternative E-mail: ', false, ['class' => 'email', 'title' => 'Enter alternative e-mail(s) for release, comma separated', 'multiple' => 'multiple']],
				'release' => ['Release', false, ['class' => 'release', 'title' => 'Release mail to original recipient']],
				'release_alt' => ['Release to alternate recipient', false, ['class' => 'release', 'title' => 'Release mail to alternate recipient']],
				'submit' => ['Submit and Release Mail', null, []],
			]],
			'user delete, no user given' => [UserDeleteForm::class, [], [], [
				'id' => [null, false, []],
				'delete' => ['Delete User', null, ['class' => 'btn btn-danger', 'data-confirm' => 'Are you sure you want to delete user "this user"?']],
			]],
		];
	}

	/**
	 * @param class-string<FormTypeInterface<array<string, mixed>>> $class
	 * @param array<string, mixed> $options
	 * @param array<string, string> $config
	 * @param array<string, mixed> $fields
	 */
	#[DataProvider('forms')]
	public function testFormView(string $class, array $options, array $config, array $fields): void {
		foreach ($config as $key => $value) {
			Config::set($key, $value);
		}
		$view = Forms::createFormFactoryBuilder()
			->addExtension(new ValidatorExtension(Validation::createValidator()))
			->getFormFactory()
			->create($class, null, $options)
			->createView();
		$this->assertSame($fields, self::fields($view));
	}

	/** @return array<array-key, list<mixed>> */
	private static function fields(FormView $view): array {
		$fields = [];
		foreach ($view->children as $name => $child) {
			$field = [$child->vars['label'] ?? null, $child->vars['required'] ?? null, $child->vars['attr'] ?? []];
			if ($child->children !== []) {
				$field[] = self::fields($child);
			}
			$fields[$name] = $field;
		}
		return $fields;
	}
}
