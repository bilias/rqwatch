<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Adding a column to mail_logs without a rebuild (a rebuild blocks writes
 cluster-wide under Galera TOI). While the index on the virtual column
 ever_stored exists, MariaDB refuses ALGORITHM=INSTANT for any stored column
 add (MDEV-17468 workaround in handler0alter.cc), and dropping the index in
 the same statement does not help. The workaround a migration uses: drop the
 index (NOCOPY), add the column (INSTANT), re-add the index (INPLACE,
 LOCK=NONE). The new column must go after ever_stored: an add before a
 virtual column is refused even without the index. Rows and the generated
 columns stay as they were, and the index is used again.

 On a copy of mail_logs (CREATE TABLE LIKE keeps the generated columns and
 every index) with a child table, in a database of its own. Run on another
 MariaDB with MARIADB_IMAGE (e.g. docker.io/library/mariadb:10.6). If the
 first test fails after a MariaDB upgrade, the restriction may be lifted.
*/

namespace Tests\Integration\Migrations;

use App\Core\Database\Migrations\EverStored;
use PDOException;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Integration\IntegrationTestCase;

final class MailLogsInstantAddTest extends IntegrationTestCase
{
	private const string DB = 'rqwatch_instant_add';
	private const string TABLE = '`' . self::DB . '`.`mail_logs`';
	private const string INDEX = EverStored::INDEX;
	// ER_ALTER_OPERATION_NOT_SUPPORTED, ER_ALTER_OPERATION_NOT_SUPPORTED_REASON
	private const array NOT_SUPPORTED = [1845, 1846];

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$db = self::DB;
		$table = self::TABLE;
		$source = self::valueString('SELECT DATABASE()');
		self::db()->exec("DROP DATABASE IF EXISTS `{$db}`");
		self::db()->exec("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4");
		self::db()->exec("CREATE TABLE {$table} LIKE `{$source}`.`mail_logs`");
		// production's children reference mail_logs by foreign key
		self::db()->exec("CREATE TABLE `{$db}`.`child` (`mail_log_id` INT(10) UNSIGNED NOT NULL,
			FOREIGN KEY (`mail_log_id`) REFERENCES {$table} (`id`) ON DELETE CASCADE) ENGINE=InnoDB");
		self::db()->exec("INSERT INTO {$table} (qid, server, action, mail_stored, mail_location, notified, created_at) VALUES
			('A1', 'mx1', 'reject', 1, '/q/a', 0, NOW()),
			('A2', 'mx1', 'no action', 0, NULL, 0, NOW()),
			('A3', 'mx1', 'discard', 0, '/q/c', 1, NOW() - INTERVAL 2 DAY),
			('A4', 'mx1', 'discard', 0, '0', 0, NOW() - INTERVAL 40 DAY)");
		self::db()->exec("INSERT INTO `{$db}`.`child` (mail_log_id) SELECT id FROM {$table}");
	}

	#[\Override]
	protected function tearDown(): void {
		self::db()->exec('DROP DATABASE IF EXISTS `' . self::DB . '`');
		parent::tearDown();
	}

	#[TestDox('INSTANT add is refused while the ever_stored index exists, also with the index dropped in the same statement')]
	public function testInstantAddRefusedWithTheIndex(): void {
		$table = self::TABLE;
		$index = self::INDEX;
		$this->assertRefused("ALTER TABLE {$table} ADD COLUMN `probe` INT NULL, ALGORITHM=INSTANT");
		$this->assertRefused("ALTER TABLE {$table} DROP INDEX `{$index}`, ADD COLUMN `probe` INT NULL, ALGORITHM=INSTANT");
		$this->assertRefused("ALTER TABLE {$table} DROP INDEX `{$index}`, ADD COLUMN `probe` INT NULL, ALGORITHM=NOCOPY");
	}

	#[TestDox('Drop the index (NOCOPY), add columns after ever_stored (INSTANT), re-add the index (INPLACE, LOCK=NONE): rows unchanged, index used')]
	public function testWorkaround(): void {
		$table = self::TABLE;
		$index = self::INDEX;
		$before = $this->rows();

		self::db()->exec("ALTER TABLE {$table} DROP INDEX `{$index}`, ALGORITHM=NOCOPY");
		self::db()->exec("ALTER TABLE {$table} ADD COLUMN `p_int` INT NULL, ALGORITHM=INSTANT");
		self::db()->exec("ALTER TABLE {$table} ADD COLUMN `p_json` JSON NULL, ALGORITHM=INSTANT");
		self::db()->exec("ALTER TABLE {$table} ADD COLUMN `p_flag` TINYINT(1) NOT NULL DEFAULT 0, ALGORITHM=INSTANT");
		self::db()->exec("ALTER TABLE {$table} ADD COLUMN `p_after` VARCHAR(20) NULL AFTER `ever_stored`, ALGORITHM=INSTANT");
		self::db()->exec("ALTER TABLE {$table} ADD INDEX `{$index}` (`ever_stored`, `created_day`), ALGORITHM=INPLACE, LOCK=NONE");

		$this->assertSame($before, $this->rows());
		$this->assertSame(4, self::valueInt("SELECT COUNT(*) FROM {$table}
			WHERE p_int IS NULL AND p_json IS NULL AND p_flag = 0 AND p_after IS NULL"));
		$this->assertSame(4, self::valueInt('SELECT COUNT(*) FROM `' . self::DB . '`.`child`'));
		$plan = self::db()->query("EXPLAIN SELECT COUNT(*) FROM {$table} FORCE INDEX (`{$index}`)
			WHERE ever_stored = 1 AND created_day >= CURDATE() - INTERVAL 7 DAY");
		$this->assertNotFalse($plan);
		/** @var array<string, mixed> $row */
		$row = $plan->fetch(\PDO::FETCH_ASSOC);
		$this->assertSame($index, $row['key'] ?? null);

		// with the index back, the next INSTANT add is refused again
		$this->assertRefused("ALTER TABLE {$table} ADD COLUMN `p_more` INT NULL, ALGORITHM=INSTANT");
	}

	#[TestDox('Without the index, an INSTANT add before the virtual ever_stored is still refused')]
	public function testAddBeforeTheVirtualColumnRefused(): void {
		$table = self::TABLE;
		$index = self::INDEX;
		self::db()->exec("ALTER TABLE {$table} DROP INDEX `{$index}`, ALGORITHM=NOCOPY");
		$this->assertRefused("ALTER TABLE {$table} ADD COLUMN `probe` INT NULL AFTER `has_virus`, ALGORITHM=INSTANT");
		$this->assertRefused("ALTER TABLE {$table} ADD COLUMN `probe` INT NULL AFTER `mail_stored`, ALGORITHM=INSTANT");
	}

	private function assertRefused(string $sql): void {
		try {
			self::db()->exec($sql);
		} catch (PDOException $e) {
			// the driver's error code, an int for MySQL
			/** @var mixed $code */
			$code = $e->errorInfo[1] ?? null;
			$this->assertContains($code, self::NOT_SUPPORTED, "{$sql}: {$e->getMessage()}");
			return;
		}
		$this->fail("Accepted, expected refused: {$sql}");
	}

	// every row's own and generated columns, to compare before and after
	/** @return list<array<string, mixed>> */
	private function rows(): array {
		$stmt = self::db()->query('SELECT id, qid, action, mail_stored, mail_location, ever_stored,
			notification_pending, created_day FROM ' . self::TABLE . ' ORDER BY id');
		$this->assertNotFalse($stmt);
		/** @var list<array<string, mixed>> */
		return $stmt->fetchAll(\PDO::FETCH_ASSOC);
	}
}
