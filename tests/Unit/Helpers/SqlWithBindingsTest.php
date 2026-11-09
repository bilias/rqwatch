<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Helper::sqlWithBindings(), the debug-log SQL of every getSqlFromQuery():
 bindings filled in quoted, a literal % in the SQL kept, and bindings that
 are not scalars shown instead of failing.
*/

namespace Tests\Unit\Helpers;

use App\Utils\Helper;
use DateTimeImmutable;
use stdClass;
use Tests\Unit\UnitTestCase;

final class SqlWithBindingsTest extends UnitTestCase
{
	public function testScalarBindings(): void {
		$this->assertSame(
			'select * from t where a = "5" and b like "%foo%" and c = "1.5" and d = "1" and e = ""',
			Helper::sqlWithBindings('select * from t where a = ? and b like ? and c = ? and d = ? and e = ?', [5, '%foo%', 1.5, true, null])
		);
	}

	// a % in the SQL text is not a vsprintf() directive
	public function testPercentInSql(): void {
		$this->assertSame(
			"select DATE_FORMAT(created_at, '%Y-%m') from t where id = \"7\"",
			Helper::sqlWithBindings("select DATE_FORMAT(created_at, '%Y-%m') from t where id = ?", [7])
		);
	}

	public function testDateAndObjectBindings(): void {
		$this->assertSame(
			'select * from t where d >= "2026-11-10 12:00:00" and o = "stdClass"',
			Helper::sqlWithBindings('select * from t where d >= ? and o = ?', [new DateTimeImmutable('2026-11-10 12:00:00'), new stdClass()])
		);
	}

	// bindings keyed by name are filled in order
	public function testKeyedBindings(): void {
		$this->assertSame('select "a", "b"', Helper::sqlWithBindings('select ?, ?', ['x' => 'a', 'y' => 'b']));
	}
}
