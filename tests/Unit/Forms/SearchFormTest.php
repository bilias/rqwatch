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
 as one; no choice, or no value, is nothing to check.
*/

namespace Tests\Unit\Forms;

use App\Forms\SearchForm;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Validation;
use Tests\Unit\UnitTestCase;

final class SearchFormTest extends UnitTestCase
{
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
}
