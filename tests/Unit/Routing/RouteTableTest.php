<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The route table: every RouteName is registered and nothing else, every
 _controller is a public method that exists, every path placeholder has a
 requirement, and the HTTP methods and extra defaults are exactly the
 reviewed lists. A failure is the prompt to review that route.
*/

namespace Tests\Unit\Routing;

use App\Core\Routing\RouteName;
use App\Core\Routing\Routes;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\Routing\Route;

final class RouteTableTest extends TestCase
{
	// placeholders checked by the controller instead (Helper::check_id_qid())
	private const array UNCONSTRAINED = [
		'detail' => ['value'],
		'admin_detail' => ['value'],
	];

	/** @return array<string, Route> */
	private function routes(): array {
		return Routes::load()['routes']->all();
	}

	public function testEveryRouteNameRegistered(): void {
		$names = array_map(fn (RouteName $name): string => $name->value, RouteName::cases());
		sort($names);
		$registered = array_keys($this->routes());
		sort($registered);
		$this->assertSame($names, $registered);
	}

	public function testEveryControllerExists(): void {
		foreach ($this->routes() as $name => $route) {
			/** @var mixed $controller */
			$controller = $route->getDefault('_controller');
			$this->assertIsString($controller, "Route '$name' has no _controller");
			$this->assertMatchesRegularExpression('/^App\\\\Controllers\\\\\w+::\w+$/D', $controller, "Route '$name'");
			[$class, $method] = explode('::', $controller);
			$this->assertTrue(class_exists($class), "Route '$name': no class $class");
			$this->assertTrue(method_exists($class, $method), "Route '$name': no method $controller");
			$this->assertTrue((new ReflectionMethod($class, $method))->isPublic(), "Route '$name': $controller is not public");
		}
	}

	public function testEveryPlaceholderHasARequirement(): void {
		foreach ($this->routes() as $name => $route) {
			$placeholders = $route->compile()->getPathVariables();
			$constrained = array_values(array_diff($placeholders, self::UNCONSTRAINED[$name] ?? []));
			$requirements = array_keys($route->getRequirements());
			sort($constrained);
			sort($requirements);
			$this->assertSame($constrained, $requirements, "Route '$name' requirements differ from its placeholders");
		}
	}

	public function testMethodsAreTheReviewedList(): void {
		$expected = [
			RouteName::TOKEN_LINK->value => ['GET'],
			RouteName::TOKEN_REDEEM->value => ['POST'],
			RouteName::TOKEN_VIEW->value => ['POST'],
			RouteName::TOKEN_RELEASE->value => ['POST'],
			RouteName::ADMIN_USERSEARCH->value => ['GET', 'POST'],
			RouteName::ADMIN_ALIASES_SEARCH->value => ['GET', 'POST'],
			RouteName::ADMIN_FUZZY_SEARCH->value => ['GET', 'POST'],
			RouteName::ADMIN_MAP_SEARCH_ENTRY->value => ['GET', 'POST'],
		];
		foreach ($this->routes() as $name => $route) {
			$this->assertSame($expected[$name] ?? [], $route->getMethods(), "Route '$name' methods");
		}
	}

	public function testExtraDefaultsAreTheReviewedList(): void {
		$expected = [
			RouteName::REPORTS->value => ['mode' => 'count'],
			RouteName::ADMIN_REPORTS->value => ['mode' => 'count'],
			RouteName::DAY_LOGS->value => ['date' => null],
			RouteName::ADMIN_DAY_LOGS->value => ['date' => null],
			RouteName::SEARCH_FILTER_DEL->value => ['filter_id' => null],
			RouteName::ADMIN_SEARCH_FILTER_DEL->value => ['filter_id' => null],
			RouteName::ADMIN_MAP_SHOW_ALL->value => ['model' => null],
		];
		foreach ($this->routes() as $name => $route) {
			$defaults = array_diff_key($route->getDefaults(), ['_controller' => true, '_middleware' => true]);
			$this->assertSame($expected[$name] ?? [], $defaults, "Route '$name' defaults");
		}
	}
}
