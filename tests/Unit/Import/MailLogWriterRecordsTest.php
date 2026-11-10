<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The after-commit records of MailLogWriter: a field they read that is not
 text is logged and the record skipped, never cast into "no hits", and
 never thrown at the caller. splitMailData() splits the mail_log_data
 columns from the rest.
*/

namespace Tests\Unit\Import;

use App\Services\Import\MailLogWriter;
use ReflectionClass;
use ReflectionMethod;
use Tests\Unit\UnitTestCase;

final class MailLogWriterRecordsTest extends UnitTestCase
{
	public function testMapHitsSymbolsNotText(): void {
		self::call('recordMapHits', 1, ['qid' => 'Q1', 'symbols' => 5]);
		$this->assertLogged('[MailLogWriter] Q1 could not record map hits: symbols is int, not text');
	}

	public function testVirusSymbolsNotText(): void {
		self::call('recordViruses', 1, ['qid' => 'Q1', 'has_virus' => 1, 'symbols' => ['x']]);
		$this->assertLogged('[MailLogWriter] Q1 could not record viruses: symbols is array, not text');
	}

	public function testFuzzyHashesNotText(): void {
		self::call('recordFuzzyHits', 1, ['qid' => 'Q1', 'fuzzy_hashes' => 7]);
		$this->assertLogged('[MailLogWriter] Q1 could not record fuzzy hits: fuzzy_hashes is int, not text');
	}

	public function testFuzzyHitTimeNotText(): void {
		self::call('recordFuzzyHits', 1, ['qid' => 'Q1', 'fuzzy_hashes' => '["abc"]', 'created_at' => 1700000000]);
		$this->assertLogged('[MailLogWriter] Q1 could not record fuzzy hits: created_at is int, not text');
	}

	public function testNoFuzzyHashesNothingLogged(): void {
		self::call('recordFuzzyHits', 1, ['qid' => 'Q1', 'fuzzy_hashes' => null]);
		self::call('recordFuzzyHits', 1, ['qid' => 'Q1', 'fuzzy_hashes' => '[]']);
		$this->assertSame([], $this->log->lines);
	}

	public function testSplitMailData(): void {
		$this->assertSame(
			[['qid' => 'Q1', 'score' => 1.5], ['headers' => 'H', 'symbols' => 'S']],
			self::call('splitMailData', ['qid' => 'Q1', 'headers' => 'H', 'score' => 1.5, 'symbols' => 'S'])
		);
	}

	private static function call(string $method, mixed ...$arguments): mixed {
		$writer = (new ReflectionClass(MailLogWriter::class))->newInstanceWithoutConstructor();
		return (new ReflectionMethod($writer, $method))->invoke($writer, ...$arguments);
	}
}
