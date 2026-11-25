<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The digests matchedBy() and recordHits() look up in a mail's fuzzy_hashes:
 text in the digest format, each once, reindexed; anything else is dropped.
*/

namespace Tests\Unit\Services;

use App\Services\FuzzyService;
use ReflectionMethod;
use Tests\Unit\UnitTestCase;

final class FuzzyDigestsTest extends UnitTestCase
{
	public function testDigestsKeptOnceInOrder(): void {
		$a = str_repeat('a1', 64);
		$b = str_repeat('0f', 64);
		$this->assertSame([$a, $b], self::digests([
			'x' => $a,
			3 => 'ABC',
			'y' => $b,
			7,
			[$a],
			null,
			$a,
			substr($a, 1),
			strtoupper($b),
		]));
	}

	public function testNone(): void {
		$this->assertSame([], self::digests([]));
		$this->assertSame([], self::digests(['abc', 5, null]));
	}

	/** @param array<array-key, mixed> $values */
	private static function digests(array $values): mixed {
		return (new ReflectionMethod(FuzzyService::class, 'digests'))->invoke(null, $values);
	}
}
