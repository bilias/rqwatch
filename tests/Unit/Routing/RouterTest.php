<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Router dispatch: a route's _middleware is used only as a list of class
 names (or ['NO_MIDDLEWARE']), anything else is refused; a controller
 that returns no Response gets a 500, on either path.
*/

namespace Tests\Unit\Routing;

use App\Core\Middleware\AuthMiddleware;
use App\Core\Middleware\Authorization;
use App\Core\Routing\Router;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\Response;

final class RouterTest extends TestCase
{
	public function testClassListKept(): void {
		$this->assertSame([AuthMiddleware::class, Authorization::class],
			self::call('classList', [AuthMiddleware::class, Authorization::class]));
		$this->assertSame(['NO_MIDDLEWARE'], self::call('classList', ['NO_MIDDLEWARE']));
	}

	/** @return array<string, array{mixed}> */
	public static function notClassLists(): array {
		return [
			'string' => [AuthMiddleware::class],
			'not a list' => [['auth' => AuthMiddleware::class]],
			'int element' => [[AuthMiddleware::class, 1]],
			'nested' => [[[AuthMiddleware::class]]],
		];
	}

	#[DataProvider('notClassLists')]
	public function testOtherDeclarationsRefused(mixed $declared): void {
		$this->assertNull(self::call('classList', $declared));
	}

	public function testControllerResponseKept(): void {
		$response = new Response('controller');
		$this->assertSame($response, self::call('controllerResponse', $response));
	}

	public function testNoResponseIs500(): void {
		$response = self::call('controllerResponse', 'not a response');
		$this->assertInstanceOf(Response::class, $response);
		$this->assertSame(500, $response->getStatusCode());
	}

	private static function call(string $method, mixed $argument): mixed {
		return (new ReflectionMethod(Router::class, $method))->invoke(null, $argument);
	}
}
