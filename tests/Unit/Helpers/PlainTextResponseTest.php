<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 An error response: the body as given, as UTF-8 text/plain that a browser
 must not sniff into HTML, with the status given.
*/

namespace Tests\Unit\Helpers;

use App\Utils\Helper;
use Tests\Unit\UnitTestCase;

final class PlainTextResponseTest extends UnitTestCase
{
	public function testPlainText(): void {
		$response = Helper::plainTextResponse('<b>Permission denied</b>', 403);
		$this->assertSame('<b>Permission denied</b>', $response->getContent());
		$this->assertSame(403, $response->getStatusCode());
		$this->assertSame('text/plain; charset=utf-8', $response->headers->get('Content-Type'));
		$this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
	}
}
