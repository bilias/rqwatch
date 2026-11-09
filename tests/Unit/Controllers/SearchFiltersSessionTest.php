<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 MailLogController::getFiltersFromSession(): the search filters
 saveFiltersToSession() stored, each a filter, choice and value as text.
 Only rqwatch writes them, so anything else fails naming the session key,
 where it used to read as no filter at all.
*/

namespace Tests\Unit\Controllers;

use App\Controllers\Controller;
use App\Controllers\MailLogController;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Tests\Unit\UnitTestCase;

final class SearchFiltersSessionTest extends UnitTestCase
{
	public function testStored(): void {
		$filters = [
			['filter' => 'Date', 'choice' => 'is equal to', 'value' => '2026-11-09'],
			['filter' => 'Subject', 'choice' => 'contains', 'value' => 'invoice'],
		];
		$this->assertSame($filters, self::read(json_encode($filters, JSON_THROW_ON_ERROR)));
	}

	public function testNone(): void {
		$this->assertSame([], self::read(null));
		$this->assertSame([], self::read(''));
	}

	/** @return array<string, array{string, string}> stored JSON, message */
	public static function damaged(): array {
		return [
			'not a list' => ['"Date"', "Session value 'filters' is not a list of search filters"],
			'not JSON' => ['{broken', "Session value 'filters' is not a list of search filters"],
			'a number value' => ['[{"filter":"Score","choice":"is greater than","value":5}]', "Session value 'filters' holds a filter that is not"],
			'a missing key' => ['[{"filter":"Date","value":"2026-11-09"}]', "Session value 'filters' holds a filter that is not"],
			'not a filter' => ['["Date"]', "Session value 'filters' holds a filter that is not"],
		];
	}

	#[DataProvider('damaged')]
	public function testDamagedFails(string $stored, string $message): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage($message);
		self::read($stored);
	}

	private static function read(?string $stored): mixed {
		$session = new Session(new MockArraySessionStorage());
		if ($stored !== null) {
			$session->set('filters', $stored);
		}
		$controller = (new ReflectionClass(MailLogController::class))->newInstanceWithoutConstructor();
		(new ReflectionProperty(Controller::class, 'session'))->setValue($controller, $session);
		return (new ReflectionMethod($controller, 'getFiltersFromSession'))->invoke($controller);
	}
}
