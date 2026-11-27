<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 MapService::updateMapFile(), with RecordingDatabase answering its one
 query. The file MAP_DIR/<map>.txt is replaced whole: a Last-Modified
 header in GMT, then the map's enabled entries, a combined map's fields
 joined by | (an entry with an empty field left out), a custom map's
 pattern and score (an empty pattern, '' or '0', left out); its mode is
 MAP_FILE_PERM and its mtime the update time. MAP_DIR may be, or be under,
 a symlink. Refused, logged, with nothing left behind: no usable MAP_DIR,
 a map name unfit for a file name, an unknown model, a failed query, a
 file that cannot be put in place. Without MAP_FILE_PERM it throws.
*/

namespace Tests\Unit\Services;

use App\Configuration\Config;
use App\Core\App;
use App\Core\AppContainer;
use App\Core\Database\MigrationStatus;
use App\Services\MapService;
use Illuminate\Database\Eloquent\Model;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Unit\RecordingDatabase;
use Tests\Unit\UnitTestCase;

final class MapFileTest extends UnitTestCase
{
	// with its offset, so the test holds in any timezone
	private const string UPDATED = '2026-11-26 10:00:00 +02:00';
	private const int MTIME = 1795680000;
	private const string HEADER = '# Last-Modified: Thu, 26 Nov 2026 08:00:00 GMT';
	private const string COMBINED_SQL = 'select `id`, `user_id`, `disabled`, `created_at`, `updated_at`, `mail_from`, `rcpt_to`'
		. ' from `maps_combined` where `map_name` = ? and `mail_from` is not null and `rcpt_to` is not null and `disabled` = ?'
		. ' order by `updated_at` desc';
	private const string CUSTOM_SQL = 'select `id`, `map_name`, `pattern`, `disabled`, `score`, `created_at`, `updated_at`'
		. ' from `maps_custom` where `map_name` = ? and `disabled` = ? order by `updated_at` desc';

	private RecordingDatabase $db;

	private string $dir = '';

	/** @var list<array<string, mixed>> the rows the query returns */
	private array $entries = [];

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->db = new RecordingDatabase($this->createStub(PDO::class));
		$this->db->capsule->bootEloquent();
		$this->db->answer = fn (): array => $this->entries;
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, $this->db->capsule, new MigrationStatus(null, $this->log), null, false));
		$this->dir = sys_get_temp_dir() . '/rqwatch-maps-' . bin2hex(random_bytes(4));
		mkdir($this->dir);
		Config::set('MAP_DIR', $this->dir);
		Config::set('MAP_FILE_PERM', 0640);
	}

	#[\Override]
	protected function tearDown(): void {
		Model::unsetConnectionResolver();
		RecordingDatabase::forget();
		self::remove($this->dir);
		@unlink($this->dir . '-link');
		parent::tearDown();
	}

	/** @return list<string> the names in $dir */
	private static function names(string $dir): array {
		$names = scandir($dir);
		return $names === false ? [] : array_values(array_diff($names, ['.', '..']));
	}

	private static function remove(string $path): void {
		if (is_dir($path) && !is_link($path)) {
			foreach (self::names($path) as $name) {
				self::remove("{$path}/{$name}");
			}
			rmdir($path);
		} elseif (file_exists($path) || is_link($path)) {
			unlink($path);
		}
	}

	/** @return list<string> the names in MAP_DIR */
	private function files(): array {
		return self::names($this->dir);
	}

	private function contents(string $map): string {
		return (string) file_get_contents("{$this->dir}/{$map}.txt");
	}

	public function testCombinedMap(): void {
		$this->entries = [
			['id' => 1, 'mail_from' => 'a@example.org', 'rcpt_to' => 'b@example.org'],
			['id' => 2, 'mail_from' => 'c@example.org', 'rcpt_to' => ''],
			['id' => 3, 'mail_from' => null, 'rcpt_to' => 'd@example.org'],
			['id' => 4, 'mail_from' => 'e@example.org', 'rcpt_to' => 'f@example.org'],
		];
		$this->assertTrue((new MapService())->updateMapFile('MapCombined', 'mail_from_rcpt_to_whitelist', self::UPDATED, ['mail_from', 'rcpt_to']));
		$this->assertSame(self::HEADER . "\na@example.org|b@example.org\ne@example.org|f@example.org\n", $this->contents('mail_from_rcpt_to_whitelist'));
		$this->assertSame([[self::COMBINED_SQL, ['mail_from_rcpt_to_whitelist', 0]]], $this->db->selects);
		$this->assertFileWritten('mail_from_rcpt_to_whitelist');
	}

	public function testCustomMap(): void {
		$this->entries = [
			['id' => 1, 'pattern' => 'a.example.org', 'score' => null],
			['id' => 2, 'pattern' => 'b.example.org', 'score' => 5],
			['id' => 3, 'pattern' => '', 'score' => 5],
			['id' => 4, 'pattern' => '0', 'score' => null],
			['id' => 5, 'pattern' => '00', 'score' => null],
		];
		$this->assertTrue((new MapService())->updateMapFile('MapCustom', 'body_url_host', self::UPDATED));
		$this->assertSame(self::HEADER . "\na.example.org\nb.example.org 5\n00\n", $this->contents('body_url_host'));
		$this->assertSame([[self::CUSTOM_SQL, ['body_url_host', 0]]], $this->db->selects);
		$this->assertFileWritten('body_url_host');
	}

	// the header alone: rspamd reads an empty map
	public function testNoEntries(): void {
		$this->assertTrue((new MapService())->updateMapFile('MapCustom', 'empty_map', self::UPDATED));
		$this->assertSame(self::HEADER . "\n", $this->contents('empty_map'));
	}

	public function testReplacesTheFile(): void {
		file_put_contents("{$this->dir}/body_url_host.txt", "old.example.org\n");
		$this->entries = [['id' => 1, 'pattern' => 'new.example.org', 'score' => null]];
		$this->assertTrue((new MapService())->updateMapFile('MapCustom', 'body_url_host', self::UPDATED));
		$this->assertSame(self::HEADER . "\nnew.example.org\n", $this->contents('body_url_host'));
		$this->assertFileWritten('body_url_host');
	}

	// tempnam() names the file by MAP_DIR's real path
	public function testSymlinkedMapDir(): void {
		symlink($this->dir, $this->dir . '-link');
		Config::set('MAP_DIR', $this->dir . '-link');
		$this->entries = [['id' => 1, 'pattern' => 'a.example.org', 'score' => null]];
		$this->assertTrue((new MapService())->updateMapFile('MapCustom', 'body_url_host', self::UPDATED));
		$this->assertSame(self::HEADER . "\na.example.org\n", $this->contents('body_url_host'));
		$this->assertFileWritten('body_url_host');
	}

	public function testDebugSql(): void {
		$this->setEnv('DEBUG_SEARCH_SQL', 'true');
		$this->assertTrue((new MapService())->updateMapFile('MapCustom', 'body_url_host', self::UPDATED));
		$this->assertSame([
			'info select `id`, `map_name`, `pattern`, `disabled`, `score`, `created_at`, `updated_at` from `maps_custom`'
			. ' where `map_name` = "body_url_host" and `disabled` = "0" order by `updated_at` desc',
		], $this->log->lines);
	}

	/** @return array<string, array{?string, string}> MAP_DIR, logged */
	public static function unusableDirs(): array {
		return [
			'unset' => [null, 'error MAP_DIR not writable: '],
			'missing' => ['/nonexistent/rqwatch-maps', 'error MAP_DIR not writable: /nonexistent/rqwatch-maps'],
		];
	}

	#[DataProvider('unusableDirs')]
	public function testUnusableMapDir(?string $dir, string $logged): void {
		Config::set('MAP_DIR', $dir);
		$this->assertFalse((new MapService())->updateMapFile('MapCustom', 'body_url_host', self::UPDATED));
		$this->assertSame([[$logged], []], [$this->log->lines, $this->db->selects]);
	}

	// a file, not a directory
	public function testMapDirIsAFile(): void {
		touch("{$this->dir}/file");
		Config::set('MAP_DIR', "{$this->dir}/file");
		$this->assertFalse((new MapService())->updateMapFile('MapCustom', 'body_url_host', self::UPDATED));
		$this->assertSame(["error MAP_DIR not writable: {$this->dir}/file"], $this->log->lines);
		$this->assertSame(['file'], $this->files());
	}

	/** @return array<string, array{string}> */
	public static function unfitNames(): array {
		return ['a path' => ['../evil'], 'upper case' => ['Body_URL'], 'a dot' => ['map.txt'], 'a line feed' => ["body_url_host\n"], 'empty' => ['']];
	}

	#[DataProvider('unfitNames')]
	public function testUnfitMapName(string $map): void {
		$this->assertFalse((new MapService())->updateMapFile('MapCustom', $map, self::UPDATED));
		$this->assertSame([["error Invalid map name for map file: {$map}"], [], []], [$this->log->lines, $this->db->selects, $this->files()]);
	}

	public function testUnknownModel(): void {
		$this->assertFalse((new MapService())->updateMapFile('MapOther', 'body_url_host', self::UPDATED));
		$this->assertSame([["error Unknown model 'MapOther' for map body_url_host"], []], [$this->log->lines, $this->files()]);
	}

	/** @return array<string, array{string, ?list<string>}> */
	public static function models(): array {
		return ['combined' => ['MapCombined', ['mail_from']], 'custom' => ['MapCustom', null]];
	}

	/** @param list<string>|null $fields */
	#[DataProvider('models')]
	public function testQueryFails(string $model, ?array $fields): void {
		$this->db->answer = static fn (): never => throw new RuntimeException('Lost connection');
		$this->assertFalse((new MapService())->updateMapFile($model, 'some_map', self::UPDATED, $fields));
		$this->assertSame([['error Query error building map some_map: Lost connection'], []], [$this->log->lines, $this->files()]);
	}

	// a directory where the file goes: the new file is not left behind
	public function testCannotPutInPlace(): void {
		mkdir("{$this->dir}/body_url_host.txt");
		set_error_handler(static fn (): bool => true);
		try {
			$updated = (new MapService())->updateMapFile('MapCustom', 'body_url_host', self::UPDATED);
		} finally {
			restore_error_handler();
		}
		$this->assertFalse($updated);
		$this->assertSame(['body_url_host.txt'], $this->files());
	}

	public function testNoFileMode(): void {
		Config::set('MAP_FILE_PERM', null);
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('MAP_FILE_PERM is not set in config');
		(new MapService())->updateMapFile('MapCustom', 'body_url_host', self::UPDATED);
	}

	private function assertFileWritten(string $map): void {
		$file = "{$this->dir}/{$map}.txt";
		clearstatcache();
		$perms = fileperms($file);
		$this->assertNotFalse($perms);
		$this->assertSame([0640, self::MTIME], [$perms & 0777, filemtime($file)]);
		$this->assertSame(["{$map}.txt"], $this->files(), 'no temporary file left');
		$this->assertSame([], $this->log->lines);
	}
}
