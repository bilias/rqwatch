<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 MailTokenService::idleSeconds(): notification_links_idle_minutes in
 seconds, the default of 10 minutes when it is unset or below 1.
*/

namespace Tests\Unit\Services;

use App\Configuration\Config;
use App\Services\MailTokenService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Unit\UnitTestCase;

final class MailTokenIdleTest extends UnitTestCase
{
	/** @return array<string, array{int|null, int}> */
	public static function minutes(): array {
		return [
			'unset' => [null, 600],
			'one' => [1, 60],
			'five' => [5, 300],
			'zero' => [0, 600],
			'negative' => [-3, 600],
		];
	}

	#[DataProvider('minutes')]
	public function testIdleSeconds(?int $minutes, int $seconds): void {
		Config::set('notification_links_idle_minutes', $minutes);
		$this->assertSame($seconds, MailTokenService::idleSeconds());
	}
}
