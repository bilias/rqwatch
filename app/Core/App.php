<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Core;

use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Log\LoggerInterface;

use App\Core\Database\MigrationStatus;

use RuntimeException;

final class App
{
	private static ?AppContainer $instance = null;

	public static function init(AppContainer $container): void {
			if (self::$instance !== null) {
				throw new RuntimeException(
					'App has already been initialized.'
				);
			}

			self::$instance = $container;
	}

	public static function fileLogger(): LoggerInterface {
			return self::instance()->fileLogger;
	}

	public static function syslogLogger(): LoggerInterface {
			return self::instance()->syslogLogger;
	}

	public static function capsule(): Capsule {
			return self::instance()->capsule
				?? throw new RuntimeException(
					'Database capsule is not available in this context.'
				);
	}

	public static function migrationStatus(): MigrationStatus {
			return self::instance()->migrationStatus
				?? throw new RuntimeException(
					'MigrationStatus is not available in this context.'
				);
	}

	/*
	public static function services(): ServiceFactory
	{
			return self::instance()->services;
	}
	*/

	// Mainly for PHPUnit/testing.
	public static function swap(AppContainer $container): void
	{
			self::$instance = $container;
	}

	// Mainly for PHPUnit/testing.
	public static function reset(): void {
			self::$instance = null;
	}

	private static function instance(): AppContainer {
			return self::$instance
				?? throw new RuntimeException(
					'App::init() has not been called.'
				);
	}

}
