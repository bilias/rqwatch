<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 MailLogWriter::databaseUnavailable(): the failures that refuse every mail
 alike (connection, server, locks), which the importer spools, against the
 database rejecting one mail's data, which would fail again on replay and
 so must not be spooled. And describe(): how either is logged.
*/

namespace Tests\Unit\Import;

use App\Services\Import\MailLogWriter;
use Illuminate\Database\QueryException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Unit\UnitTestCase;

final class DatabaseUnavailableTest extends UnitTestCase
{
	/** @return array<string, array{string, string, int, bool}> SQLSTATE, message, driver code, unavailable */
	public static function cases(): array {
		return [
			'server gone away' => ['HY000', 'MySQL server has gone away', 2006, true],
			'lost connection' => ['HY000', 'Lost connection to MySQL server during query', 2013, true],
			'Galera node not ready' => ['08S01', 'WSREP has not yet prepared node for application use', 1047, true],
			'too many connections' => ['08004', 'Too many connections', 1040, true],
			'access denied' => ['28000', "Access denied for user 'rqwatch'", 1045, true],
			'deadlock' => ['40001', 'Deadlock found when trying to get lock; try restarting transaction', 1213, true],
			'lock wait' => ['HY000', 'Lock wait timeout exceeded; try restarting transaction', 1205, true],
			'CHECK constraint' => ['23000', 'CONSTRAINT `mail_log_data.fuzzy_hashes` failed', 4025, false],
			'data too long' => ['22001', "Data too long for column 'subject'", 1406, false],
			'incorrect string' => ['HY000', "Incorrect string value: '\\xF0' for column 'subject'", 1366, false],
			'trigger' => ['45000', 'rejected by a trigger', 1644, false],
			'unknown column' => ['42S22', "Unknown column 'x' in 'field list'", 1054, false],
		];
	}

	#[DataProvider('cases')]
	public function testStatement(string $state, string $message, int $code, bool $unavailable): void {
		$pdo = new PDOException("SQLSTATE[{$state}]: {$message}");
		$pdo->errorInfo = [$state, $code, $message];
		$this->assertSame($unavailable, MailLogWriter::databaseUnavailable($pdo), 'PDOException');
		// as the query builder throws it
		$query = new QueryException('mysql', 'insert into mail_logs ...', [], $pdo);
		$this->assertSame($unavailable, MailLogWriter::databaseUnavailable($query), 'QueryException');
	}

	// a failed connect carries the driver code as the exception code only
	public function testConnect(): void {
		$this->assertTrue(MailLogWriter::databaseUnavailable(
			new PDOException('SQLSTATE[HY000] [2002] Connection refused', 2002)));
	}

	// codes first, then the whole message: for a query, the SQL with its values
	public function testDescribeQuery(): void {
		$pdo = new PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'alice@example.org' for key 'x'");
		$pdo->errorInfo = ['23000', 1062, "Duplicate entry 'alice@example.org' for key 'x'"];
		$query = new QueryException('mysql', 'insert into mail_logs (mail_from) values (?)', ['alice@example.org'], $pdo);
		$described = MailLogWriter::describe($query);
		$this->assertStringStartsWith("SQLSTATE 23000, error 1062: SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'alice@example.org'", $described);
		$this->assertStringContainsString('insert into mail_logs (mail_from) values (alice@example.org)', $described);
	}

	// a failed connect has no errorInfo: its code is the exception's
	public function testDescribeConnect(): void {
		$this->assertSame('SQLSTATE ?, error 2002: SQLSTATE[HY000] [2002] Connection refused',
			MailLogWriter::describe(new PDOException('SQLSTATE[HY000] [2002] Connection refused', 2002)));
	}

	public function testDescribeOther(): void {
		$this->assertSame('RuntimeException: Disk full', MailLogWriter::describe(new RuntimeException('Disk full')));
	}

	public function testNotADatabaseError(): void {
		$this->assertFalse(MailLogWriter::databaseUnavailable(new RuntimeException('Connection refused')));
	}
}
