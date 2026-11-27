<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The database connection and the boot's schema check. boot() sets up a
 MySQL connection from .env (nothing connects yet), with the session
 clock PHP's, global and with Eloquent; for web pages each statement is
 capped at DB_WEB_MAX_STATEMENT_TIME seconds, 30 when unset, none at 0.
 verifySchema(), on RecordingDatabase, reads DB_NAME's columns from
 information_schema afresh each time: mail_logs is required; without a
 migrations table nothing more is checked and the status is told so;
 with one, the tables and columns of each REQUIRED migration recorded
 completed are required, and only those.
*/

namespace Tests\Unit\Core;

use App\Core\Database\Database;
use App\Core\Database\MigrationStatus;
use App\Inventory\Migrations;
use App\Models\MailLogData;
use App\Models\MailLogRecipient;
use App\Models\MailLogToken;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use ReflectionProperty;
use RuntimeException;
use Tests\Unit\RecordingDatabase;
use Tests\Unit\UnitTestCase;

final class DatabaseTest extends UnitTestCase
{
	private const string COLUMNS = "SELECT TABLE_NAME, COLUMN_NAME\n\t\t\t          FROM information_schema.COLUMNS\n\t\t\t          WHERE TABLE_SCHEMA = ?\n\t\t\t          ORDER BY TABLE_NAME, ORDINAL_POSITION";

	/** @var array<string, list<string>> table => columns */
	private array $schema = [];

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->setEnv('DB_NAME', 'rqwatch');
		$this->schema = [
			'mail_logs' => ['id', 'qid', 'created_day'],
			'migrations' => ['migration', 'status'],
			'mail_log_recipients' => MailLogRecipient::COLUMNS,
			'mail_log_data' => MailLogData::COLUMNS,
			'mail_log_tokens' => MailLogToken::COLUMNS,
		];
	}

	#[\Override]
	protected function tearDown(): void {
		Model::unsetConnectionResolver();
		RecordingDatabase::forget();
		parent::tearDown();
	}

	private function database(): RecordingDatabase {
		$db = new RecordingDatabase($this->createStub(PDO::class));
		$db->answer = function (string $sql, array $bindings): array {
			$rows = [];
			foreach ($this->schema as $table => $columns) {
				foreach ($columns as $column) {
					$rows[] = ['TABLE_NAME' => $table, 'COLUMN_NAME' => $column];
				}
			}
			return $rows;
		};
		return $db;
	}

	/** @param list<string> $completed */
	private function completed(array $completed): MigrationStatus {
		$status = new MigrationStatus(null, $this->log);
		foreach ($completed as $migration) {
			$status->setMigrationState($migration, Migrations::STATUS_COMPLETED);
		}
		return $status;
	}

	// the connection's settings (made, not connected), without the name Laravel adds
	/** @return array<array-key, mixed> */
	private static function connection(Capsule $capsule): array {
		/** @var mixed $config */
		$config = $capsule->getConnection()->getConfig();
		$settings = is_array($config) ? $config : [];
		unset($settings['name']);
		return $settings;
	}

	private function env(): void {
		foreach (['DB_HOST' => 'db.example.org', 'DB_USER' => 'rq', 'DB_PASS' => 'secret', 'DB_PORT' => '3307'] as $key => $value) {
			$this->setEnv($key, $value);
		}
	}

	public function testBoot(): void {
		$this->env();
		$this->setEnv('DB_WEB_MAX_STATEMENT_TIME', '5');
		$capsule = Database::boot();
		$this->assertSame([
			'host' => 'db.example.org',
			'username' => 'rq',
			'password' => 'secret',
			'database' => 'rqwatch',
			'port' => '3307',
			'charset' => 'utf8mb4',
			'collation' => 'utf8mb4_general_ci',
			'timezone' => date('P'),
			'driver' => 'mysql',
			'fetch' => 'FETCH_ASSOC',
			// Laravel's default
			'prefix' => '',
		], self::connection($capsule));
		$this->assertSame($capsule, (new ReflectionProperty(Capsule::class, 'instance'))->getValue());
		$this->assertSame($capsule->getDatabaseManager(), (new ReflectionProperty(Model::class, 'resolver'))->getValue());
	}

	/** @return array<string, array{string|null, int|null}> DB_WEB_MAX_STATEMENT_TIME, the cap (null: none) */
	public static function webCaps(): array {
		return [
			'unset' => [null, 30],
			'set' => ['5', 5],
			'padded' => ['05', 5],
			'zero' => ['0', null],
		];
	}

	#[DataProvider('webCaps')]
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testWebCap(?string $setting, ?int $cap): void {
		define('WEB_MODE', true);
		$this->env();
		$this->setEnv('DB_WEB_MAX_STATEMENT_TIME', $setting);
		$config = self::connection(Database::boot());
		/** @psalm-suppress DeprecatedConstant the PDO:: branch runs on PHP 8.3 only */
		$init = PHP_VERSION_ID >= 80400 ? \Pdo\Mysql::ATTR_INIT_COMMAND : PDO::MYSQL_ATTR_INIT_COMMAND;
		$this->assertSame($cap === null ? null : [$init => "SET SESSION max_statement_time = {$cap}"], $config['options'] ?? null);
	}

	public function testAllRequiredCompleted(): void {
		$db = $this->database();
		$status = $this->completed(Migrations::REQUIRED);
		Database::verifySchema($db->capsule, $status);
		Database::verifySchema($db->capsule, $status);
		$this->assertSame([[self::COLUMNS, ['rqwatch']], [self::COLUMNS, ['rqwatch']]], $db->selects);
		$this->assertTrue($status->hasMigrationsTable());
	}

	public function testNoMailLogs(): void {
		unset($this->schema['mail_logs']);
		$this->expectExceptionObject(new RuntimeException("Required table 'mail_logs' is missing."));
		Database::verifySchema($this->database()->capsule, $this->completed([]));
	}

	// a new install before db:migrate: only mail_logs
	public function testNoMigrationsTable(): void {
		$this->schema = ['mail_logs' => ['id']];
		$status = $this->completed(Migrations::REQUIRED);
		$status->setMigrationTableExists(true);
		Database::verifySchema($this->database()->capsule, $status);
		$this->assertFalse($status->hasMigrationsTable());
	}

	/** @return array<string, array{string, string, string}> migration, table, a column of it, or '' for the whole table */
	public static function checks(): array {
		return [
			'recipients table' => [Migrations::MAIL_RECIPIENTS, 'mail_log_recipients', ''],
			'recipients column' => [Migrations::MAIL_RECIPIENTS, 'mail_log_recipients', 'recipient_email'],
			'data table' => [Migrations::MAIL_LOG_DATA, 'mail_log_data', ''],
			'data column' => [Migrations::MAIL_LOG_DATA, 'mail_log_data', 'fuzzy_hashes'],
			'created_day' => [Migrations::CREATED_DAY, 'mail_logs', 'created_day'],
			'tokens table' => [Migrations::MAIL_LOG_TOKENS, 'mail_log_tokens', ''],
			'tokens column' => [Migrations::MAIL_LOG_TOKENS, 'mail_log_tokens', 'recipient_email'],
		];
	}

	#[DataProvider('checks')]
	public function testRequiredOnceCompleted(string $migration, string $table, string $column): void {
		if ($column === '') {
			unset($this->schema[$table]);
		} else {
			$this->schema[$table] = array_values(array_diff($this->schema[$table], [$column]));
		}
		$others = array_values(array_diff(Migrations::REQUIRED, [$migration]));
		Database::verifySchema($this->database()->capsule, $this->completed($others));
		$this->expectExceptionObject(new RuntimeException($column === ''
			? "Required table '{$table}' is missing."
			: "Required column '{$table}.{$column}' is missing."));
		Database::verifySchema($this->database()->capsule, $this->completed(Migrations::REQUIRED));
	}
}
