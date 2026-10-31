<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Every route requested once per role it is open to, with sample parameters:
 no server error, no PHP warning, no ERROR-level log line, the session kept,
 and no data changed (state-changing routes need a form or CSRF token, which
 a plain request does not carry).
*/

namespace Tests\Scoping;

use PHPUnit\Framework\Attributes\TestDox;

final class RouteSmokeTest extends WebTestCase
{
	// the harness runs no rspamd: admin pages that show its stats log this
	private const EXPECTED_ERRORS = '/\] ERROR: Stat request to \'\w+\' failed: /';

	private const TABLES = [
		'users', 'mail_aliases', 'mail_logs', 'mail_log_recipients', 'mail_log_data',
		'maps_combined', 'maps_custom', 'custom_map_config', 'mail_log_fuzzy',
	];

	#[TestDox('Admin: every admin and user route')]
	public function testAdmin(): void {
		$this->smoke(self::client('admin'), [self::ADMIN, self::USER], true);
	}

	#[TestDox('Plain user: every user route')]
	public function testUser(): void {
		$this->smoke(self::client('alice'), [self::USER], true);
	}

	#[TestDox('Anonymous: every public route')]
	public function testPublic(): void {
		$this->smoke(self::anonymous(), [self::PUBLIC], false);
	}

	/** @param list<string> $levels */
	private function smoke(WebClient $client, array $levels, bool $loggedIn): void {
		$tables = self::checksums();
		$appLog = self::logSize('logs/rqwatch.log');
		$webLog = self::logSize((string) getenv('SCOPING_WEB_LOG'));
		$checked = 0;

		foreach (self::routes() as $name => $route) {
			if (!in_array(self::level($route), $levels, true)) {
				continue;
			}
			$response = self::call($client, $name, $route);
			$this->assertLessThan(500, $response['status'], "{$name}: HTTP {$response['status']}");
			$this->assertDoesNotMatchRegularExpression(
				'/<b>(Warning|Notice|Deprecated|Fatal error)<\/b>:|PHP (Warning|Notice|Deprecated|Fatal error):/',
				$response['body'], "{$name}: PHP error in the page");
			if ($loggedIn) {
				$this->assertNotSame('/login', self::target($response), "{$name}: session lost");
			}
			$checked++;
		}

		$this->assertGreaterThan(0, $checked);
		$errors = self::linesSince('logs/rqwatch.log', $appLog, '/\] (ERROR|CRITICAL|ALERT|EMERGENCY): /');
		$this->assertSame([], array_values(preg_grep(self::EXPECTED_ERRORS, $errors, PREG_GREP_INVERT) ?: []), 'rqwatch.log errors');
		$this->assertSame([], self::linesSince((string) getenv('SCOPING_WEB_LOG'), $webLog, '/PHP (Warning|Notice|Deprecated|Fatal error)/'), 'php -S errors');
		$this->assertSame($tables, self::checksums(), 'data changed by a plain request');
	}

	/** @return array<string, string> table => CHECKSUM TABLE result */
	private static function checksums(): array {
		$sums = [];
		foreach (self::db()->query('CHECKSUM TABLE ' . implode(', ', self::TABLES))->fetchAll() as $row) {
			$sums[(string) $row['Table']] = (string) $row['Checksum'];
		}
		return $sums;
	}

	private static function file(string $path): string {
		return str_starts_with($path, '/') ? $path : getenv('SCOPING_ROOT') . "/{$path}";
	}

	private static function logSize(string $path): int {
		clearstatcache();
		return is_file(self::file($path)) ? (int) filesize(self::file($path)) : 0;
	}

	/** @return list<string> lines matching $pattern written after byte $from */
	private static function linesSince(string $path, int $from, string $pattern): array {
		clearstatcache();
		if (!is_file(self::file($path))) {
			return [];
		}
		$new = (string) file_get_contents(self::file($path), false, null, $from);
		return array_values(preg_grep($pattern, explode("\n", $new)) ?: []);
	}
}
