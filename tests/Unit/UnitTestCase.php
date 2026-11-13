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
 cache or DNS, and a config holding only what each test sets. setEnv()
 changes a .env value for one test.
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

	/** @var array<non-empty-string, string|null> .env values setEnv() changed, as they were */
	private array $envSaved = [];

	#[\Override]
	protected function setUp(): void {
		$this->log = new RecordingLogger();
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, null, null, null, false));
		Config::init(['dns_resolv' => false, 'geoip_enable' => false]);
		// Helper caches the *_symbol_patterns config per process
		(new ReflectionProperty(Helper::class, 'symbolPatterns'))->setValue(null, []);
	}

	#[\Override]
	protected function tearDown(): void {
		foreach ($this->envSaved as $key => $value) {
			if ($value === null) {
				unset($_ENV[$key]);
			} else {
				$_ENV[$key] = $value;
			}
		}
		$this->envSaved = [];
		App::reset();
		Config::init([]);
	}

	/**
	 * a .env value for this test only (null: unset), put back in tearDown()
	 * @param non-empty-string $key
	 */
	protected function setEnv(string $key, ?string $value): void {
		if (!array_key_exists($key, $this->envSaved)) {
			$this->envSaved[$key] = Helper::env_string($key);
		}
		if ($value === null) {
			unset($_ENV[$key]);
		} else {
			$_ENV[$key] = $value;
		}
	}

	protected function assertLogged(string $needle): void {
		foreach ($this->log->lines as $line) {
			if (str_contains($line, $needle)) {
				$this->assertStringContainsString($needle, $line);
				return;
			}
		}
		$this->fail("No log line contains '{$needle}':\n" . implode("\n", $this->log->lines));
	}
}
