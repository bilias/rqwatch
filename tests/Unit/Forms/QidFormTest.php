<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 "Go to message" takes Postfix short (hex) and long (enable_long_queue_ids)
 queue IDs, up to the qid column's 30 characters, letters and digits only;
 an empty one is refused. A valid one redirects to the mail's page, the
 admin's for an admin; a form not submitted, or refused, does not.
*/

namespace Tests\Unit\Forms;

use App\Core\Routing\Routes;
use App\Forms\QidForm;
use App\Utils\FormHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Form\Extension\HttpFoundation\HttpFoundationExtension;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Validator\Validation;
use Tests\Unit\UnitTestCase;

final class QidFormTest extends UnitTestCase
{
	/** @return array<string, array{string, bool}> qid, accepted */
	public static function qids(): array {
		return [
			'short' => ['4B3E020C949', true],
			'short, small inode' => ['A1B2C3', true],
			'long' => ['3Pbn0X5lv5zJLaV', true],
			'long, 64-bit inode' => ['3Pbn0X5lv5zJLaVxYwTsRqP', true],
			'column width' => [str_repeat('A', 30), true],
			'too short' => ['A1B2C', false],
			'too long' => [str_repeat('A', 31), false],
			'trailing newline, trimmed by TextType' => ["4B3E020C949\n", true],
			'inner newline' => ["4B3E\n020C949", false],
			'punctuation' => ['4B3E-20C949', false],
			'empty' => ['', false],
		];
	}

	#[DataProvider('qids')]
	public function testQid(string $qid, bool $accepted): void {
		$form = self::form();
		$form->submit(['qid' => $qid]);

		$this->assertTrue($form->isSubmitted());
		$this->assertSame($accepted, $form->isValid(), (string) $form->getErrors(true));
	}

	public function testRedirect(): void {
		$urls = new UrlGenerator(Routes::load()['routes'], new RequestContext());
		$form = self::form();
		$form->submit(['qid' => '4B3E020C949']);
		$this->assertSame('/detail/qid/4B3E020C949', QidForm::check_form($form, $urls)?->getTargetUrl());
		$this->assertSame('/admin/detail/qid/4B3E020C949', QidForm::check_form($form, $urls, true)?->getTargetUrl());
	}

	public function testNoRedirect(): void {
		$urls = new UrlGenerator(Routes::load()['routes'], new RequestContext());
		$this->assertNull(QidForm::check_form(self::form(), $urls, true), 'not submitted');
		$form = self::form();
		$form->submit(['qid' => '4B3E-20C949']);
		$this->assertNull(QidForm::check_form($form, $urls, true), 'refused');
	}

	/**
	 * @psalm-return FormInterface<array<string, mixed>>
	 * @phpstan-return FormInterface
	 */
	private static function form(): FormInterface {
		$factory = Forms::createFormFactoryBuilder()
			->addExtension(new HttpFoundationExtension())
			->addExtension(new ValidatorExtension(Validation::createValidator()))
			->getFormFactory();
		return FormHelper::formCreator($factory, Request::create('/'), QidForm::class);
	}
}
