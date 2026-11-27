<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 cron:updatemapfiles run on RecordingDatabase, with a MAP_DIR of its own.
 Only one run at a time. Every map, or the one -m names (an unknown one
 refused), after syncMaps(): a map with no activity logged gets it now; its
 file is written when it needs to be, reported; a file it cannot write is
 reported as failed, and the run goes on.
*/

namespace Tests\Unit\Console;

use App\Console\CronUpdateMapFiles;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Tests\Unit\PrivateLock;
use Tests\Unit\Services\MapDatabaseTestCase;

final class CronUpdateMapFilesRunTest extends MapDatabaseTestCase
{
	private const array BUILT_IN = ['mail_from_rcpt_to_whitelist', 'mail_from_rcpt_to_blacklist', 'mime_from_rcpt_to_whitelist', 'mime_from_rcpt_to_blacklist',
		'mail_from_whitelist', 'mail_from_blacklist', 'mime_from_whitelist', 'mime_from_blacklist', 'ip_whitelist', 'ip_blacklist'];

	/** @var array<string, string> map => its last activity logged */
	private array $activity = [];

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->answers = [
			['select * from `map_activity_logs` where (`map_name` = ?) limit 1', $this->activityOf(...)],
			['from `map_activity_logs`', fn (): array => array_map(
				fn (string $map, string $at): array => ['map_name' => $map, 'last_changed_at' => $at],
				array_keys($this->activity),
				array_values($this->activity)
			)],
			['from `maps_combined`', [['id' => 1, 'ip' => '192.0.2.7']]],
		];
	}

	/**
	 * @param list<mixed> $bindings
	 * @return list<array<string, mixed>>
	 */
	private function activityOf(array $bindings): array {
		/** @var mixed $map */
		$map = $bindings[0] ?? null;
		return is_string($map) && isset($this->activity[$map]) ? [['map_name' => $map, 'last_changed_at' => $this->activity[$map]]] : [];
	}

	/**
	 * @param array<string, mixed> $options
	 * @return array{int, string}
	 */
	private function cron(array $options = []): array {
		$tester = new CommandTester(PrivateLock::give(new CronUpdateMapFiles(), $this->dir));
		$status = $tester->execute($options, ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);
		return [$status, $tester->getDisplay()];
	}

	// one map: its file written from its entries as of its last activity
	public function testOneMap(): void {
		$this->activity = array_fill_keys(self::BUILT_IN, '2026-11-20 10:00:00');
		[$status, $display] = $this->cron(['-m' => 'ip_blacklist']);
		$this->assertSame([Command::SUCCESS, "Map file 'ip_blacklist' updated\n"], [$status, $display]);
		$this->assertSame(['192.0.2.7'], $this->mapFile('ip_blacklist'));
		$this->assertSame(strtotime('2026-11-20 10:00:00'), filemtime("{$this->dir}/ip_blacklist.txt"));
		$this->assertSame(["info cron:updatemapfiles Map file 'ip_blacklist' updated"], $this->log->lines);
		$this->assertSame([], $this->changedMaps());
	}

	// a file as new as the map's activity is left; one with no activity logged is logged now and written
	public function testEveryMap(): void {
		$this->activity = array_fill_keys(self::BUILT_IN, '2026-11-20 10:00:00');
		unset($this->activity['ip_blacklist']);
		foreach (self::BUILT_IN as $map) {
			file_put_contents("{$this->dir}/{$map}.txt", '# Last-Modified: ' . gmdate('D, d M Y H:i:s', (int) strtotime('2026-11-20 10:00:00')) . " GMT\n");
		}
		[$status, $display] = $this->cron();
		$this->assertSame(Command::SUCCESS, $status);
		$this->assertStringContainsString("Map file 'ip_whitelist' does not need update\n", $display);
		$this->assertStringContainsString("Map file 'ip_blacklist' updated\n", $display);
		// syncMaps() logs it first; the run, having read the log before, again
		$this->assertSame(['ip_blacklist', 'ip_blacklist'], $this->changedMaps());
		$this->assertSame(['192.0.2.7'], $this->mapFile('ip_blacklist'));
		$this->assertContains("debug cron:updatemapfiles Map file 'ip_whitelist' does not need update", $this->log->lines);
	}

	// a map whose name cannot be a file name: failed, the others written
	public function testWriteFails(): void {
		$this->activity = array_fill_keys(self::BUILT_IN, '2026-11-20 10:00:00') + ['Bad.Map' => '2026-11-20 10:00:00'];
		$this->answers[] = ['from `custom_map_config`', [['map_name' => 'Bad.Map', 'map_description' => 'Bad', 'field_name' => 'pattern']]];
		[$status, $display] = $this->cron();
		$this->assertSame(Command::SUCCESS, $status);
		$this->assertStringContainsString("Map file 'Bad.Map' update FAILED - check logs\n", $display);
		$this->assertStringContainsString("Map file 'ip_blacklist' updated\n", $display);
		$this->assertContains("error cron:updatemapfiles Map file 'Bad.Map' update FAILED (model 'MapCustom')", $this->log->lines);
	}

	public function testUnknownMap(): void {
		[$status, $display] = $this->cron(['-m' => 'ip_greylist']);
		$this->assertSame([Command::FAILURE, "Map 'ip_greylist' not found\n"], [$status, $display]);
		$this->assertSame(["warning cron:updatemapfiles Map 'ip_greylist' not found"], $this->log->lines);
	}

	public function testAlreadyRunning(): void {
		$lock = (new LockFactory(new FlockStore($this->dir)))->createLock('cron:updatemapfiles');
		$this->assertTrue($lock->acquire());
		$this->assertSame([Command::FAILURE, "Already running in another process.\n"], $this->cron());
		$this->assertSame(['warning cron:updatemapfiles Already running in another process'], $this->log->lines);
		$lock->release();
	}
}
