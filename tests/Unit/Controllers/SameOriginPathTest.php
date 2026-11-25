<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 LoginController::sameOriginPath(), what getRedirectUrl() lets a login
 return to: a path on this site. Not a URL, not "//host", and no "\" or
 control character, which browsers turn into "/" or drop, so that "/\host"
 and "/<tab>/host" would leave the site.
*/

namespace Tests\Unit\Controllers;

use App\Controllers\LoginController;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\Unit\UnitTestCase;

final class SameOriginPathTest extends UnitTestCase
{
	/** @return array<string, array{string, bool}> */
	public static function paths(): array {
		return [
			'a page' => ['/mail/1', true],
			'a page with a query' => ['/search/results?page=2&q=a%2Fb', true],
			'the root' => ['/', true],
			'empty' => ['', false],
			'relative' => ['mail/1', false],
			'a URL' => ['https://evil.example/', false],
			'protocol-relative' => ['//evil.example/', false],
			'backslash' => ['/\\evil.example/', false],
			'backslash later' => ['/mail/1\\x', false],
			'tab' => ["/\t/evil.example/", false],
			'line feed' => ["/\n/evil.example/", false],
			'carriage return' => ["/mail/1\r\nLocation: x", false],
			'NUL' => ["/mail/1\0", false],
			'DEL' => ["/mail/1\x7F", false],
		];
	}

	#[DataProvider('paths')]
	public function testPaths(string $path, bool $same): void {
		$this->assertSame($same, (new ReflectionMethod(LoginController::class, 'sameOriginPath'))->invoke(null, $path));
	}
}
