<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 MapActivityLog::getRawLastChangedAt(): the DATETIME as the database gave
 it, null when unset; a value of another type fails rather than reading as
 "never changed".
*/

namespace Tests\Unit\Models;

use App\Models\MapActivityLog;
use RuntimeException;
use Tests\Unit\UnitTestCase;

final class MapActivityLogTest extends UnitTestCase
{
	public function testRawValue(): void {
		$log = (new MapActivityLog())->setRawAttributes(['last_changed_at' => '2026-11-10 12:00:00']);
		$this->assertSame('2026-11-10 12:00:00', $log->getRawLastChangedAt());
	}

	public function testUnset(): void {
		$this->assertNull((new MapActivityLog())->getRawLastChangedAt());
	}

	public function testOtherTypeFails(): void {
		$log = (new MapActivityLog())->setRawAttributes(['last_changed_at' => 1762770000]);
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('map_activity_log.last_changed_at is not text: int');
		$log->getRawLastChangedAt();
	}
}
