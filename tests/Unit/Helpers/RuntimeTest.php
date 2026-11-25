<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The runtime line the logs end with: seconds since the start, memory used
 since the start and peak memory, in MB.
*/

namespace Tests\Unit\Helpers;

use App\Utils\Helper;
use Tests\Unit\UnitTestCase;

final class RuntimeTest extends UnitTestCase
{
	// a start 2 s and 3 MB back: about that much is reported
	public function testRuntime(): void {
		$text = Helper::getRuntime(microtime(true) - 2.0, memory_get_usage() - 3 * 1048576);
		if (preg_match('/^Execution time: (\d+\.\d{3}) sec \| Memory used: (\d+\.\d{2}) MB \| Peak memory: (\d+\.\d{2}) MB$/D', $text, $m) !== 1) {
			$this->fail("Not a runtime line: {$text}");
		}
		$this->assertGreaterThanOrEqual(2.0, (float) $m[1]);
		$this->assertLessThan(3.0, (float) $m[1]);
		$this->assertGreaterThanOrEqual(3.0, (float) $m[2]);
		$this->assertLessThan(3.5, (float) $m[2]);
		$this->assertEqualsWithDelta(memory_get_peak_usage() / 1048576, (float) $m[3], 1.0);
	}
}
