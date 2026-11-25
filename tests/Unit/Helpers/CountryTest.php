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
 geoip_country_db set, and an address that is not private or reserved. A
 database that is not there is a logged failure naming the file, and null.
 In fixtures/geoip-country.mmdb (made-up data, see geoip-country.py) an
 address has its country's English name; one with no country, an empty name
 or no record has none. The database is opened once and kept, reopened when
 the configured file changes, and dropped after a failure.
*/

namespace Tests\Unit\Helpers;

use App\Configuration\Config;
use App\Utils\Helper;
use MaxMind\Db\Reader as MaxMindReader;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Tests\Unit\UnitTestCase;

final class CountryTest extends UnitTestCase
{
	private const string DB = '/nonexistent/rqwatch-country.mmdb';

	private const string FIXTURE = __DIR__ . '/../fixtures/geoip-country.mmdb';

	// the reader Helper keeps per process, closed before and after each test
	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		self::dropReader();
	}

	#[\Override]
	protected function tearDown(): void {
		self::dropReader();
		parent::tearDown();
	}

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

	/** @return array<string, array{string, string|null}> */
	public static function fixtureAnswers(): array {
		return [
			'a country' => ['2.2.2.2', 'Greece'],
			'IPv6' => ['2a02:2::1', 'Greece'],
			'no country in the record' => ['3.3.3.3', null],
			'an empty name' => ['4.4.4.4', null],
			'no record' => ['5.5.5.5', null],
		];
	}

	#[DataProvider('fixtureAnswers')]
	public function testCountry(string $ip, ?string $country): void {
		self::useDatabase(self::FIXTURE);
		$this->assertSame($country, Helper::getCountry($ip));
		$this->assertSame([], $this->log->lines);
	}

	public function testReaderKept(): void {
		self::useDatabase(self::FIXTURE);
		Helper::getCountry('2.2.2.2');
		$reader = self::reader();
		$this->assertNotNull($reader);
		$this->assertSame('Greece', Helper::getCountry('2.2.2.2'));
		$this->assertSame($reader, self::reader());
	}

	public function testOtherFileReopened(): void {
		self::useDatabase(self::FIXTURE);
		Helper::getCountry('2.2.2.2');
		$reader = self::reader();
		$copy = sys_get_temp_dir() . '/rqwatch-country-' . bin2hex(random_bytes(4)) . '.mmdb';
		copy(self::FIXTURE, $copy);
		try {
			Config::set('geoip_country_db', $copy);
			$this->assertSame('Greece', Helper::getCountry('2.2.2.2'));
			$this->assertNotNull(self::reader());
			$this->assertNotSame($reader, self::reader());
			$this->assertSame($copy, (new ReflectionProperty(Helper::class, 'geoipReaderDb'))->getValue());
		} finally {
			unlink($copy);
		}
	}

	// the next lookup opens the database again
	public function testFailureDropsTheReader(): void {
		self::useDatabase(self::FIXTURE);
		Helper::getCountry('2.2.2.2');
		Config::set('geoip_country_db', self::DB);
		$this->assertNull(Helper::getCountry('2.2.2.2'));
		$this->assertNull(self::reader());
		Config::set('geoip_country_db', self::FIXTURE);
		$this->assertSame('Greece', Helper::getCountry('2.2.2.2'));
	}

	private static function useDatabase(string $db): void {
		Config::set('geoip_enable', true);
		Config::set('geoip_country_db', $db);
	}

	private static function reader(): ?MaxMindReader {
		/** @var MaxMindReader|null $reader Helper::$geoipReader's type */
		$reader = (new ReflectionProperty(Helper::class, 'geoipReader'))->getValue();
		return $reader;
	}

	private static function dropReader(): void {
		(new ReflectionProperty(Helper::class, 'geoipReader'))->setValue(null, null);
		(new ReflectionProperty(Helper::class, 'geoipReaderDb'))->setValue(null, null);
	}
}
