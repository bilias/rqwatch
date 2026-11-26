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
 A match that fails (PCRE's limits) throws, rather than drop a digest.
*/

namespace Tests\Unit\Services;

use App\Services\FuzzyService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use ReflectionMethod;
use RuntimeException;
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
			"{$a}\n",
		]));
	}

	// a repeat before another digest: still a list
	public function testRepeatFirst(): void {
		$a = str_repeat('a1', 64);
		$b = str_repeat('0f', 64);
		$this->assertSame([$a, $b], self::digests([$a, $a, $b]));
	}

	public function testNone(): void {
		$this->assertSame([], self::digests([]));
		$this->assertSame([], self::digests(['abc', 5, null]));
	}

	// in its own process: a pattern compiled before with JIT ignores the limit
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testMatchFailureThrows(): void {
		$jit = (string) ini_get('pcre.jit');
		$limit = (string) ini_get('pcre.backtrack_limit');
		ini_set('pcre.jit', '0');
		ini_set('pcre.backtrack_limit', '1');
		try {
			$this->expectException(RuntimeException::class);
			$this->expectExceptionMessage('preg_match() failed on a fuzzy digest: Backtrack limit exhausted');
			self::digests([str_repeat('a1', 64)]);
		} finally {
			ini_set('pcre.jit', $jit);
			ini_set('pcre.backtrack_limit', $limit);
		}
	}

	/** @param array<array-key, mixed> $values */
	private static function digests(array $values): mixed {
		return (new ReflectionMethod(FuzzyService::class, 'digests'))->invoke(null, $values);
	}
}
