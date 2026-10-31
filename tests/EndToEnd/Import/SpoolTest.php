<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis
 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The failed-import spool: refused writes, degraded boot, and its refusal.
*/

namespace Tests\EndToEnd\Import;

use PHPUnit\Framework\Attributes\TestDox;
use RuntimeException;
use Tests\EndToEnd\EndToEndTestCase;

final class SpoolTest extends EndToEndTestCase
{
	#[TestDox('Database refuses writes: spooled to Redis, imported later by cron:import_spool, arrival time kept')]
	public function testSpooledWhenDatabaseRefusesWrites(): void {
		// created_at of mails imported directly, before and after, brackets the spooled one
		[, , $before] = $this->import('clean');

		self::db()->exec('SET GLOBAL read_only = 1');
		try {
			[, $reply] = $this->import('spam', wait: false);
			self::waitForSpool();
			$this->assertSame([], self::query('SELECT id FROM mail_logs WHERE message_id = ?',
				["<{$this->sent['id']}@sender.test>"]), 'imported despite read_only');
		} finally {
			self::db()->exec('SET GLOBAL read_only = 0');
		}
		sleep(2);

		self::cli('cron:import_spool', '-i', '-l');
		$row = self::row("<{$this->sent['id']}@sender.test>");
		$this->assertImported($reply, $row);
		$this->assertQuarantined($row);
		$this->assertStringContainsString('No spooled entries found', self::cli('cron:import_spool', '-l', '-v'));

		[, , $after] = $this->import('clean');
		$created = strtotime((string) $row['created_at']);
		$this->assertGreaterThanOrEqual(strtotime((string) $before['created_at']), $created,
			'spooled created_at earlier than a mail imported before it (PHP and database clocks differ?)');
		$this->assertLessThan(strtotime((string) $after['created_at']), $created,
			'spooled created_at is the replay time, or PHP and database clocks differ');
	}

	#[TestDox('Database unreachable at boot: importer boots degraded, spools, cron:import_spool imports it')]
	public function testSpooledOnDegradedBoot(): void {
		$reply = [];
		self::withDatabaseUnreachable(function () use (&$reply): void {
			[, $reply] = $this->import('spam', wait: false);
			self::waitForLog("{$this->sent['qid']} no database connection, spooling");
			self::waitForLog("{$this->sent['qid']} spooled as");
		});

		self::waitForSpool();
		self::cli('cron:import_spool', '-i', '-l');
		$row = self::row("<{$this->sent['id']}@sender.test>");
		$this->assertImported($reply, $row);
		$this->assertQuarantined($row);
		$this->assertStringContainsString('No spooled entries found', self::cli('cron:import_spool', '-l', '-v'));
	}

	#[TestDox('Database unreachable, no migration status in Redis: degraded boot refused, nothing spooled')]
	public function testDegradedBootRefusedWithoutMigrationStatus(): void {
		$redis = self::redis();
		$key = 'rqwatch_migration_status:' . self::SERVER;
		$state = $redis->get($key);
		$this->assertIsString($state, 'fixture: a persisted migration status');
		$refusal = 'No usable migration status in Redis; refusing degraded boot';
		$refusals = self::logCount($refusal);

		$redis->del($key);
		try {
			self::withDatabaseUnreachable(function () use ($refusal, $refusals): void {
				$this->import('spam', wait: false);
				self::waitForLog($refusal, $refusals);
			});
		} finally {
			$redis->set($key, $state);
		}

		$this->assertSame(0, self::logCount("{$this->sent['qid']} spooled as"), 'spooled');
		$this->assertStringContainsString('No spooled entries found', self::cli('cron:import_spool', '-l', '-v'));
		$this->assertSame([], self::query('SELECT id FROM mail_logs WHERE message_id = ?',
			["<{$this->sent['id']}@sender.test>"]), 'imported');
		$this->assertDirectoryDoesNotExist(self::quarantineDir() . '/' . date('Y-m-d') . "/{$this->sent['qid']}");
	}

	/**
	 * Runs $fn with the copy's .env pointing DB_PORT at a closed port, so
	 * the importer (which rereads .env per request) cannot reach the
	 * database; the tests' own connection is unaffected.
	 */
	private static function withDatabaseUnreachable(callable $fn): void {
		$env = (string) getenv('E2E_ROOT') . '/.env';
		$original = (string) file_get_contents($env);
		$probe = stream_socket_server('tcp://127.0.0.1:0');
		$closed = (int) explode(':', (string) stream_socket_get_name($probe, false))[1];
		fclose($probe);

		file_put_contents($env, (string) preg_replace('/^DB_PORT=.*$/m', "DB_PORT={$closed}", $original));
		try {
			$fn();
		} finally {
			file_put_contents($env, $original);
		}
	}

	private static function logCount(string $needle): int {
		$log = @file_get_contents((string) getenv('E2E_ROOT') . '/logs/rqwatch.log');
		return $log === false ? 0 : substr_count($log, $needle);
	}

	/** Waits until logs/rqwatch.log holds more than $seen lines with $needle. */
	private static function waitForLog(string $needle, int $seen = 0): void {
		$deadline = microtime(true) + self::WAIT_SECONDS;
		do {
			if (self::logCount($needle) > $seen) {
				return;
			}
			usleep(200000);
		} while (microtime(true) < $deadline);

		throw new RuntimeException("Not logged within " . self::WAIT_SECONDS . "s: {$needle}");
	}

	private static function redis(): \Redis {
		$redis = new \Redis();
		$redis->connect('127.0.0.1', (int) getenv('E2E_REDIS_PORT'));
		return $redis;
	}

	/** Waits until cron:import_spool reports one pending entry for this server. */
	private static function waitForSpool(): void {
		$deadline = microtime(true) + self::WAIT_SECONDS;
		do {
			if (str_contains(self::cli('cron:import_spool', '-l', '-v'), '1 spooled entries found')) {
				return;
			}
			usleep(200000);
		} while (microtime(true) < $deadline);

		throw new RuntimeException('Nothing was spooled within ' . self::WAIT_SECONDS . 's');
	}
}
