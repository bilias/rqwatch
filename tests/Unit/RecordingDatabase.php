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
 its statements as usual. Each INSERTed row is recorded by table; the id
 of a mail_logs row is 100 plus its number, and a mail_logs row of a qid
 in $failInsert throws instead. Each SELECT is recorded with its bindings
 and answered by $answer, with no rows without one. Each UPDATE and DELETE
 is recorded with its bindings, in order, and reports the rows $affect
 gives, none without one. The Capsule is set as
 global (the static Capsule::connection() reads it); forget() puts that
 back. Eloquent models need $capsule->bootEloquent() too, which a test
 undoes with Model::unsetConnectionResolver().
*/

namespace Tests\Unit;

use Closure;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;
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

	/** @var array<string, Throwable> qid => what its mail_logs insert throws */
	public array $failInsert = [];

	/** @var list<array{string, list<mixed>}> each SELECT run: its SQL and bindings */
	public array $selects = [];

	/** @var (Closure(string, list<mixed>): list<array<string, mixed>>)|null the rows a SELECT returns */
	public ?Closure $answer = null;

	/** @var list<array{string, list<mixed>}> each UPDATE and DELETE run, in order: its SQL and bindings */
	public array $changes = [];

	/** @var (Closure(string, list<mixed>): int)|null the rows an UPDATE or DELETE changes */
	public ?Closure $affect = null;

	public function __construct(PDO&Stub $pdo) {
		$pdo->method('lastInsertId')->willReturnCallback(fn (): string => (string) (100 + count($this->rows['mail_logs'] ?? [])));
		$connection = new class ($pdo, $this->record(...), $this->query(...), $this->change(...)) extends Connection {
			/**
			 * @param Closure(string, array<array-key, mixed>): void $record
			 * @param Closure(string, array<array-key, mixed>): list<object> $query
			 * @param Closure(string, array<array-key, mixed>): int $change
			 */
			public function __construct(PDO $pdo, private Closure $record, private Closure $query, private Closure $change) {
				parent::__construct($pdo);
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
			 */
			#[\Override]
			public function insert($query, $bindings = []): bool {
				($this->record)($query, $bindings);
				return true;
			}

			/**
			 * an UPDATE or DELETE the query builder made, recorded and not run
			 * @param string $query
			 * @param array<array-key, mixed> $bindings
			 */
			#[\Override]
			public function affectingStatement($query, $bindings = []): int {
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
	 * @param array<array-key, mixed> $bindings
	 * @return list<object>
	 */
	private function query(string $sql, array $bindings): array {
		$bindings = array_values($bindings);
		$this->selects[] = [$sql, $bindings];
		$rows = $this->answer === null ? [] : ($this->answer)($sql, $bindings);
		return array_map(fn (array $row): object => (object) $row, $rows);
	}

	/** @param array<array-key, mixed> $bindings */
	private function change(string $sql, array $bindings): int {
		$bindings = array_values($bindings);
		$this->changes[] = [$sql, $bindings];
		return $this->affect === null ? 0 : ($this->affect)($sql, $bindings);
	}

	/** @param array<array-key, mixed> $bindings */
	private function record(string $sql, array $bindings): void {
		if (preg_match('/^insert into "(\w+)" \(([^)]*)\) values /D', $sql, $match) !== 1) {
			throw new LogicException("Not an INSERT: {$sql}");
		}
		$columns = array_map(fn (string $column): string => trim($column, '"'), explode(', ', $match[2]));
		foreach (array_chunk(array_values($bindings), count($columns)) as $values) {
			$row = array_combine($columns, $values);
			/** @var mixed $qid */
			$qid = $row['qid'] ?? null;
			if ($match[1] === 'mail_logs' && is_string($qid) && isset($this->failInsert[$qid])) {
				throw $this->failInsert[$qid];
			}
			$this->rows[$match[1]][] = $row;
		}
	}
}
