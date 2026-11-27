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
 for, throw.
*/

namespace Tests\Unit\Core;

use Illuminate\Database\MySqlConnection;
use LogicException;
use PDO;
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
			default => $connection->insert('insert into `t` (`a`) values (?)', $bindings),
		};
	}

	public function testBindingsList(): void {
		$db = new RecordingDatabase($this->createStub(PDO::class));
		$db->capsule->getConnection()->update('update `t` set `a` = ?', [5]);
		$this->assertSame([['update `t` set `a` = ?', [5]]], $db->changes);
	}
}
