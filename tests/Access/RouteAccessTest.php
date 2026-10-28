<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Route-level access policy. Every route must be public (listed in
 AppConfig::PUBLIC_ROUTES), user (login required) or admin (login + admin).
 Adding a route, or changing its access, means updating the lists here:
 the failure is the prompt to review that decision.
*/

namespace Tests\Access;

use App\Configuration\AppConfig;
use App\Core\Middleware\AuthMiddleware;
use App\Core\Middleware\Authorization;
use App\Core\Routing\Routes;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Route;

final class RouteAccessTest extends TestCase
{
	private const ADMIN = [AuthMiddleware::class, Authorization::class];
	private const USER = [AuthMiddleware::class];
	private const PUBLIC = ['NO_MIDDLEWARE'];

	// Routes any logged-in user may reach. Their controllers must scope data
	// to the user themselves (several share controllers with admin routes).
	private const USER_ROUTES = [
		'logout', 'homepage', 'profile',
		'search_results', 'reports', 'day_logs', 'detail',
		'quarantine', 'quarantine_per_month', 'quarantine_day', 'quarantine_month',
		'search', 'search_filter_del', 'search_filter_add_day',
		'search_filter_add_week', 'search_filter_add_month',
		'showmail', 'attachsave', 'attachopen', 'releasemail',
		'maps', 'map_hits_mails', 'map_show_all', 'map_show',
		'map_add_entry', 'map_edit_entry', 'map_del_all_entries',
		'map_del_entry', 'map_toggle_entry',
	];

	/** @return array<string, Route> */
	private function routes(): array {
		return Routes::load()['routes']->all();
	}

	public function testDefaultMiddlewareRequiresAdmin(): void {
		$this->assertSame(self::ADMIN, Routes::load()['defaultMiddlewareClasses']);
	}

	public function testEveryRouteDeclaresMiddleware(): void {
		foreach ($this->routes() as $name => $route) {
			$this->assertTrue($route->hasDefault('_middleware'), "Route '$name' has no _middleware");
		}
	}

	public function testPublicRoutesMatchAllowlist(): void {
		$public = [];
		foreach ($this->routes() as $name => $route) {
			if ($route->getDefault('_middleware') === self::PUBLIC) {
				$public[] = $name;
			}
		}
		sort($public);
		$allowed = AppConfig::PUBLIC_ROUTES;
		sort($allowed);
		$this->assertSame($allowed, $public, 'NO_MIDDLEWARE routes differ from AppConfig::PUBLIC_ROUTES');
	}

	public function testAdminPathsRequireAdmin(): void {
		foreach ($this->routes() as $name => $route) {
			$path = $route->getPath();
			if ($path === '/admin' || str_starts_with($path, '/admin/')) {
				$this->assertSame(self::ADMIN, $route->getDefault('_middleware'), "Admin path '$path' ($name) is not admin-only");
			}
		}
	}

	public function testUserRoutesAreExactlyTheReviewedList(): void {
		$user = [];
		foreach ($this->routes() as $name => $route) {
			if ($route->getDefault('_middleware') === self::USER) {
				$user[] = $name;
			}
		}
		sort($user);
		$expected = self::USER_ROUTES;
		sort($expected);
		$this->assertSame($expected, $user, 'User-level routes changed: review access and update USER_ROUTES');
	}

	public function testEveryRouteHasAKnownAccessLevel(): void {
		foreach ($this->routes() as $name => $route) {
			$this->assertContains(
				$route->getDefault('_middleware'),
				[self::ADMIN, self::USER, self::PUBLIC],
				"Route '$name' has an unknown middleware combination"
			);
		}
	}
}
