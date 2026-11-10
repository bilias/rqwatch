<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 FuzzyService::flags(): the $fuzzy_learn_flags lists, keyed by flag. An
 invalid entry is logged and left out; a label or symbol that is not text
 is one, where a cast used to accept it as "Array".
*/

namespace Tests\Unit\Services;

use App\Configuration\Config;
use App\Services\FuzzyService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Unit\UnitTestCase;

final class FuzzyFlagsTest extends UnitTestCase
{
	public function testValid(): void {
		Config::set('fuzzy_learn_flags', [
			1 => ['label' => ' Spam ', 'symbol' => 'FUZZY_SPAM', 'weight' => 10],
			2 => ['label' => 'Ham'],
		]);
		$this->assertSame([
			1 => ['label' => 'Spam', 'symbol' => 'FUZZY_SPAM', 'weight' => 10],
			2 => ['label' => 'Ham', 'symbol' => '', 'weight' => 10], // the default weight
		], FuzzyService::flags());
		$this->assertSame([], $this->log->lines);
	}

	/** @return array<string, array{array<string, mixed>}> */
	public static function invalid(): array {
		return [
			'label not text' => [['label' => ['Spam'], 'symbol' => 'FUZZY_SPAM']],
			'symbol not text' => [['label' => 'Spam', 'symbol' => ['FUZZY_SPAM']]],
			'label a number' => [['label' => 5]],
			'no label' => [['symbol' => 'FUZZY_SPAM']],
			'blank label' => [['label' => '  ']],
			'weight out of range' => [['label' => 'Spam', 'weight' => 0]],
		];
	}

	/** @param array<string, mixed> $entry */
	#[DataProvider('invalid')]
	public function testInvalidLeftOutAndLogged(array $entry): void {
		Config::set('fuzzy_learn_flags', [1 => $entry, 2 => ['label' => 'Ham']]);
		$this->assertSame([2], array_keys(FuzzyService::flags()));
		$this->assertLogged('invalid fuzzy_learn_flags entry');
	}
}
