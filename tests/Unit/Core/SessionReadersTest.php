<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 SessionManager's typed session reads: the value rqwatch stored, null (or
 [] for a list) when unset, and for a value of another type an exception
 naming the key, never a silent default.
*/

namespace Tests\Unit\Core;

use App\Core\SessionManager;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Tests\Unit\UnitTestCase;

final class SessionReadersTest extends UnitTestCase
{
	public function testStoredValues(): void {
		$session = self::session(['s' => 'alice', 'i' => 7, 'b' => false, 'l' => ['a@x.org', 'b@x.org']]);
		$this->assertSame('alice', SessionManager::getString($session, 's'));
		$this->assertSame(7, SessionManager::getInt($session, 'i'));
		$this->assertFalse(SessionManager::getBool($session, 'b'));
		$this->assertSame(['a@x.org', 'b@x.org'], SessionManager::getStringList($session, 'l'));
	}

	public function testUnset(): void {
		$session = self::session([]);
		$this->assertNull(SessionManager::getString($session, 'k'));
		$this->assertNull(SessionManager::getInt($session, 'k'));
		$this->assertNull(SessionManager::getBool($session, 'k'));
		$this->assertSame([], SessionManager::getStringList($session, 'k'));
	}

	// renumbered: loginAs() stores array_unique()'s keys
	public function testStringListRenumbered(): void {
		$session = self::session(['l' => [3 => 'a@x.org', 9 => 'b@x.org']]);
		$this->assertSame(['a@x.org', 'b@x.org'], SessionManager::getStringList($session, 'l'));
	}

	/** @return array<string, array{string, mixed, string}> reader, stored value, message */
	public static function wrongTypeCases(): array {
		return [
			'string' => ['getString', ['alice'], "Session value 'k' must be a string, not array"],
			'int' => ['getInt', '7', "Session value 'k' must be an integer, not string"],
			'bool' => ['getBool', 1, "Session value 'k' must be true or false, not int"],
			'list' => ['getStringList', 'a@x.org', "Session value 'k' must be a list of strings, not string"],
			'list item' => ['getStringList', ['a@x.org', 5], "Session value 'k' must be a list of strings, not int"],
		];
	}

	#[DataProvider('wrongTypeCases')]
	public function testWrongTypeFails(string $reader, mixed $stored, string $message): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage($message);
		SessionManager::{$reader}(self::session(['k' => $stored]), 'k');
	}

	/** @param array<string, mixed> $values */
	private static function session(array $values): Session {
		$session = new Session(new MockArraySessionStorage());
		/** @var mixed $value */
		foreach ($values as $key => $value) {
			$session->set($key, $value);
		}
		return $session;
	}
}
