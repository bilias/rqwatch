<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Kernel::boot() refusing to run on a database it cannot use: unreachable,
 a completed migration's table missing, or a required migration not
 completed. The web pages and the mail APIs answer a generic 503 (with
 Redis too: only the metadata importer boots without a database, see
 EndToEnd's SpoolTest), the reason goes to rqwatch.log, and the CLI exits
 non-zero. A pending migration still lets the CLI list its commands and
 reach db:migrate, which is how it gets completed. Each test puts the
 database or .env back.
*/

namespace Tests\Integration\Access;

use App\Inventory\Migrations;
use PHPUnit\Framework\Attributes\TestDox;
use RuntimeException;
use Tests\Integration\IntegrationTestCase;

final class BootTest extends IntegrationTestCase
{
	private int $logStart = 0;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		clearstatcache();
		$log = self::log();
		$this->logStart = is_file($log) ? (int) filesize($log) : 0;
	}

	private static function log(): string {
		return self::root() . '/logs/rqwatch.log';
	}

	private function assertLoggedSince(string $line): void {
		clearstatcache();
		$this->assertStringContainsString($line, (string) file_get_contents(self::log(), false, null, $this->logStart), 'rqwatch.log');
	}

	// the login page and get_mail.php both answer $status with exactly $body
	private function assertWebAndApi(int $status, string $body): void {
		$page = self::anonymous()->get('/login');
		$this->assertSame([$status, $body], [$page['status'], $page['body']], 'web');
		[$apiStatus, $apiBody] = self::mailApi('get_mail.php', ['id' => 101]);
		$this->assertSame([$status, $body], [$apiStatus, $apiBody], 'mail API');
	}

	private function assertCliRefused(string $message, string ...$args): void {
		try {
			self::cli(...$args);
			$this->fail('bin/cli.php ' . implode(' ', $args) . ' booted');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString($message, $e->getMessage(), 'CLI');
		}
	}

	#[TestDox('Database unreachable: web and mail API 503, the CLI refused, the reason logged')]
	public function testDatabaseUnreachable(): void {
		// nothing listens on port 1: the connection is refused at once
		self::withEnv(['DB_PORT' => '1'], function (): void {
			$this->assertWebAndApi(503, 'Database connection problem!');
			$this->assertCliRefused('Database connection problem!', 'list');
			$this->assertLoggedSince('Database connection problem: ');
		});
		$this->assertSame(200, self::anonymous()->get('/login')['status'], 'database back');
	}

	#[TestDox('A completed migration\'s table missing: web and mail API 503, the CLI refused, the table logged')]
	public function testSchemaProblem(): void {
		self::db()->exec('RENAME TABLE mail_log_tokens TO boottest_mail_log_tokens');
		try {
			$this->assertWebAndApi(503, 'Database schema problem!');
			$this->assertCliRefused('Database schema problem!', 'list');
			$this->assertLoggedSince("Database schema verification failed: Required table 'mail_log_tokens' is missing.");
		} finally {
			self::db()->exec('RENAME TABLE boottest_mail_log_tokens TO mail_log_tokens');
		}
		$this->assertSame(200, self::anonymous()->get('/login')['status'], 'table back');
	}

	#[TestDox('A required migration pending: web and mail API 503, the CLI lists and reaches db:migrate but runs nothing else')]
	public function testMigrationPending(): void {
		$migration = Migrations::IP_CREATED_DAY_INDEX;
		$set = self::db()->prepare('UPDATE migrations SET status = ? WHERE migration = ?');
		$set->execute([Migrations::STATUS_PENDING, $migration]);
		$this->assertSame(1, $set->rowCount(), "{$migration} recorded");
		try {
			$this->assertWebAndApi(503, 'Database migration pending. See logs.');
			$this->assertStringContainsString('db:migrate', self::cli('list'), 'the command list');
			$this->assertStringContainsString('db:migrate', self::cli('db:migrate', '--help'), 'db:migrate');
			$this->assertCliRefused('Database migration pending. See logs.', 'cron:updatemapfiles', '--help');
			$this->assertLoggedSince("Required database migration missing: Required migration '{$migration}' is not completed. See: "
				. Migrations::MIGRATION_HELP[$migration]);
		} finally {
			$set->execute([Migrations::STATUS_COMPLETED, $migration]);
		}
		$this->assertSame(200, self::anonymous()->get('/login')['status'], 'migration completed again');
	}
}
