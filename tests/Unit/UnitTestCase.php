<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Base for the unit tests: an App with a recording logger and no database,
 cache or DNS, and a config holding only what each test sets.
*/

namespace Tests\Unit;

use App\Configuration\Config;
use App\Core\App;
use App\Core\AppContainer;
use App\Utils\Helper;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

abstract class UnitTestCase extends TestCase
{
	protected RecordingLogger $log;

	protected function setUp(): void {
		$this->log = new RecordingLogger();
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, null, null, null, false));
		Config::init(['dns_resolv' => false, 'geoip_enable' => false]);
		// Helper caches the *_symbol_patterns config per process
		(new ReflectionProperty(Helper::class, 'symbolPatterns'))->setValue(null, []);
	}

	protected function tearDown(): void {
		App::reset();
		Config::init([]);
	}

	protected function assertLogged(string $needle): void {
		foreach ($this->log->lines as $line) {
			if (str_contains($line, $needle)) {
				$this->addToAssertionCount(1);
				return;
			}
		}
		$this->fail("No log line contains '{$needle}':\n" . implode("\n", $this->log->lines));
	}
}
