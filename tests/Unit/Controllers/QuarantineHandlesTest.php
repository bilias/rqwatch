<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The notification-link handles QuarantineController keeps in the session:
 what link() stored is read back and an idle handle expires; anything else
 there fails with the session key named, rather than reading as no handle.
 Only the paths that stop before the database are run.
*/

namespace Tests\Unit\Controllers;

use App\Controllers\QuarantineController;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Tests\Unit\UnitTestCase;

final class QuarantineHandlesTest extends UnitTestCase
{
	private const string KEY = 'notify_tokens';

	public function testStoredHandles(): void {
		$entry = ['hash' => 'h', 'redeemed' => false, 'seen' => 1];
		[$controller] = $this->controller(['aa' => $entry]);
		$this->assertSame(['aa' => $entry], self::call($controller, 'handles'));
	}

	public function testNoHandles(): void {
		$controller = (new ReflectionClass(QuarantineController::class))->newInstanceWithoutConstructor();
		(new ReflectionProperty($controller, 'session'))->setValue($controller, new Session(new MockArraySessionStorage()));
		$this->assertSame([], self::call($controller, 'handles'));
		$this->assertNull(self::call($controller, 'sessionToken', 'aa'));
	}

	public function testIdleHandleExpires(): void {
		[$controller, $session] = $this->controller(['aa' => ['hash' => 'h', 'redeemed' => false, 'seen' => time() - 86400 * 30]]);
		$this->assertNull(self::call($controller, 'sessionToken', 'aa'));
		$this->assertSame([], $session->get(self::KEY));
	}

	public function testDamagedStoreFails(): void {
		[$controller] = $this->controller('junk');
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("Session value 'notify_tokens' must be an array, not string");
		self::call($controller, 'sessionToken', 'aa');
	}

	public function testHandleNotAnArrayFails(): void {
		[$controller] = $this->controller(['aa' => 'junk']);
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("Session value 'notify_tokens' has a handle that is not an array: string");
		self::call($controller, 'handles');
	}

	public function testSeenNotATimeFails(): void {
		[$controller] = $this->controller(['aa' => ['hash' => 'h', 'redeemed' => false, 'seen' => 'abc']]);
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("Session handle's last-seen time is not a time: string");
		self::call($controller, 'sessionToken', 'aa');
	}

	/** @return array{QuarantineController, Session} */
	private function controller(mixed $stored): array {
		$session = new Session(new MockArraySessionStorage());
		$session->set(self::KEY, $stored);
		$controller = (new ReflectionClass(QuarantineController::class))->newInstanceWithoutConstructor();
		(new ReflectionProperty($controller, 'session'))->setValue($controller, $session);
		return [$controller, $session];
	}

	private static function call(QuarantineController $controller, string $method, mixed ...$args): mixed {
		return (new ReflectionMethod($controller, $method))->invoke($controller, ...$args);
	}
}
