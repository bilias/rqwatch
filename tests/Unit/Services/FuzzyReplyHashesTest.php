<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The hashes of rspamd's fuzzyadd reply: kept, with their keys, only when
 every one is text in the digest format; otherwise the reply is unexpected
 (null), checked without a PHP warning for a value that is not text.
*/

namespace Tests\Unit\Services;

use App\Services\FuzzyService;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\Unit\UnitTestCase;

final class FuzzyReplyHashesTest extends UnitTestCase
{
	/** @return array<string, array{mixed}> */
	public static function unexpected(): array {
		$hash = str_repeat('a1', 64);
		return [
			'not an array' => ['abc'],
			'none' => [[]],
			'wrong format' => [[$hash, 'ABC']],
			'too short' => [[substr($hash, 1)]],
			'a nested array' => [[$hash, [$hash]]],
			'a number' => [[$hash, 7]],
		];
	}

	#[DataProvider('unexpected')]
	public function testUnexpectedReply(mixed $hashes): void {
		$this->assertNull(self::hashes($hashes));
	}

	public function testHashesKeptWithKeys(): void {
		$a = str_repeat('a1', 64);
		$b = str_repeat('0f', 64);
		$this->assertSame([0 => $a, 1 => $b], self::hashes([$a, $b]));
		$this->assertSame(['x' => $a], self::hashes(['x' => $a]));
	}

	private static function hashes(mixed $hashes): mixed {
		return (new ReflectionMethod(FuzzyService::class, 'replyHashes'))->invoke(null, $hashes);
	}
}
