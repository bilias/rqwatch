<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Each form's create(), as the controllers call it: the action and method it
 posts to, the map forms' CSS class, the data it starts with, and the
 request handled, so a GET shows it unsubmitted and a POST of its fields
 submits them. The release form's own address cannot be changed by a post,
 and the delete button asks about the user by name. SearchForm::create()
 is in SearchFormTest, which gives it its migration status.
*/

namespace Tests\Unit\Forms;

use App\Core\Routing\Routes;
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
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Form\Extension\HttpFoundation\HttpFoundationExtension;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Validator\Validation;
use Tests\Unit\UnitTestCase;

final class FormCreateTest extends UnitTestCase
{
	/**
	 * a form built as its controller builds it, from the request given
	 * @return array<string, array{Closure(FormFactoryInterface, Request, UrlGeneratorInterface): FormInterface, string, string|null, array<string, mixed>, string, mixed}>
	 *   create, action, CSS class, posted fields, a field, its data after the post
	 */
	public static function forms(): array {
		return [
			'fuzzy search' => [
				fn (FormFactoryInterface $f, Request $r, UrlGeneratorInterface $u): FormInterface => FuzzySearchForm::create($f, $r, $u),
				'/admin/fuzzy/search', null, ['search' => 'ABC123'], 'search', 'ABC123',
			],
			'mail alias search' => [
				fn (FormFactoryInterface $f, Request $r, UrlGeneratorInterface $u): FormInterface => MailAliasSearchForm::create($f, $r, $u),
				'/admin/aliases/search', null, ['alias' => 'alice'], 'alias', 'alice',
			],
			'user search' => [
				fn (FormFactoryInterface $f, Request $r, UrlGeneratorInterface $u): FormInterface => UserSearchForm::create($f, $r, $u),
				'/admin/user/search', null, ['user' => 'alice'], 'user', 'alice',
			],
			'map entry search' => [
				fn (FormFactoryInterface $f, Request $r, UrlGeneratorInterface $u): FormInterface => MapSearchForm::create($f, $r, $u, ['model' => 'MapCombined', 'map_name' => 'ip_blacklist']),
				'/admin/map/search', null, ['field' => '192.0.2.1', 'model' => 'MapCombined', 'map_name' => 'ip_blacklist'], 'field', '192.0.2.1',
			],
			'mail release' => [
				fn (FormFactoryInterface $f, Request $r, UrlGeneratorInterface $u): FormInterface => MailReleaseForm::create($f, $r, ['email' => 'alice@example.org']),
				'', null, ['email_alt' => 'bob@example.org', 'release_alt' => '1'], 'email_alt', 'bob@example.org',
			],
			'user delete' => [
				fn (FormFactoryInterface $f, Request $r, UrlGeneratorInterface $u): FormInterface => UserDeleteForm::create($f, $r, ['id' => '7', 'username' => 'bob']),
				'', null, ['id' => '7'], 'id', '7',
			],
			'go to message' => [
				fn (FormFactoryInterface $f, Request $r, UrlGeneratorInterface $u): FormInterface => QidForm::create($f, $r),
				'', null, ['qid' => 'ABC123'], 'qid', 'ABC123',
			],
			'login' => [
				fn (FormFactoryInterface $f, Request $r, UrlGeneratorInterface $u): FormInterface => LoginForm::create($f, $r),
				'', null, ['username' => 'alice', 'password' => 'secret'], 'username', 'alice',
			],
			'user' => [
				fn (FormFactoryInterface $f, Request $r, UrlGeneratorInterface $u): FormInterface => UserForm::create($f, $r, null, ['is_edit' => true]),
				'', null, ['username' => 'alice', 'email' => 'alice@example.org'], 'username', 'alice',
			],
			'profile' => [
				fn (FormFactoryInterface $f, Request $r, UrlGeneratorInterface $u): FormInterface => ProfileForm::create($f, $r, ['firstname' => 'Alice']),
				'', null, ['lastname' => 'Smith'], 'lastname', 'Smith',
			],
			'mail alias' => [
				fn (FormFactoryInterface $f, Request $r, UrlGeneratorInterface $u): FormInterface => MailAliasForm::create($f, $r, ['username' => 'alice']),
				'', null, ['username' => 'alice', 'alias' => 'a.smith@example.org'], 'alias', 'a.smith@example.org',
			],
			'custom map config' => [
				fn (FormFactoryInterface $f, Request $r, UrlGeneratorInterface $u): FormInterface => CustomMapConfigForm::create($f, $r, null, ['map_symbols' => true]),
				'', 'onefieldform', ['map_name' => 'senders'], 'map_name', 'senders',
			],
			'one-field map' => [
				fn (FormFactoryInterface $f, Request $r, UrlGeneratorInterface $u): FormInterface => MapMailFromForm::create($f, $r),
				'', 'onefieldform', ['mail_from' => 'spammer@example.org'], 'mail_from', 'spammer@example.org',
			],
			'two-field map' => [
				fn (FormFactoryInterface $f, Request $r, UrlGeneratorInterface $u): FormInterface => MapMailFromRcptToForm::create($f, $r),
				'', 'twofieldsform', ['mail_from' => 'spammer@example.org', 'rcpt_to' => 'bob@example.org'], 'rcpt_to', 'bob@example.org',
			],
			"two-field map, a user's" => [
				fn (FormFactoryInterface $f, Request $r, UrlGeneratorInterface $u): FormInterface => MapMailFromRcptToUserForm::create($f, $r, null, ['user_emails' => ['alice@example.org']]),
				'', 'twofieldsform', ['mail_from' => 'spammer@example.org', 'rcpt_to' => 'alice@example.org'], 'rcpt_to', 'alice@example.org',
			],
		];
	}

	/**
	 * @param Closure(FormFactoryInterface, Request, UrlGeneratorInterface): FormInterface $create
	 * @param array<string, mixed> $post
	 */
	#[DataProvider('forms')]
	public function testCreate(Closure $create, string $action, ?string $class, array $post, string $field, mixed $data): void {
		$shown = $create(self::factory(), Request::create('/page'), self::urls());
		$this->assertFalse($shown->isSubmitted());
		$this->assertSame($action, $shown->getConfig()->getAction());
		$this->assertSame('POST', $shown->getConfig()->getMethod());
		$attr = $shown->getConfig()->getOption('attr');
		$this->assertIsArray($attr);
		$this->assertSame($class, $attr['class'] ?? null);

		$posted = $create(self::factory(), Request::create('/page', 'POST', [$shown->getName() => $post]), self::urls());
		$this->assertTrue($posted->isSubmitted());
		$this->assertSame($data, $posted->get($field)->getData());
	}

	// what the search form starts with goes in its hidden fields
	public function testMapSearchCarriesItsMap(): void {
		$form = MapSearchForm::create(self::factory(), Request::create('/page'), self::urls(), ['model' => 'MapCustom', 'map_name' => 'senders']);
		$this->assertSame('MapCustom', $form->get('model')->getData());
		$this->assertSame('senders', $form->get('map_name')->getData());
	}

	public function testReleaseAddressNotPosted(): void {
		$form = MailReleaseForm::create(self::factory(), Request::create('/page', 'POST', ['mail_release_form' => ['email' => 'mallory@example.org']]),
			['email' => 'alice@example.org']);
		$this->assertTrue($form->isSubmitted());
		$this->assertSame('alice@example.org', $form->get('email')->getData());
	}

	/** @return array<string, array{array<string, mixed>, string}> */
	public static function deletes(): array {
		return [
			'by name' => [['id' => '7', 'username' => 'bob'], 'Are you sure you want to delete user "bob"?'],
			'no name' => [['id' => '7'], 'Are you sure you want to delete user "this user"?'],
			'a name not text' => [['id' => '7', 'username' => ['bob']], 'Are you sure you want to delete user "this user"?'],
		];
	}

	/** @param array<string, mixed> $data */
	#[DataProvider('deletes')]
	public function testDeleteAsksByName(array $data, string $question): void {
		$form = UserDeleteForm::create(self::factory(), Request::create('/page'), $data);
		$attr = $form->get('delete')->getConfig()->getOption('attr');
		$this->assertIsArray($attr);
		$this->assertSame($question, $attr['data-confirm'] ?? null);
	}

	private static function factory(): FormFactoryInterface {
		return Forms::createFormFactoryBuilder()
			->addExtension(new HttpFoundationExtension())
			->addExtension(new ValidatorExtension(Validation::createValidator()))
			->getFormFactory();
	}

	private static function urls(): UrlGeneratorInterface {
		return new UrlGenerator(Routes::load()['routes'], new RequestContext());
	}
}
