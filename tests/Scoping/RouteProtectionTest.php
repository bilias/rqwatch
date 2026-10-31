<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Every protected route refused over HTTP: anonymous requests go to the login
 page, a plain user is refused every admin route. Positive controls first, so
 a broken login cannot make a refusal pass.
*/

namespace Tests\Scoping;

use PHPUnit\Framework\Attributes\TestDox;

final class RouteProtectionTest extends WebTestCase
{
	#[TestDox('Positive control: the admin reaches /admin, the user reaches /')]
	public function testLoginsWork(): void {
		$this->assertSame(200, self::client('admin')->get('/admin')['status'], 'admin /admin');
		$this->assertSame(200, self::client('alice')->get('/')['status'], 'alice /');
	}

	#[TestDox('Anonymous: every user and admin route redirects to the login page')]
	public function testAnonymousIsSentToLogin(): void {
		$anonymous = self::anonymous();
		$checked = 0;
		foreach (self::routes() as $name => $route) {
			if (self::level($route) === self::PUBLIC) {
				continue;
			}
			$response = self::call($anonymous, $name, $route);
			$this->assertSame(302, $response['status'], "anonymous {$name}");
			$this->assertSame('/login', self::target($response), "anonymous {$name}");
			$checked++;
		}
		$this->assertGreaterThan(0, $checked);
	}

	// admin by declaration or by path: a route declared too open under
	// /admin is refused here too, not only in tests/Access
	#[TestDox('Plain user: every admin route (declared, or under /admin) is refused with "Permission denied"')]
	public function testUserIsRefusedAdminRoutes(): void {
		$alice = self::client('alice');
		$checked = 0;
		foreach (self::routes() as $name => $route) {
			$path = $route->getPath();
			if (self::level($route) !== self::ADMIN && $path !== '/admin' && !str_starts_with($path, '/admin/')) {
				continue;
			}
			$response = self::call($alice, $name, $route);
			$this->assertSame(302, $response['status'], "alice {$name}");
			$this->assertSame('/', self::target($response), "alice {$name}");
			// the refusal's flash shows on the page it redirects to
			$this->assertStringContainsString('Permission denied', $alice->get('/')['body'], "alice {$name}");
			$checked++;
		}
		$this->assertGreaterThan(0, $checked);
	}
}
