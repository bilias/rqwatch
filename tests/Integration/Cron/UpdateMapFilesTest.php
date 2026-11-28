<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 cron:updatemapfiles against the instance's database and map directory. It
 writes a map file that is missing, from the map's enabled entries; leaves
 one as new as the map's last change; and writes one again when the map
 changed since (what a change whose file could not be written leaves
 behind). An unknown map, and a map file that cannot be written, end the
 run with a failure. The entries are inserted here, not through the web,
 which would write the file itself.
*/

namespace Tests\Integration\Cron;

use PHPUnit\Framework\Attributes\TestDox;
use RuntimeException;
use Tests\Integration\IntegrationTestCase;

final class UpdateMapFilesTest extends IntegrationTestCase
{
	private const string MAP = 'ip_blacklist';
	private const string IP = '198.51.100.77';
	private const string DISABLED_IP = '198.51.100.78';
	private const string HEADER = '/^# Last-Modified: \w{3}, \d\d \w{3} \d{4} \d\d:\d\d:\d\d GMT$/D';

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		self::deleteEntries();
	}

	#[\Override]
	protected function tearDown(): void {
		self::deleteEntries();
		parent::tearDown();
	}

	private static function deleteEntries(): void {
		self::db()->prepare('DELETE FROM maps_combined WHERE map_name = ? AND ip IN (?, ?)')
			->execute([self::MAP, self::IP, self::DISABLED_IP]);
	}

	private static function mapFile(): string {
		return self::root() . '/web/maps/' . self::MAP . '.txt';
	}

	/** @return list<string> the map file's lines, its header first */
	private static function mapLines(): array {
		return explode("\n", rtrim((string) file_get_contents(self::mapFile()), "\n"));
	}

	// runs the cron, verbose: it says what it did with each map file
	private static function cron(string ...$options): string {
		return self::cli('cron:updatemapfiles', '-v', ...$options);
	}

	#[TestDox('A missing map file is written from the enabled entries, left while it is current, and written again after the map changed')]
	public function testWritesAMapFileThatIsBehind(): void {
		$insert = self::db()->prepare('INSERT INTO maps_combined (map_name, user_id, ip, disabled)'
			. " SELECT ?, id, ?, ? FROM users WHERE username = 'admin'");
		$insert->execute([self::MAP, self::IP, 0]);
		$insert->execute([self::MAP, self::DISABLED_IP, 1]);
		@unlink(self::mapFile());

		$this->assertStringContainsString("Map file '" . self::MAP . "' updated", self::cron());
		$lines = self::mapLines();
		$header = array_shift($lines);
		$this->assertMatchesRegularExpression(self::HEADER, (string) $header);
		$this->assertContains(self::IP, $lines);
		$this->assertNotContains(self::DISABLED_IP, $lines, 'a disabled entry');

		// as new as the map's last change: left as it is
		$this->assertStringContainsString("Map file '" . self::MAP . "' does not need update", self::cron());
		$this->assertSame($header, self::mapLines()[0]);

		// the map changed since: written again, for this map alone
		self::db()->prepare('DELETE FROM maps_combined WHERE map_name = ? AND ip = ?')->execute([self::MAP, self::IP]);
		$bump = self::db()->prepare('UPDATE map_activity_logs SET last_changed_at = last_changed_at + INTERVAL 1 MINUTE WHERE map_name = ?');
		$bump->execute([self::MAP]);
		$this->assertSame(1, $bump->rowCount(), 'the activity log has the map');
		$output = self::cron('-m', self::MAP);
		$this->assertStringContainsString("Map file '" . self::MAP . "' updated", $output);
		$this->assertStringNotContainsString('ip_whitelist', $output, 'no other map');
		$lines = self::mapLines();
		$this->assertNotSame($header, array_shift($lines), 'a newer Last-Modified');
		$this->assertNotContains(self::IP, $lines);
	}

	#[TestDox('An unknown map: the run fails and says so')]
	public function testUnknownMap(): void {
		$this->assertCronFails("Map 'no_such_map' not found", '-m', 'no_such_map');
	}

	#[TestDox('A map file that cannot be written: the run fails and says which')]
	public function testMapFileNotWritable(): void {
		if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
			$this->markTestSkipped('root writes anywhere');
		}
		// a map directory of its own, read-only: the instance's files are not touched
		$dir = self::root() . '/maps-readonly-' . bin2hex(random_bytes(4));
		mkdir($dir, 0500);
		try {
			self::withEnv(['REDIS_CONFIG_KEY' => '"rqwatch_config_mapfiles_' . bin2hex(random_bytes(4)) . '"'], function () use ($dir): void {
				self::withLocalConfig("\$MAP_DIR = '{$dir}';", function (): void {
					$this->assertCronFails("Map file '" . self::MAP . "' update FAILED - check logs", '-m', self::MAP);
				});
			});
		} finally {
			chmod($dir, 0700);
			rmdir($dir);
		}
	}

	// the run exits 1 (Command::FAILURE) and prints $message
	private function assertCronFails(string $message, string ...$options): void {
		try {
			$output = self::cron(...$options);
			$this->fail("cron:updatemapfiles succeeded: {$output}");
		} catch (RuntimeException $e) {
			$this->assertStringContainsString(' exited 1: ', $e->getMessage());
			$this->assertStringContainsString($message, $e->getMessage());
		}
	}
}
