<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 cron:fuzzy_cleanup on RecordingDatabase. Only one run at a time; nothing
 to do before the fuzzy hits migration or with fuzzy_learn_expire_days 0.
 The learned mails unchanged for that many days (this server's with -l)
 are counted, listed with -s and deleted with -d.
*/

namespace Tests\Unit\Console;

use App\Configuration\Config;
use App\Console\CronFuzzyCleanup;
use App\Core\App;
use App\Core\AppContainer;
use App\Core\Database\MigrationStatus;
use App\Inventory\Migrations;
use Illuminate\Database\Eloquent\Model;
use PDO;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Tests\Unit\PrivateLock;
use Tests\Unit\RecordingDatabase;
use Tests\Unit\UnitTestCase;

final class CronFuzzyCleanupTest extends UnitTestCase
{
	private RecordingDatabase $db;

	private MigrationStatus $status;

	private string $dir = '';

	/** @var list<array<string, mixed>> the expired records */
	private array $expired = [];

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->db = new RecordingDatabase($this->createStub(PDO::class));
		$this->db->capsule->bootEloquent();
		$this->db->answer = fn (string $sql): array => str_starts_with($sql, 'select count(*)') ? [['aggregate' => count($this->expired)]] : $this->expired;
		$this->status = new MigrationStatus(null, $this->log);
		$this->status->setMigrationState(Migrations::MAIL_LOG_FUZZY_HITS, Migrations::STATUS_COMPLETED);
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, $this->db->capsule, $this->status, null, false));
		Config::set('fuzzy_learn_expire_days', 90);
		$this->setEnv('MY_API_SERVER_ALIAS', 'mx1');
		$this->dir = sys_get_temp_dir() . '/rqwatch-cfc-' . bin2hex(random_bytes(4));
		mkdir($this->dir);
	}

	#[\Override]
	protected function tearDown(): void {
		$files = glob("{$this->dir}/*");
		foreach ($files === false ? [] : $files as $file) {
			unlink($file);
		}
		rmdir($this->dir);
		Model::unsetConnectionResolver();
		RecordingDatabase::forget();
		parent::tearDown();
	}

	/**
	 * @param array<string, mixed> $options
	 * @return array{int, string}
	 */
	private function cron(array $options): array {
		$tester = new CommandTester(PrivateLock::give(new CronFuzzyCleanup(), $this->dir));
		$status = $tester->execute($options, ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);
		return [$status, $tester->getDisplay()];
	}

	private function twoExpired(): void {
		$this->expired = [
			['id' => 4, 'qid' => 'L4', 'api_server' => 'mx1', 'created_at' => '2026-01-01 10:00:00', 'updated_at' => '2026-02-01 10:00:00', 'last_hit_at' => null],
			['id' => 6, 'qid' => 'L6', 'api_server' => 'mx1', 'created_at' => '2026-01-02 10:00:00', 'updated_at' => '2026-02-02 10:00:00', 'last_hit_at' => '2026-02-02 09:00:00'],
		];
	}

	public function testDelete(): void {
		$this->twoExpired();
		[$status, $display] = $this->cron(['-d' => true, '-s' => true]);
		$this->assertSame(Command::SUCCESS, $status);
		$this->assertSame("2 fuzzy records unchanged for 90 days\n"
			. "QID: L4 server: mx1 learned: 2026-01-01 10:00:00 updated: 2026-02-01 10:00:00 last hit: never\n"
			. "QID: L6 server: mx1 learned: 2026-01-02 10:00:00 updated: 2026-02-02 10:00:00 last hit: 2026-02-02 09:00:00\n"
			. "2 fuzzy records deleted\n", $display);
		$this->assertSame([['delete from `mail_log_fuzzy` where `id` = ?', [4]], ['delete from `mail_log_fuzzy` where `id` = ?', [6]]], $this->db->changes);
		$this->assertSame(['select count(*) as aggregate from `mail_log_fuzzy` where updated_at < NOW() - INTERVAL ? DAY', [90]], $this->db->selects[0] ?? null);
		$this->assertContains('info cron:fuzzy_cleanup 2 fuzzy records unchanged for 90 days', $this->log->lines);
		$this->assertContains('info cron:fuzzy_cleanup 2 fuzzy records deleted', $this->log->lines);
	}

	// without -d: counted only; -l this server's
	public function testCountLocal(): void {
		$this->twoExpired();
		[$status, $display] = $this->cron(['-l' => true]);
		$this->assertSame([Command::SUCCESS, "2 fuzzy records unchanged for 90 days on server: mx1\nUse -d to delete them\n"], [$status, $display]);
		$this->assertSame([90, 'mx1'], $this->db->selects[0][1] ?? null);
		$this->assertSame([], $this->db->changes);
	}

	public function testNothing(): void {
		[$status, $display] = $this->cron(['-d' => true, '-l' => true]);
		$this->assertSame([Command::SUCCESS, "No fuzzy records unchanged for 90 days on server: mx1\n"], [$status, $display]);
	}

	public function testDisabled(): void {
		Config::set('fuzzy_learn_expire_days', 0);
		$this->assertSame([Command::SUCCESS, "\$fuzzy_learn_expire_days is 0, cleanup disabled\n"], $this->cron(['-d' => true]));
		Config::set('fuzzy_learn_expire_days', null);
		$this->assertSame([Command::SUCCESS, "\$fuzzy_learn_expire_days is 0, cleanup disabled\n"], $this->cron(['-d' => true]));
		$this->assertSame([], $this->db->selects);
	}

	public function testBeforeMigration(): void {
		$this->status->setMigrationState(Migrations::MAIL_LOG_FUZZY_HITS, Migrations::STATUS_RUNNING);
		$this->assertSame([Command::SUCCESS, "Fuzzy hits migration not completed, nothing to do\n"], $this->cron(['-d' => true]));
		$this->assertSame([], $this->db->selects);
	}

	public function testAlreadyRunning(): void {
		$lock = (new LockFactory(new FlockStore($this->dir)))->createLock('cron:fuzzy_cleanup');
		$this->assertTrue($lock->acquire());
		$this->assertSame([Command::FAILURE, "Already running in another process.\n"], $this->cron([]));
		$this->assertSame(['warning cron:fuzzy_cleanup Already running in another process'], $this->log->lines);
		$lock->release();
	}
}
