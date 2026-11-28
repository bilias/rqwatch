<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 A database for the unit tests that runs nothing: the query builder builds
 its statements as usual, with the MySQL grammar production uses, on a
 MariaDB 10.11 as the server (the grammar asks for some SQL). Each
 INSERTed row is recorded by table, an INSERT IGNORE reporting them all
 inserted; the id of a mail_logs row is 100 plus its number, and a row
 of a qid in $failInsert (in any table) throws instead, once, as does any
 row of a table in $failInsertInto. Each SELECT is
 recorded with its bindings and answered by $answer, with no rows without
 one. Each UPDATE and DELETE is recorded with its bindings, in order, and
 reports the rows $affect gives, none without one. Any other statement
 (SET, DDL, OPTIMIZE) is recorded in $statements, after $onStatement, which
 may throw. A transaction rolled back is recorded in $rolledBack; what it
 undid stays recorded. Bindings must be a
 list, as PDO binds them by key: a gap or a name throws. The Capsule is set as
 global (the static Capsule::connection() reads it); forget() puts that
 back. Eloquent models need $capsule->bootEloquent() too, which a test
 undoes with Model::unsetConnectionResolver().
*/

namespace Tests\Unit;

use Closure;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use LogicException;
use PDO;
use PHPUnit\Framework\MockObject\Stub;
use ReflectionProperty;
use Throwable;

final class RecordingDatabase
{
	public readonly Capsule $capsule;

	/** @var array<string, list<array<string, mixed>>> the rows inserted, by table */
	public array $rows = [];

	/** @var array<string, Throwable> qid => what the next insert of a row with it throws */
	public array $failInsert = [];

	/** @var array<string, Throwable> table => what an insert into it throws */
	public array $failInsertInto = [];

	/** @var list<array{string, list<mixed>}> each SELECT run: its SQL and bindings */
	public array $selects = [];

	/** @var (Closure(string, list<mixed>): list<array<string, mixed>>)|null the rows a SELECT returns */
	public ?Closure $answer = null;

	/** @var list<array{string, list<mixed>}> each UPDATE and DELETE run, in order: its SQL and bindings */
	public array $changes = [];

	/** @var (Closure(string, list<mixed>): int)|null the rows an UPDATE or DELETE changes */
	public ?Closure $affect = null;

	/** @var list<int> each transaction rolled back: the INSERTs, UPDATEs and DELETEs it undid (one not nested) */
	public array $rolledBack = [];

	// the INSERTs, UPDATEs and DELETEs recorded, and how many of them before the open transaction
	private int $writes = 0;
	private int $writesBefore = 0;

	/** @var list<array{string, list<mixed>}> each other statement run, in order: its SQL and bindings */
	public array $statements = [];

	/** @var (Closure(string, list<mixed>): void)|null called before a statement is recorded; may throw */
	public ?Closure $onStatement = null;

	// as MariaDB reports itself over the MySQL protocol
	public const string SERVER_VERSION = '5.5.5-10.11.6-MariaDB';

	public function __construct(PDO&Stub $pdo) {
		$pdo->method('getAttribute')->willReturnCallback(fn (int $attribute): ?string => $attribute === PDO::ATTR_SERVER_VERSION ? self::SERVER_VERSION : null);
		$lastId = fn (): int => 100 + count($this->rows['mail_logs'] ?? []);
		$began = function (): void {
			$this->writesBefore = $this->writes;
		};
		$undone = function (): void {
			$this->rolledBack[] = $this->writes - $this->writesBefore;
		};
		$connection = new class ($pdo, $this->record(...), $this->query(...), $this->change(...), $lastId, $this->statement(...), $began, $undone) extends MySqlConnection {
			/**
			 * @param Closure(string, array<array-key, mixed>): int $record
			 * @param Closure(string, array<array-key, mixed>): list<object> $query
			 * @param Closure(string, array<array-key, mixed>): int $change
			 * @param Closure(): int $lastId
			 * @param Closure(string, array<array-key, mixed>): void $statement
			 * @param Closure(): void $began
			 * @param Closure(): void $undone
			 */
			public function __construct(PDO $pdo, private Closure $record, private Closure $query, private Closure $change, private Closure $lastId, private Closure $statement, private Closure $began, private Closure $undone) {
				parent::__construct($pdo);
			}

			// a transaction begun: transaction() and the caller's own begin here
			#[\Override]
			public function beginTransaction(): void {
				parent::beginTransaction();
				($this->began)();
			}

			/**
			 * a transaction rolled back, recorded
			 * @param int|null $toLevel
			 */
			#[\Override]
			public function rollBack($toLevel = null): void {
				parent::rollBack($toLevel);
				($this->undone)();
			}

			/**
			 * any other statement (SET, DDL, OPTIMIZE), recorded and not run
			 * @param string $query
			 * @param array<array-key, mixed> $bindings
			 */
			#[\Override]
			public function statement($query, $bindings = []): bool {
				($this->statement)($query, $bindings);
				return true;
			}

			/**
			 * a statement without bindings, recorded and not run
			 * @param string $query
			 */
			#[\Override]
			public function unprepared($query): bool {
				($this->statement)($query, []);
				return true;
			}

			/**
			 * the statement the query builder made, recorded and answered, not run
			 * @param string $query
			 * @param array<array-key, mixed> $bindings
			 * @param bool $useReadPdo
			 * @return list<object>
			 */
			#[\Override]
			public function select($query, $bindings = [], $useReadPdo = true): array {
				return ($this->query)($query, $bindings);
			}

			/**
			 * the statement the query builder made, recorded and not run
			 * @param string $query
			 * @param array<array-key, mixed> $bindings
			 * @param string|null $sequence unused
			 */
			#[\Override]
			public function insert($query, $bindings = [], $sequence = null): bool {
				($this->record)($query, $bindings);
				$this->lastInsertId = ($this->lastId)();
				return true;
			}

			/**
			 * an UPDATE or DELETE the query builder made, recorded and not run
			 * @param string $query
			 * @param array<array-key, mixed> $bindings
			 */
			#[\Override]
			public function affectingStatement($query, $bindings = []): int {
				if (str_starts_with($query, 'insert ignore ')) {
					return ($this->record)($query, $bindings);
				}
				return ($this->change)($query, $bindings);
			}
		};
		$this->capsule = new Capsule();
		$this->capsule->addConnection(['driver' => 'rqwatch-stub']);
		$this->capsule->getDatabaseManager()->extend('rqwatch-stub', fn (): Connection => $connection);
		$this->capsule->setAsGlobal();
	}

	// no global Capsule, as without a database
	public static function forget(): void {
		(new ReflectionProperty(Capsule::class, 'instance'))->setValue(null, null);
	}

	/** @return list<array<string, mixed>> */
	public function rows(string $table): array {
		return $this->rows[$table] ?? [];
	}

	/**
	 * Connection::bindValues() binds a key n as placeholder n + 1, a name as
	 * a named parameter: anything but a list leaves a ? unbound
	 * @param array<array-key, mixed> $bindings
	 * @return list<mixed>
	 */
	private static function bindings(string $sql, array $bindings): array {
		if (!array_is_list($bindings)) {
			throw new LogicException("Bindings not a list: {$sql}");
		}
		return $bindings;
	}

	/**
	 * @param array<array-key, mixed> $bindings
	 * @return list<object>
	 */
	private function query(string $sql, array $bindings): array {
		$bindings = self::bindings($sql, $bindings);
		$this->selects[] = [$sql, $bindings];
		$rows = $this->answer === null ? [] : ($this->answer)($sql, $bindings);
		return array_map(fn (array $row): object => (object) $row, $rows);
	}

	/** @param array<array-key, mixed> $bindings */
	private function statement(string $sql, array $bindings): void {
		$bindings = self::bindings($sql, $bindings);
		if ($this->onStatement !== null) {
			($this->onStatement)($sql, $bindings);
		}
		$this->statements[] = [$sql, $bindings];
	}

	/** @param array<array-key, mixed> $bindings */
	private function change(string $sql, array $bindings): int {
		$bindings = self::bindings($sql, $bindings);
		$this->changes[] = [$sql, $bindings];
		$changed = $this->affect === null ? 0 : ($this->affect)($sql, $bindings);
		$this->writes++;
		return $changed;
	}

	/**
	 * @param array<array-key, mixed> $bindings
	 * @return int the rows inserted
	 */
	private function record(string $sql, array $bindings): int {
		if (preg_match('/^insert (?:ignore )?into `(\w+)` \(([^)]*)\) values /D', $sql, $match) !== 1) {
			throw new LogicException("Not an INSERT: {$sql}");
		}
		if (isset($this->failInsertInto[$match[1]])) {
			throw $this->failInsertInto[$match[1]];
		}
		$columns = array_map(fn (string $column): string => trim($column, '`'), explode(', ', $match[2]));
		$chunks = array_chunk(self::bindings($sql, $bindings), count($columns));
		foreach ($chunks as $values) {
			$row = array_combine($columns, $values);
			/** @var mixed $qid */
			$qid = $row['qid'] ?? null;
			if (is_string($qid) && isset($this->failInsert[$qid])) {
				$failure = $this->failInsert[$qid];
				unset($this->failInsert[$qid]);
				throw $failure;
			}
			$this->rows[$match[1]][] = $row;
		}
		$this->writes++;
		return count($chunks);
	}
}
