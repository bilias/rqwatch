<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The log settings LoggerFactory reads: a level by name in any case, and a
 syslog facility from the LOG_* facility constants. Anything else is an
 error naming the setting; an unknown facility no longer becomes LOG_MAIL.
*/

namespace Tests\Unit\Core;

use App\Core\Logging\LoggerFactory;
use Monolog\Level;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Unit\UnitTestCase;

final class LoggerFactoryTest extends UnitTestCase
{
	public function testLevel(): void {
		$this->assertSame(Level::Info, LoggerFactory::level('LOG_FILE_LEVEL', 'INFO'));
		$this->assertSame(Level::Debug, LoggerFactory::level('LOG_FILE_LEVEL', 'debug'));
		$this->assertSame(Level::Emergency, LoggerFactory::level('LOG_FILE_LEVEL', 'Emergency'));
	}

	public function testUnknownLevelThrows(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("LOG_SYSLOG_LEVEL 'VERBOSE' is not a log level");
		LoggerFactory::level('LOG_SYSLOG_LEVEL', 'VERBOSE');
	}

	public function testFacility(): void {
		$this->assertSame(LOG_MAIL, LoggerFactory::syslogFacility('LOG_MAIL'));
		$this->assertSame(LOG_LOCAL3, LoggerFactory::syslogFacility('LOG_LOCAL3'));
	}

	/** @return array<string, array{string}> */
	public static function notFacilities(): array {
		return [
			'typo' => ['LOG_LOCAL9'],
			'a priority, not a facility' => ['LOG_ERR'],
			'another constant' => ['E_ALL'],
			'lower case' => ['log_mail'],
		];
	}

	#[DataProvider('notFacilities')]
	public function testNotAFacilityThrows(string $name): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("LOG_SYSLOG_FACILITY '{$name}' is not a syslog facility");
		LoggerFactory::syslogFacility($name);
	}
}
