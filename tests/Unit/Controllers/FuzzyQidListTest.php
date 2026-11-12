<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The qids FuzzyController names after a learn whose digests other learned
 mails share: the first three, then how many more; a learned mail without
 a qid shows empty.
*/

namespace Tests\Unit\Controllers;

use App\Controllers\FuzzyController;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\Unit\UnitTestCase;

final class FuzzyQidListTest extends UnitTestCase
{
	/** @return array<string, array{list<string|null>, string}> */
	public static function lists(): array {
		return [
			'one' => [['QA'], 'QA'],
			'three' => [['QA', 'QB', 'QC'], 'QA, QB, QC'],
			'five' => [['QA', 'QB', 'QC', 'QD', 'QE'], 'QA, QB, QC (+2 more)'],
			'without a qid' => [['QA', null], 'QA, '],
		];
	}

	/** @param list<string|null> $qids */
	#[DataProvider('lists')]
	public function testQidList(array $qids, string $shown): void {
		$rows = array_map(fn (?string $qid): array => ['id' => 1, 'qid' => $qid, 'mail_log_id' => null, 'flag' => 11], $qids);
		$this->assertSame($shown, (new ReflectionMethod(FuzzyController::class, 'qidList'))->invoke(null, $rows));
	}
}
