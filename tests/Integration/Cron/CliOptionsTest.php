<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 A console command given a whole-number option that is not one refuses it
 before doing anything, naming the option, instead of running with its
 leading digits ((int) '5x' is 5) or with 0.
*/

namespace Tests\Integration\Cron;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use RuntimeException;
use Tests\Integration\IntegrationTestCase;

final class CliOptionsTest extends IntegrationTestCase
{
	/** @return array<string, array{list<string>, string}> */
	public static function malformed(): array {
		return [
			'a batch with trailing text' => [['cron:import_spool', '-s', '-b', '5x'], "Option --batch must be a whole number, not '5x'"],
			'a sleep that is text' => [['db:migrate_mail_log_viruses', '-s', 'x'], "Option --sleep must be a whole number, not 'x'"],
		];
	}

	/** @param list<string> $command */
	#[DataProvider('malformed')]
	#[TestDox('$_dataName is refused')]
	public function testMalformedNumberRefused(array $command, string $message): void {
		try {
			self::cli(...$command);
			$this->fail('accepted: ' . implode(' ', $command));
		} catch (RuntimeException $e) {
			$this->assertStringContainsString($message, $e->getMessage());
		}
	}
}
