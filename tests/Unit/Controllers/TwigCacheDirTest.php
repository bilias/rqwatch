<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The directory ViewController gives Twig as its cache, on directories of
 the test's own (the web's is a constant path in the checkout): one that
 is there and writable is used; a missing one is made, with its parents,
 for its owner and group only (0750); one that cannot be made, or cannot be
 written to, is logged and the views run uncached (false).
*/

namespace Tests\Unit\Controllers;

use App\Controllers\ViewController;
use ReflectionMethod;
use Tests\Unit\UnitTestCase;

final class TwigCacheDirTest extends UnitTestCase
{
	private string $dir = '';

	private int $umask = 0;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/rqwatch-twig-' . bin2hex(random_bytes(4));
		mkdir($this->dir);
		// the mode asked for is the mode made
		$this->umask = umask(0);
	}

	#[\Override]
	protected function tearDown(): void {
		umask($this->umask);
		foreach (["{$this->dir}/cache/twig", "{$this->dir}/cache", "{$this->dir}/locked"] as $made) {
			if (is_dir($made)) {
				chmod($made, 0700);
				rmdir($made);
			}
		}
		if (is_file("{$this->dir}/file")) {
			unlink("{$this->dir}/file");
		}
		rmdir($this->dir);
		parent::tearDown();
	}

	private static function cacheDir(string $cache): mixed {
		return (new ReflectionMethod(ViewController::class, 'twigCacheDir'))->invoke(null, $cache);
	}

	private static function mode(string $path): int {
		$perms = fileperms($path);
		self::assertIsInt($perms);
		return $perms & 0777;
	}

	public function testUsedAsItIs(): void {
		$this->assertSame($this->dir, self::cacheDir($this->dir));
		$this->assertSame([], $this->log->lines);
	}

	public function testMadeWhenMissing(): void {
		$cache = "{$this->dir}/cache/twig";
		$this->assertSame($cache, self::cacheDir($cache));
		$this->assertSame(0750, self::mode($cache));
		$this->assertSame(0750, self::mode("{$this->dir}/cache"));
		$this->assertSame([], $this->log->lines);
	}

	// under a file no directory can be made
	public function testNotCreatable(): void {
		touch("{$this->dir}/file");
		$cache = "{$this->dir}/file/twig";
		$this->assertFalse(self::cacheDir($cache));
		$this->assertSame(["warning Twig cache dir not creatable: {$cache}; running uncached"], $this->log->lines);
	}

	public function testNotWritable(): void {
		if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
			$this->markTestSkipped('root writes anywhere');
		}
		$cache = "{$this->dir}/locked";
		mkdir($cache, 0500);
		$this->assertFalse(self::cacheDir($cache));
		$this->assertSame(["warning Twig cache dir not writable: {$cache}; running uncached"], $this->log->lines);
	}
}
