<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Helper::getCountry() looks an address up only with geoip_enable on, a
 geoip_country_db set, and an address that is not private or reserved.
 There is no database here, so a lookup shows as its logged failure, which
 names the file, and the answer is null.
*/

namespace Tests\Unit\Helpers;

use App\Configuration\Config;
use App\Utils\Helper;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Unit\UnitTestCase;

final class CountryTest extends UnitTestCase
{
	private const string DB = '/nonexistent/rqwatch-country.mmdb';

	/** @return array<string, array{string|null, bool, string|null, bool}> address, geoip_enable, geoip_country_db, looked up */
	public static function cases(): array {
		return [
			'public address' => ['8.8.8.8', true, self::DB, true],
			'private address' => ['10.1.2.3', true, self::DB, false],
			'GeoIP off' => ['8.8.8.8', false, self::DB, false],
			'no database' => ['8.8.8.8', true, null, false],
			'empty database' => ['8.8.8.8', true, '', false],
			'no address' => [null, true, self::DB, false],
			'empty address' => ['', true, self::DB, false],
		];
	}

	#[DataProvider('cases')]
	public function testLookup(?string $ip, bool $enabled, ?string $db, bool $lookedUp): void {
		Config::set('geoip_enable', $enabled);
		if ($db !== null) {
			Config::set('geoip_country_db', $db);
		}
		$this->assertNull(Helper::getCountry($ip));
		if ($lookedUp) {
			$this->assertCount(1, $this->log->lines);
			$this->assertStringStartsWith('error GeoIP problem: ', $this->log->lines[0]);
			$this->assertStringContainsString(self::DB, $this->log->lines[0]);
		} else {
			$this->assertSame([], $this->log->lines);
		}
	}
}
