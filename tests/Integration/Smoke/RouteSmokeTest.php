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
 no server error, no PHP warning, no inline event handler, script or style
 attribute, the CSP as expected, no ERROR-level log line, the session kept,
 and no data changed (state-changing routes need a form or CSRF token, which
 a plain request does not carry).
*/

namespace Tests\Integration\Smoke;

use PHPUnit\Framework\Attributes\TestDox;
use Tests\Integration\IntegrationTestCase;
use Tests\Integration\WebClient;

final class RouteSmokeTest extends IntegrationTestCase
{
	// pages that preview a quarantined mail's HTML: their CSP allows inline styles
	private const MAIL_PREVIEW_ROUTES = ['showmail', 'admin_showmail'];

	private const TABLES = [
		'users', 'mail_aliases', 'mail_logs', 'mail_log_recipients', 'mail_log_data',
		'maps_combined', 'maps_custom', 'custom_map_config', 'mail_log_fuzzy',
	];

	#[TestDox('Admin: every admin and user route')]
	public function testAdmin(): void {
		$dataBlocks = $this->smoke(self::client('admin'), [self::ADMIN, self::USER], true);
		// admin pages include charts: the JSON data block check ran
		$this->assertGreaterThan(0, $dataBlocks, 'JSON data blocks checked');
	}

	#[TestDox('Plain user: every user route')]
	public function testUser(): void {
		$this->smoke(self::client('alice'), [self::USER], true);
	}

	#[TestDox('Anonymous: every public route')]
	public function testPublic(): void {
		$this->smoke(self::anonymous(), [self::PUBLIC], false);
	}

	/**
	 * @param list<string> $levels
	 * @return int the JSON data blocks checked
	 */
	private function smoke(WebClient $client, array $levels, bool $loggedIn): int {
		$tables = self::checksums();
		$appLog = self::logSize('logs/rqwatch.log');
		$webLog = self::logSize((string) getenv('INTEGRATION_WEB_LOG'));
		$checked = 0;
		$dataBlocks = 0;

		foreach (self::routes() as $name => $route) {
			if (!in_array(self::level($route), $levels, true)) {
				continue;
			}
			$response = self::call($client, $name, $route);
			$this->assertLessThan(500, $response['status'], "{$name}: HTTP {$response['status']}");
			$this->assertDoesNotMatchRegularExpression(
				'/<b>(Warning|Notice|Deprecated|Fatal error)<\/b>:|PHP (Warning|Notice|Deprecated|Fatal error):/',
				$response['body'], "{$name}: PHP error in the page");
			// the CSP refuses inline handlers and scripts; web/js/rqwatch.js does
			// their work (a JSON data block is not executed, so it is allowed)
			$this->assertDoesNotMatchRegularExpression('/<[a-z][^>]*\son[a-z]+\s*=/i',
				$response['body'], "{$name}: inline event handler");
			$this->assertDoesNotMatchRegularExpression('/<script\b(?![^>]*\bsrc=)(?![^>]*\btype=["\']application\/json["\'])[^>]*>/i',
				$response['body'], "{$name}: inline script");
			// and the policy refuses them: only rqwatch's own script files run
			$csp = $response['headers']['content-security-policy'] ?? '';
			$this->assertMatchesRegularExpression('/(^|;)\s*script-src \'self\'\s*(;|$)/', $csp, "{$name}: CSP script-src");
			// no style attributes either: inline styles are allowed only where a
			// quarantined mail's HTML is previewed (it is styled inline)
			$this->assertDoesNotMatchRegularExpression('/<[a-z][^>]*\sstyle\s*=/i',
				$response['body'], "{$name}: style attribute");
			$styleSrc = in_array($name, self::MAIL_PREVIEW_ROUTES, true) ? "'self' 'unsafe-inline'" : "'self'";
			$this->assertMatchesRegularExpression('/(^|;)\s*style-src ' . preg_quote($styleSrc, '/') . '\s*(;|$)/', $csp, "{$name}: CSP style-src");
			// what rqwatch.js parses, e.g. a chart's config
			preg_match_all('/<script type="application\/json"[^>]*>(.*?)<\/script>/s', $response['body'], $blocks);
			foreach ($blocks[1] as $json) {
				$this->assertIsArray(json_decode($json, true), "{$name}: JSON data block");
				$dataBlocks++;
			}
			if ($loggedIn) {
				$this->assertNotSame('/login', self::target($response), "{$name}: session lost");
			}
			$checked++;
		}

		$this->assertGreaterThan(0, $checked);
		$errors = self::linesSince('logs/rqwatch.log', $appLog, '/\] (ERROR|CRITICAL|ALERT|EMERGENCY): /');
		$this->assertSame([], $errors, 'rqwatch.log errors');
		$this->assertSame([], self::linesSince((string) getenv('INTEGRATION_WEB_LOG'), $webLog, '/PHP (Warning|Notice|Deprecated|Fatal error)/'), 'php -S errors');
		$this->assertSame($tables, self::checksums(), 'data changed by a plain request');
		return $dataBlocks;
	}

	/** @return array<string, string> table => CHECKSUM TABLE result */
	private static function checksums(): array {
		$sums = [];
		foreach (self::db()->query('CHECKSUM TABLE ' . implode(', ', self::TABLES))->fetchAll() as $row) { // @phpstan-ignore method.nonObject (db() sets ERRMODE_EXCEPTION, so query() throws rather than returning false)
			$sums[(string) $row['Table']] = (string) $row['Checksum'];
		}
		return $sums;
	}

	private static function file(string $path): string {
		return str_starts_with($path, '/') ? $path : self::root() . "/{$path}";
	}

	private static function logSize(string $path): int {
		clearstatcache();
		return is_file(self::file($path)) ? (int) filesize(self::file($path)) : 0;
	}

	/**
	 * @param non-empty-string $pattern
	 * @return list<string> lines matching $pattern written after byte $from
	 */
	private static function linesSince(string $path, int $from, string $pattern): array {
		clearstatcache();
		if (!is_file(self::file($path))) {
			return [];
		}
		$new = (string) file_get_contents(self::file($path), false, null, $from);
		return array_values(preg_grep($pattern, explode("\n", $new)) ?: []);
	}
}
