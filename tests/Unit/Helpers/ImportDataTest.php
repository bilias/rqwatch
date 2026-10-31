<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Input checks on the import and lookup paths: column limits, malformed
 rspamd symbols, and id/qid parameters.
*/

namespace Tests\Unit\Helpers;

use App\Utils\Helper;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Unit\UnitTestCase;

final class ImportDataTest extends UnitTestCase
{
	public function testTrimToColumnLimits(): void {
		[$data, $debug] = Helper::trimDataToDbLimits(
			['subject' => str_repeat('α', 10), 'score' => 5.5, 'from' => 'short', 'x' => 'not limited'],
			['subject' => 4, 'score' => 2, 'from' => 10, 'missing' => 3]
		);
		$this->assertSame(['subject' => 'αααα', 'score' => 5.5, 'from' => 'short', 'x' => 'not limited'], $data);
		$this->assertSame(['Field subject exceeded max 4, truncated.'], $debug);
	}

	public function testTrimScrubsInvalidUtf8(): void {
		// strict mode refuses invalid UTF-8 (1366) and the metadata would be lost
		[$data, $debug] = Helper::trimDataToDbLimits(['from' => "ab\xFFcd"], ['from' => 10]);
		$this->assertSame('ab?cd', $data['from']);
		$this->assertSame([], $debug);
	}

	public function testSymbolsNotAList(): void {
		$this->assertSame([], Helper::sanitizeSymbols('x', 'Q1'));
		$this->assertLogged('Q1: symbols is not a list');
	}

	public function testSymbolRepairs(): void {
		$out = Helper::sanitizeSymbols([
			['name' => 'A', 'score' => 1.5, 'options' => ['o']],
			'junk',
			['name' => 'B'],
			['name' => 'C', 'score' => 2, 'options' => 'opt'],
		], 'Q2');
		$this->assertSame([
			['name' => 'A', 'score' => 1.5, 'options' => ['o']],
			['name' => 'B', 'score' => 0],
			['name' => 'C', 'score' => 2, 'options' => ['opt']],
		], $out);
		$this->assertLogged('Q2: dropped symbol entry that is not a record');
		$this->assertLogged('Q2: symbol B has no score, set to 0');
		$this->assertLogged('Q2: symbol C options not a list, wrapped');
	}

	public function testWellFormedSymbolsUntouched(): void {
		$symbols = [['name' => 'BAYES_SPAM', 'score' => 5.1, 'options' => ['99.9%']], ['name' => 'R_SPF_ALLOW', 'score' => -0.2]];
		$this->assertSame($symbols, Helper::sanitizeSymbols($symbols, 'Q3'));
		$this->assertSame([], $this->log->lines);
	}

	public function testSymbolsKeyedByNameKeepKeys(): void {
		$this->assertSame(['A' => ['name' => 'A', 'score' => 0]], Helper::sanitizeSymbols(['A' => ['name' => 'A']], 'Q4'));
	}

	public static function idQidCases(): array {
		return [
			'id' => ['id', '42', ['error' => false, 'id' => 42, 'qid' => false]],
			'id zero' => ['id', '0', ['error' => 'ID wrong format', 'id' => false, 'qid' => false]],
			'id negative' => ['id', '-1', ['error' => 'ID wrong format', 'id' => false, 'qid' => false]],
			'id with letters' => ['id', '1a', ['error' => 'ID wrong format', 'id' => false, 'qid' => false]],
			'id empty' => ['id', '', ['error' => 'Empty value', 'id' => false, 'qid' => false]],
			'qid' => ['qid', '4C1012A4C8D', ['error' => false, 'id' => false, 'qid' => '4C1012A4C8D']],
			'qid traversal' => ['qid', '../x', ['error' => 'QID wrong format', 'id' => false, 'qid' => false]],
			'qid with space' => ['qid', 'a b', ['error' => 'QID wrong format', 'id' => false, 'qid' => false]],
			'qid with trailing newline' => ['qid', "abc\n", ['error' => 'QID wrong format', 'id' => false, 'qid' => false]],
			'unknown type' => ['other', 'x', ['error' => 'Unknown error', 'id' => false, 'qid' => false]],
		];
	}

	#[DataProvider('idQidCases')]
	public function testCheckIdQid(string $type, string $value, array $expected): void {
		$this->assertSame($expected, Helper::check_id_qid($type, $value));
	}

	public function testCheckIdAcceptsInt(): void {
		$this->assertSame(['error' => false, 'id' => 7, 'qid' => false], Helper::check_id_qid('id', 7));
	}
}
