<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 What every API refuses before its own work, each answered 403 and logged
 with the API's prefix to the file log and syslog: the API switched off
 (checked first), then a client not in the API's IP ACL (Basic Auth's
 own refusals: BasicAuthTest). An answer's log line has the API's prefix,
 none when it has none, and is not logged at a level the logger lacks.
*/

namespace Tests\Unit\Api;

use App\Api\GetMailApi;
use App\Api\RqwatchApi;
use App\Core\Auth\BasicAuth;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\Request;

final class ApiRefusalsTest extends ApiTestCase
{
	private static function api(): GetMailApi {
		return new GetMailApi(Request::create('/api/get_mail.php', 'POST'));
	}

	public function testBuilt(): void {
		$api = self::api();
		$auth = (new ReflectionProperty(RqwatchApi::class, 'auth'))->getValue($api);
		$this->assertInstanceOf(BasicAuth::class, $auth);
		$this->assertSame('rspamd', $auth->getAuthenticatedUser());
		$this->assertSame([], $this->log->lines);
	}

	public function testApiOff(): void {
		$this->setEnv('API_ENABLE', 'false');
		// not in the ACL either: the switch comes first
		$this->setServer('REMOTE_ADDR', '203.0.113.9');
		$this->assertAnswer(403, 'API is disabled', $this->answer(self::api(...)));
		$line = "warning [GetMailApi] 203.0.113.9 requested '/api/test' but API is disabled.";
		$this->assertSame([$line, $line], $this->log->lines);
	}

	public function testApiOffNoUri(): void {
		$this->setEnv('API_ENABLE', null);
		$this->setServer('REQUEST_URI', null);
		$this->assertAnswer(403, 'API is disabled', $this->answer(self::api(...)));
		$this->assertSame("warning [GetMailApi] 192.0.2.7 requested 'UNKNOWN' but API is disabled.", $this->log->lines[0] ?? null);
	}

	public function testNotInAcl(): void {
		$this->setServer('REMOTE_ADDR', '203.0.113.9');
		$this->assertAnswer(403, 'Permission denied', $this->answer(self::api(...)));
		$line = 'warning [GetMailApi] 203.0.113.9 is not allowed to access this API';
		$this->assertSame([$line, $line], $this->log->lines);
	}

	private function drop(string $prefix, string $level): void {
		$api = self::api();
		(new ReflectionProperty(RqwatchApi::class, 'logPrefix'))->setValue($api, $prefix);
		$this->assertAnswer(418, 'teapot', $this->answer(
			fn (): mixed => (new ReflectionMethod(RqwatchApi::class, 'dropLogResponse'))->invoke($api, 418, 'teapot', 'brewing', $level)
		));
	}

	public function testAnswerLogged(): void {
		$this->drop('GetMailApi', 'notice');
		$this->assertSame(['notice [GetMailApi] brewing', 'notice [GetMailApi] brewing'], $this->log->lines);
	}

	public function testAnswerWithoutPrefix(): void {
		$this->drop('', 'error');
		$this->assertSame(['error brewing', 'error brewing'], $this->log->lines);
	}

	public function testAnswerAtNoLevel(): void {
		$this->drop('GetMailApi', 'shout');
		$this->assertSame([], $this->log->lines);
	}
}
