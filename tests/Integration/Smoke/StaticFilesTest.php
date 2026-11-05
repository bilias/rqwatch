<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The harness serves web/'s static files as they are, like Apache in
 production (qa/php-router.php); before, php -S answered them with the
 homepage. A missing file is a 404 where web/.htaccess doesn't rewrite
 (css/, images/) and reaches rqwatch elsewhere (no session: the login).
*/

namespace Tests\Integration\Smoke;

use PHPUnit\Framework\Attributes\TestDox;
use Tests\Integration\IntegrationTestCase;

final class StaticFilesTest extends IntegrationTestCase
{
	#[TestDox('css, js and images are served as files; a missing one is a 404 or goes to rqwatch, as web/.htaccess has it')]
	public function testStaticFiles(): void {
		$client = self::anonymous();
		$files = [
			'/css/style.css' => 'text/css',
			'/js/rqwatch.js' => 'javascript',
			'/js/chart.umd.min.js' => 'javascript',
			'/images/favicon.ico' => 'image/',
		];
		foreach ($files as $path => $type) {
			$response = $client->get($path);
			$this->assertSame(200, $response['status'], $path);
			$this->assertStringContainsString($type, $response['headers']['content-type'] ?? '', "{$path}: content type");
			$this->assertSame((string) file_get_contents(self::root() . '/web' . $path), $response['body'], "{$path}: the file itself");
		}

		// web/.htaccess doesn't rewrite css/: a missing file there is the web server's 404
		$missing = $client->get('/css/missing.css');
		$this->assertSame(404, $missing['status'], 'a missing file under css/: 404');
		// js/ is rewritten: a missing file there reaches rqwatch
		$missing = $client->get('/js/missing.js');
		$this->assertSame('/login', self::target($missing), 'a missing file under js/ reaches rqwatch');
	}
}
