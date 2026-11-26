<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The search form refuses a REGEXP value that is not a valid regular
 expression, for both regexp choices, and checks no other choice's value
 as one; no choice, or no value, is nothing to check. Built (as create()
 builds it, from the request) it offers an admin the admin-only filters
 too, a user not; it needs a filter and a choice from its lists and a
 value of 1 to 64 characters. is_admin must be given, as a bool.
*/

namespace Tests\Unit\Forms;

use App\Core\App;
use App\Core\AppContainer;
use App\Core\Database\MigrationStatus;
use App\Forms\SearchForm;
use App\Utils\FormHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Form\Extension\HttpFoundation\HttpFoundationExtension;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;
use Symfony\Component\OptionsResolver\Exception\MissingOptionsException;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Validation;
use Tests\Unit\UnitTestCase;

final class SearchFormTest extends UnitTestCase
{
	// the filters depend on the migrations done: none here
	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, null, new MigrationStatus(null, $this->log), null, false));
	}

	/** @return array<string, array{mixed, mixed, bool}> */
	public static function searches(): array {
		return [
			'valid regexp' => ['matches REGEXP', '^re: (spam|ham)$', true],
			'regexp with a slash' => ['not matches REGEXP', 'example\.com/path', true],
			'invalid regexp' => ['matches REGEXP', '(unclosed', false],
			'invalid, negated' => ['not matches REGEXP', '[a-', false],
			'not a regexp choice' => ['contains', '(unclosed', true],
			'no choice' => [null, '(unclosed', true],
			'empty value' => ['matches REGEXP', null, true],
		];
	}

	#[DataProvider('searches')]
	public function testRegexpValue(mixed $choice, mixed $value, bool $valid): void {
		$violations = Validation::createValidator()->validate(
			['filter' => 'Subject', 'choice' => $choice, 'value' => $value],
			new Callback([SearchForm::class, 'validateRegexpValue'])
		);
		$this->assertCount($valid ? 0 : 1, $violations);
		if (!$valid) {
			$this->assertSame('Invalid regular expression.', (string) $violations->get(0)->getMessage());
		}
	}

	public function testFilters(): void {
		$admin = self::factory()->create(SearchForm::class, null, ['is_admin' => true])->get('filter')->getConfig()->getOption('choices');
		$user = self::factory()->create(SearchForm::class, null, ['is_admin' => false])->get('filter')->getConfig()->getOption('choices');
		$this->assertSame(FormHelper::getSearchFilters(true), $admin);
		$this->assertSame(FormHelper::getSearchFilters(false), $user);
		$this->assertSame(['Server' => 'Server'], array_diff_key($admin, $user));
	}

	public function testFields(): void {
		$view = self::factory()->create(SearchForm::class, null, ['is_admin' => false])->createView();
		$fields = [];
		foreach ($view->children as $name => $child) {
			$fields[$name] = [$child->vars['label'] ?? null, $child->vars['required'] ?? null];
		}
		$this->assertSame(['filter' => ['Filter: ', true], 'choice' => ['Choice: ', true], 'value' => [null, false], 'search' => ['Add', null]], $fields);
		$this->assertSame(FormHelper::getSearchChoices(), self::factory()->create(SearchForm::class, null, ['is_admin' => false])
			->get('choice')->getConfig()->getOption('choices'));
	}

	/** @return array<string, array{bool, string, string, string, bool}> admin, filter, choice, value, valid */
	public static function submissions(): array {
		return [
			'a search' => [false, 'Subject', 'contains', 'invoice', true],
			'an admin filter, by an admin' => [true, 'Server', 'is equal to', 'mx1', true],
			'an admin filter, by a user' => [false, 'Server', 'is equal to', 'mx1', false],
			'no filter' => [false, '', 'contains', 'invoice', false],
			'an unknown filter' => [false, 'mail_from', 'contains', 'invoice', false],
			'no choice' => [false, 'Subject', '', 'invoice', false],
			'an operator, not a choice' => [false, 'Subject', 'LIKE', 'invoice', false],
			'no value' => [false, 'Subject', 'contains', '', false],
			'one character' => [false, 'Subject', 'contains', 'x', true],
			'64 characters' => [false, 'Subject', 'contains', str_repeat('x', 64), true],
			'65 characters' => [false, 'Subject', 'contains', str_repeat('x', 65), false],
			'an invalid REGEXP' => [false, 'Subject', 'matches REGEXP', '(unclosed', false],
		];
	}

	#[DataProvider('submissions')]
	public function testSubmit(bool $admin, string $filter, string $choice, string $value, bool $valid): void {
		$form = SearchForm::create(self::factory(), Request::create('/search', 'POST',
			['search_form' => ['filter' => $filter, 'choice' => $choice, 'value' => $value]]), $admin);
		$this->assertTrue($form->isSubmitted());
		$this->assertSame($valid, $form->isValid(), (string) $form->getErrors(true));
	}

	public function testAdminFlagRequired(): void {
		$this->expectException(MissingOptionsException::class);
		self::factory()->create(SearchForm::class);
	}

	public function testAdminFlagIsABool(): void {
		$this->expectException(InvalidOptionsException::class);
		self::factory()->create(SearchForm::class, null, ['is_admin' => 'yes']);
	}

	private static function factory(): FormFactoryInterface {
		return Forms::createFormFactoryBuilder()
			->addExtension(new HttpFoundationExtension())
			->addExtension(new ValidatorExtension(Validation::createValidator()))
			->getFormFactory();
	}
}
