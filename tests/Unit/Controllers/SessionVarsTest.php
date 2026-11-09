<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Controller::setSessionVars() copies the login's session values into the
 controller. A value of another type fails with the key named, rather than
 a TypeError on the property or, worse, passing as "not an admin".
*/

namespace Tests\Unit\Controllers;

use App\Controllers\Controller;
use App\Controllers\MapController;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Tests\Unit\UnitTestCase;

final class SessionVarsTest extends UnitTestCase
{
	public function testLoginValues(): void {
		$controller = $this->controller(['is_admin' => true, 'username' => 'alice', 'user_id' => 7,
			'email' => 'alice@example.org', 'user_aliases' => ['a@example.org']]);
		$this->assertSame(true, self::read($controller, 'is_admin'));
		$this->assertSame('alice', self::read($controller, 'username'));
		$this->assertSame(7, self::read($controller, 'user_id'));
		$this->assertSame('alice@example.org', self::read($controller, 'email'));
		$this->assertSame(['a@example.org'], self::read($controller, 'user_aliases'));
	}

	// no login: the defaults stay
	public function testEmptySession(): void {
		$controller = $this->controller([]);
		$this->assertSame(false, self::read($controller, 'is_admin'));
		$this->assertNull(self::read($controller, 'username'));
		$this->assertSame([], self::read($controller, 'user_aliases'));
	}

	/** @return array<string, array{string, mixed}> */
	public static function wrongTypeCases(): array {
		return [
			'is_admin' => ['is_admin', 'yes'],
			'username' => ['username', ['alice']],
			'user_id' => ['user_id', '7'],
			'email' => ['email', 5],
			'user_aliases' => ['user_aliases', 'a@example.org'],
		];
	}

	#[DataProvider('wrongTypeCases')]
	public function testWrongTypeFails(string $key, mixed $stored): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("Session value '{$key}' must be");
		$this->controller([$key => $stored]);
	}

	/** @param array<string, mixed> $values */
	private function controller(array $values): Controller {
		$session = new Session(new MockArraySessionStorage());
		/** @var mixed $value */
		foreach ($values as $key => $value) {
			$session->set($key, $value);
		}
		$controller = (new ReflectionClass(MapController::class))->newInstanceWithoutConstructor();
		$controller->setSessionVars($session);
		return $controller;
	}

	private static function read(Controller $controller, string $property): mixed {
		return (new ReflectionProperty(Controller::class, $property))->getValue($controller);
	}
}
