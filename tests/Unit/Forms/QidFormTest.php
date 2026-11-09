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
 queue IDs, up to the qid column's 30 characters, letters and digits only.
*/

namespace Tests\Unit\Forms;

use App\Forms\QidForm;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Forms;
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
		];
	}

	#[DataProvider('qids')]
	public function testQid(string $qid, bool $accepted): void {
		$factory = Forms::createFormFactoryBuilder()
			->addExtension(new ValidatorExtension(Validation::createValidator()))
			->getFormFactory();
		$form = $factory->create(QidForm::class);
		$form->submit(['qid' => $qid]);

		$this->assertTrue($form->isSubmitted());
		$this->assertSame($accepted, $form->isValid(), (string) $form->getErrors(true));
	}
}
