<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Controller::getRspamdStat() on the paths that need no rspamd: stats off for
 non-admins and by config, an empty stat_url, and RSPAMD_CONTROLLER_PASS
 missing from .env, which used to be a TypeError (a 500 on every admin page
 showing stats). A malformed stat_url fails at the request, before any
 connection. Replies from a real controller are left to the integration
 suite (Smoke/RspamdStatTest).
*/

namespace Tests\Unit\Controllers;

use App\Configuration\Config;
use App\Controllers\Controller;
use App\Controllers\MapController;
use ReflectionClass;
use ReflectionProperty;
use Tests\Unit\UnitTestCase;

final class RspamdStatTest extends UnitTestCase
{
	private ?string $password = null;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		// required at boot, so a booted app always has it
		Config::set('rspamd_stat_redis_key', 'rqwatch_rspamd_stats_test');
		/** @var mixed $saved */
		$saved = $_ENV['RSPAMD_CONTROLLER_PASS'] ?? null;
		$this->password = is_string($saved) ? $saved : null;
	}

	#[\Override]
	protected function tearDown(): void {
		if ($this->password === null) {
			unset($_ENV['RSPAMD_CONTROLLER_PASS']);
		} else {
			$_ENV['RSPAMD_CONTROLLER_PASS'] = $this->password;
		}
		parent::tearDown();
	}

	public function testNonAdminGetsNone(): void {
		$this->assertSame([], $this->controller(false)->getRspamdStat());
		$this->assertSame([], $this->log->lines);
	}

	public function testDisabled(): void {
		Config::set('rspamd_stat_disable', true);
		$this->assertSame([], $this->controller(true)->getRspamdStat());
		$this->assertSame([], $this->log->lines);
	}

	public function testEmptyStatUrl(): void {
		Config::set('API_SERVERS', ['mx1' => ['stat_url' => '']]);
		$_ENV['RSPAMD_CONTROLLER_PASS'] = 'secret';
		$this->assertSame([], $this->controller(true)->getRspamdStat());
		$this->assertLogged("API server 'mx1' has an empty stat_url");
	}

	public function testPasswordUnset(): void {
		Config::set('API_SERVERS', ['mx1' => ['stat_url' => 'http://']]);
		unset($_ENV['RSPAMD_CONTROLLER_PASS']);
		$this->assertSame([], $this->controller(true)->getRspamdStat());
		$this->assertLogged("Stat request to 'mx1' failed: Malformed URL");
	}

	private function controller(bool $isAdmin): Controller {
		$controller = (new ReflectionClass(MapController::class))->newInstanceWithoutConstructor();
		(new ReflectionProperty(Controller::class, 'is_admin'))->setValue($controller, $isAdmin);
		(new ReflectionProperty(Controller::class, 'fileLogger'))->setValue($controller, $this->log);
		return $controller;
	}
}
