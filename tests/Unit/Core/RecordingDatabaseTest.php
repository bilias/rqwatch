<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 RecordingDatabase's server: a MariaDB, as production's, so the grammar
 builds a per-group limit (an eager load's limit per parent) with a window
 function, not the variables MySQL before 8.0 needed. Bindings that are
 not a list (a gap, a name), which PDO would leave a placeholder unbound
 for, throw. Other statements (SET, DDL) and unprepared SQL are recorded,
 after $onStatement, which may refuse them. A qid in $failInsert fails its
 next insert only.
*/

namespace Tests\Unit\Core;

use Illuminate\Database\MySqlConnection;
use LogicException;
use PDO;
use RuntimeException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Unit\RecordingDatabase;
use Tests\Unit\UnitTestCase;

final class RecordingDatabaseTest extends UnitTestCase
{
	#[\Override]
	protected function tearDown(): void {
		RecordingDatabase::forget();
		parent::tearDown();
	}

	public function testServer(): void {
		$db = new RecordingDatabase($this->createStub(PDO::class));
		$connection = $db->capsule->getConnection();
		$this->assertInstanceOf(MySqlConnection::class, $connection);
		$this->assertSame([true, '10.11.6'], [$connection->isMaria(), $connection->getServerVersion()]);
		$this->assertSame(
			'select * from (select *, row_number() over (partition by `mail_log_id` order by `id` asc) as `laravel_row` from `mail_log_recipients`) as `laravel_table` where `laravel_row` <= 2 order by `laravel_row`',
			$db->capsule->table('mail_log_recipients')->orderBy('id')->groupLimit(2, 'mail_log_id')->toSql()
		);
	}

	/** @return array<string, array{string, array<array-key, mixed>}> */
	public static function keyedBindings(): array {
		return [
			'a select with a gap' => ['select', [0 => 1, 2 => 2]],
			'an update by name' => ['update', ['a' => 1]],
			'an insert from 1' => ['insert', [1 => 1]],
			'a statement by name' => ['statement', ['a' => 1]],
		];
	}

	/** @param array<array-key, mixed> $bindings */
	#[DataProvider('keyedBindings')]
	public function testBindingsNotAList(string $statement, array $bindings): void {
		$db = new RecordingDatabase($this->createStub(PDO::class));
		$connection = $db->capsule->getConnection();
		$this->expectException(LogicException::class);
		match ($statement) {
			'select' => $connection->select('select * from `t` where `a` = ? and `b` = ?', $bindings),
			'update' => $connection->update('update `t` set `a` = ?', $bindings),
			'statement' => $connection->statement('set @a = ?', $bindings),
			default => $connection->insert('insert into `t` (`a`) values (?)', $bindings),
		};
	}

	public function testBindingsList(): void {
		$db = new RecordingDatabase($this->createStub(PDO::class));
		$db->capsule->getConnection()->update('update `t` set `a` = ?', [5]);
		$this->assertSame([['update `t` set `a` = ?', [5]]], $db->changes);
	}

	public function testStatements(): void {
		$db = new RecordingDatabase($this->createStub(PDO::class));
		$connection = $db->capsule->getConnection();
		$this->assertTrue($connection->statement('SET SESSION max_statement_time = ?', [0]));
		$this->assertTrue($connection->unprepared('DROP TABLE `t`'));
		$this->assertSame([['SET SESSION max_statement_time = ?', [0]], ['DROP TABLE `t`', []]], $db->statements);
		$this->assertSame([[], [], []], [$db->selects, $db->changes, $db->rows]);
	}

	public function testStatementRefused(): void {
		$db = new RecordingDatabase($this->createStub(PDO::class));
		$seen = [];
		$db->onStatement = function (string $sql, array $bindings) use (&$seen): void {
			$seen[] = [$sql, $bindings];
			throw new RuntimeException('refused');
		};
		try {
			$db->capsule->getConnection()->unprepared('OPTIMIZE TABLE `t`');
			$this->fail('not refused');
		} catch (RuntimeException $e) {
			$this->assertSame('refused', $e->getMessage());
		}
		$this->assertSame([['OPTIMIZE TABLE `t`', []]], $seen);
		$this->assertSame([], $db->statements);
	}

	public function testFailInsertOnce(): void {
		$db = new RecordingDatabase($this->createStub(PDO::class));
		$failure = new RuntimeException('gone away');
		$db->failInsert['Q1'] = $failure;
		$table = $db->capsule->table('mail_logs');
		try {
			$table->insert(['qid' => 'Q1']);
			$this->fail('not failed');
		} catch (RuntimeException $e) {
			$this->assertSame($failure, $e);
		}
		$this->assertSame([], $db->failInsert);
		$this->assertTrue($table->insert(['qid' => 'Q1']));
		$this->assertSame(['mail_logs' => [['qid' => 'Q1']]], $db->rows);
	}
}
