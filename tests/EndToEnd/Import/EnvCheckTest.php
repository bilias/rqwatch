<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 A failing .env key does not stop the metadata importer: rspamd posts once,
 so a refusal would lose the mail's metadata. The failure is logged and the
 mail imported. (Every other entry point refuses: Integration.)
*/

namespace Tests\EndToEnd\Import;

use PHPUnit\Framework\Attributes\TestDox;
use Tests\EndToEnd\EndToEndTestCase;

final class EnvCheckTest extends EndToEndTestCase
{
	#[TestDox('A mistyped boolean in .env: the importer logs it and still imports the mail')]
	public function testImporterLogsAndImports(): void {
		$root = (string) getenv('E2E_ROOT');
		$env = "{$root}/.env";
		$log = "{$root}/logs/rqwatch.log";
		$original = (string) file_get_contents($env);
		clearstatcache();
		$before = is_file($log) ? (int) filesize($log) : 0;

		$changed = (string) preg_replace('/^DEBUG_SEARCH_SQL=.*$/m', 'DEBUG_SEARCH_SQL=maybe', $original, -1, $count);
		$this->assertSame(1, $count, 'fixture: one DEBUG_SEARCH_SQL line');
		file_put_contents($env, $changed);
		try {
			// import() checks the stored row against rspamd's verdict
			$this->import('clean');
		} finally {
			file_put_contents($env, $original);
		}

		clearstatcache();
		$this->assertStringContainsString('.env: DEBUG_SEARCH_SQL is not a boolean', (string) file_get_contents($log, false, null, $before));
	}
}
