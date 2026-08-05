<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Core\Database;

use Illuminate\Database\Capsule\Manager as Capsule;

use App\Configuration\AppConfig;

use RuntimeException;

class Database {

	private static array $schema = [];
	private static bool $schemaLoaded = false;

	public static function boot(): Capsule {
		$db_config = Array (
			'host'     => $_ENV['DB_HOST'],
			'username' => $_ENV['DB_USER'],
			'password' => $_ENV['DB_PASS'],
			'database' => $_ENV['DB_NAME'],
			'port'     => $_ENV['DB_PORT'],
			'charset'  => 'utf8mb4',
			'collation'=> 'utf8mb4_general_ci',
			'driver'   => 'mysql',
			'fetch'    => 'FETCH_ASSOC',
		//	'prefix'   => 'my_',
		);

		$capsule = new Capsule;

		$capsule->addConnection($db_config);

		$capsule->bootEloquent();

		// Make this Capsule instance available globally via static methods... (optional)
		$capsule->setAsGlobal();

		/*
		// have DB:: and query builder
		use Illuminate\Container\Container;
		use Illuminate\Support\Facades\Facade;

		// Set up a container manually
		$container = new Container();

		// Bind the container to facades
		Facade::setFacadeApplication($container);

		// Bind 'db' to the capsule instance, so DB:: works
		$container->instance('db', $capsule->getDatabaseManager());
		*/

		return $capsule;
	}

	public static function verifySchema(
		Capsule $capsule,
		MigrationStatus $migrationStatus
	): void {

		self::refreshDbSchema($capsule);

		// Base schema
		self::requireTable(AppConfig::MAIL_LOGS_TABLE);

		// Migrations schema
		self::verifyOptionalMigrationSchema($migrationStatus);
		// Future: Migrations will become mandatory
		// replace verifyOptionalMigrationSchema with verifyRequiredMigrationSchema
		// self::verifyRequiredMigrationSchema($migrationStatus);
	}

	private static function verifyOptionalMigrationSchema(
		MigrationStatus $migrationStatus
	): void {

		$migrationsTableExists = array_key_exists(
			AppConfig::MIGRATIONS_TABLE,
			self::$schema
		);

		$migrationStatus->setMigrationTableExists($migrationsTableExists);

		if (!$migrationsTableExists) {
			return;
		}

		self::verifyMigrationSchema($migrationStatus);
	}

	private static function verifyRequiredMigrationSchema(
		MigrationStatus $migrationStatus
	): void {
		self::requireTable(AppConfig::MIGRATIONS_TABLE);

		$migrationStatus->setMigrationTableExists(true);

		self::verifyMailRecipients();
		self::verifyMailLogData();
		self::verifyCreatedDay();
	}

	private static function verifyMigrationSchema(
		MigrationStatus $migrationStatus
	): void {

		// First migration completion query will lazy-load the migrationStatus state cache.
		// Kernel also explicitly warms the cache later after schema verification.

		if ($migrationStatus->mailRecipientsCompleted()) {
			self::verifyMailRecipients();
		}

		if ($migrationStatus->mailLogDataCompleted()) {
			self::verifyMailLogData();
		}

		if ($migrationStatus->createdDayCompleted()) {
			self::verifyCreatedDay();
		}
	}

	private static function verifyCreatedDay(): void {
		self::requireColumn(
			AppConfig::MAIL_LOGS_TABLE,
			'created_day'
		);
	}

	private static function verifyMailRecipients(): void {
		self::requireTable(AppConfig::MAIL_LOG_RECIPIENTS_TABLE);

		self::requireColumn(
			AppConfig::MAIL_LOG_RECIPIENTS_TABLE,
			'mail_log_id'
		);

		self::requireColumn(
			AppConfig::MAIL_LOG_RECIPIENTS_TABLE,
			'recipient_email'
		);
	}

	private static function verifyMailLogData(): void {
		self::requireTable(AppConfig::MAIL_LOG_DATA_TABLE);

		self::requireColumn(
			AppConfig::MAIL_LOG_DATA_TABLE,
			'mail_log_id'
		);

		self::requireColumn(
			AppConfig::MAIL_LOG_DATA_TABLE,
			'headers'
		);

		self::requireColumn(
			AppConfig::MAIL_LOG_DATA_TABLE,
			'symbols'
		);

		self::requireColumn(
			AppConfig::MAIL_LOG_DATA_TABLE,
			'fuzzy_hashes'
		);
	}

	private static function refreshDbSchema(Capsule $capsule): void {
		self::$schema = [];
		self::$schemaLoaded = false;

		self::getDbSchema($capsule);
	}

	private static function getDbSchema(
		Capsule $capsule
	): array {
		// might be cached
		if (self::$schemaLoaded) {
			return self::$schema;
		}

		$rows = $capsule->getConnection()
			->select("SELECT TABLE_NAME, COLUMN_NAME
			          FROM information_schema.COLUMNS
			          WHERE TABLE_SCHEMA = ?
			          ORDER BY TABLE_NAME, ORDINAL_POSITION",
			          [$_ENV['DB_NAME']]
			);

		$schema = [];

		foreach ($rows as $row) {
			$schema[$row->TABLE_NAME][] = $row->COLUMN_NAME;
		}

		self::$schema = $schema;
		self::$schemaLoaded = true;

		return self::$schema;
	}

	private static function requireTable(string $table): void {
		if (!array_key_exists($table, self::$schema)) {
			throw new RuntimeException(
				"Required table '{$table}' is missing."
			);
		}
	}

	private static function requireColumn(string $table, string $column): void {
		self::requireTable($table);

		if (!in_array($column, self::$schema[$table], true)) {
			throw new RuntimeException(
				"Required column '{$table}.{$column}' is missing."
			);
		}
	}

}
