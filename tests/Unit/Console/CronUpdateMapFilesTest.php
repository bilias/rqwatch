<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 cron:updatemapfiles decides per map, without the database, whether its
 file in MAP_DIR needs writing: when there is none, it is empty, its first
 line is not "# Last-Modified: <date> GMT", that date cannot be read
 (logged), or the map changed after it. The date is GMT, compared in the
 local time zone with the last activity, which is local. Its one option,
 -m, may name a map.
*/

namespace Tests\Unit\Console;

use App\Configuration\Config;
use App\Console\CronUpdateMapFiles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use ReflectionMethod;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Tests\Unit\UnitTestCase;

final class CronUpdateMapFilesTest extends UnitTestCase
{
	private const string HEADER = "# Last-Modified: Thu, 26 Nov 2026 10:00:00 GMT\n";

	// the header's time, 2026-11-26 10:00:00 GMT
	private const int WRITTEN = 1795687200;

	private string $dir = '';

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/rqwatch-um-' . bin2hex(random_bytes(4));
		mkdir($this->dir);
		Config::set('MAP_DIR', $this->dir);
	}

	#[\Override]
	protected function tearDown(): void {
		@unlink("{$this->dir}/whitelist.txt");
		@rmdir($this->dir);
		parent::tearDown();
	}

	private static function needsUpdate(string $map, ?string $lastActivity = null): bool {
		$method = new ReflectionMethod(CronUpdateMapFiles::class, 'mapFileNeedsUpdate');
		/** @var bool */
		return $lastActivity === null ? $method->invoke(new CronUpdateMapFiles(), $map) : $method->invoke(new CronUpdateMapFiles(), $map, $lastActivity);
	}

	/** @return list<array{string, string|null, bool, bool, mixed}> name, shortcut, takes a value, needs one, default */
	private static function options(Command $command): array {
		return array_values(array_map(
			fn (InputOption $o): array => [$o->getName(), $o->getShortcut(), $o->acceptValue(), $o->isValueRequired(), $o->getDefault()],
			$command->getDefinition()->getOptions()
		));
	}

	public function testOptions(): void {
		$this->assertSame([['map', 'm', true, false, null]], self::options(new CronUpdateMapFiles()));
	}

	// the last activity, in the process's own time zone, $seconds after the header's time
	private static function activity(int $seconds): string {
		return date('Y-m-d H:i:s', self::WRITTEN + $seconds);
	}

	/** @return array<string, array{?string, int, bool}> the file's text (null: none), the last activity after the header's time, whether to write it */
	public static function files(): array {
		return [
			'no file' => [null, -3600, true],
			'empty' => ['', -3600, true],
			'no header' => ["example.org\n", -3600, true],
			'a header later in the file' => ["example.org\n" . self::HEADER, -3600, true],
			'changed since' => [self::HEADER . "example.org\n", 1, true],
			'not changed since' => [self::HEADER . "example.org\n", -1, false],
			'changed as written' => [self::HEADER . "example.org\n", 0, false],
			'a header alone, no line end' => [rtrim(self::HEADER), -3600, false],
			'a header with blanks around' => [' ' . rtrim(self::HEADER) . " \r\n", -3600, false],
			'a header with text before it' => ['x' . self::HEADER, -3600, true],
		];
	}

	#[DataProvider('files')]
	public function testNeedsUpdate(?string $text, int $after, bool $write): void {
		if ($text !== null) {
			file_put_contents("{$this->dir}/whitelist.txt", $text);
		}
		$this->assertSame($write, self::needsUpdate('whitelist', self::activity($after)));
		$this->assertSame([], $this->log->lines);
	}

	// no activity known: an up-to-date file is kept
	public function testNoActivity(): void {
		file_put_contents("{$this->dir}/whitelist.txt", self::HEADER);
		$this->assertFalse(self::needsUpdate('whitelist'));
	}

	public function testMapDirWithASlash(): void {
		Config::set('MAP_DIR', "{$this->dir}/");
		file_put_contents("{$this->dir}/whitelist.txt", self::HEADER);
		$this->assertFalse(self::needsUpdate('whitelist', self::activity(-3600)));
	}

	public function testUnreadableDate(): void {
		file_put_contents("{$this->dir}/whitelist.txt", "# Last-Modified: someday\n");
		$this->assertTrue(self::needsUpdate('whitelist', self::activity(-3600)));
		$this->assertCount(1, $this->log->lines);
		$this->assertStringStartsWith("warning cron:updatemapfiles Map file 'whitelist': cannot read Last-Modified header (", $this->log->lines[0]);
		$this->assertStringEndsWith('); regenerating', $this->log->lines[0]);
	}

	// 10:00 GMT is 12:00 in Athens, past a local 11:00
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testLocalTime(): void {
		date_default_timezone_set('Europe/Athens');
		file_put_contents("{$this->dir}/whitelist.txt", self::HEADER);
		$this->assertSame([false, true], [self::needsUpdate('whitelist', '2026-11-26 11:00:00'), self::needsUpdate('whitelist', '2026-11-26 12:00:01')]);
	}
}
