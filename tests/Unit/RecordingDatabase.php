<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 A database for the unit tests that only takes INSERTs: the query builder
 builds them as usual, and each row is recorded by table instead of run.
 The id of a mail_logs row is 100 plus its number; a mail_logs row of a
 qid in $failInsert throws instead. The Capsule is set as global (the
 static Capsule::connection() reads it); forget() puts that back.
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

	public function __construct(PDO&Stub $pdo) {
		$pdo->method('lastInsertId')->willReturnCallback(fn (): string => (string) (100 + count($this->rows['mail_logs'] ?? [])));
		$connection = new class ($pdo, $this->record(...)) extends Connection {
			/** @param Closure(string, array<array-key, mixed>): void $record */
			public function __construct(PDO $pdo, private Closure $record) {
				parent::__construct($pdo);
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
