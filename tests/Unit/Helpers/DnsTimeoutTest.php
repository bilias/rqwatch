<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 DNS lookups with dns_resolv on and dns_resolv_timeout unset fail naming
 the setting, before any query, instead of with a TypeError from netdns2.
*/

namespace Tests\Unit\Helpers;

use App\Configuration\Config;
use App\Utils\Helper;
use RuntimeException;
use Tests\Unit\UnitTestCase;

final class DnsTimeoutTest extends UnitTestCase
{
	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		Config::set('dns_resolv', true);
	}

	public function testReverseLookupNamesTheSetting(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('dns_resolv_timeout is not set in config');
		Helper::my_gethostbyaddr('8.8.8.8');
	}

	public function testForwardLookupNamesTheSetting(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('dns_resolv_timeout is not set in config');
		Helper::my_gethostbyname('example.org');
	}
}
