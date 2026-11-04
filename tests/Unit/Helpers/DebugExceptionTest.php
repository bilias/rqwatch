<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Helper::debug_exception_err() and debug_exception_warn() log the message
 with the caller's file and line (not Helper.php's), at error and warning.
*/

namespace Tests\Unit\Helpers;

use App\Utils\Helper;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Unit\UnitTestCase;

final class DebugExceptionTest extends UnitTestCase
{
	#[TestDox('debug_exception_err logs at error, debug_exception_warn at warning, both with the caller\'s file:line')]
	public function testLevelsAndCaller(): void {
		$errLine = __LINE__ + 1;
		$err = Helper::debug_exception_err('boom');
		$warnLine = __LINE__ + 1;
		$warn = Helper::debug_exception_warn('unknown id');

		$this->assertSame(__FILE__ . ":{$errLine} 'boom'", $err);
		$this->assertSame(__FILE__ . ":{$warnLine} 'unknown id'", $warn);
		$this->assertSame(["error {$err}", "warning {$warn}"], $this->log->lines);
	}
}
