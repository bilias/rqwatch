<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Quarantine files: where store_raw_mail() writes, and what
 deleteDirectory() refuses to remove. Runs in a temporary directory.
*/

namespace Tests\Unit\Helpers;

use App\Utils\Helper;
use PHPUnit\Framework\Attributes\RequiresFunction;
use Tests\Unit\UnitTestCase;

final class QuarantineFileTest extends UnitTestCase
{
	private string $root;
	private string $q;
	private ?string $savedEnv;

	protected function setUp(): void {
		parent::setUp();
		$this->root = sys_get_temp_dir() . '/rqwatch-unit-' . bin2hex(random_bytes(6));
		$this->q = $this->root . '/q';
		mkdir($this->q, 0750, true);
		$this->savedEnv = $_ENV['QUARANTINE_DIR'] ?? null;
		$_ENV['QUARANTINE_DIR'] = $this->q;
	}

	protected function tearDown(): void {
		if ($this->savedEnv === null) {
			unset($_ENV['QUARANTINE_DIR']);
		} else {
			$_ENV['QUARANTINE_DIR'] = $this->savedEnv;
		}
		self::rmTree($this->root);
		parent::tearDown();
	}

	private static function rmTree(string $path): void {
		if (is_link($path) || is_file($path) || (file_exists($path) && !is_dir($path))) {
			unlink($path);
			return;
		}
		if (!is_dir($path)) {
			return;
		}
		foreach (array_diff(scandir($path), ['.', '..']) as $entry) {
			self::rmTree("{$path}/{$entry}");
		}
		rmdir($path);
	}

	private function dayDir(): string {
		return preg_quote($this->q, '#') . '/\d{4}-\d{2}-\d{2}';
	}

	public function testStoresUnderDateAndQid(): void {
		$file = Helper::store_raw_mail($this->q, '4C1012A4C8D', "Subject: x\r\n\r\nbody");
		$this->assertMatchesRegularExpression('#^' . $this->dayDir() . '/4C1012A4C8D/mail\.eml$#', (string) $file);
		$this->assertSame("Subject: x\r\n\r\nbody", file_get_contents($file));
	}

	public function testTraversalQidStaysInside(): void {
		$file = Helper::store_raw_mail($this->q, '../../escaped', 'raw');
		$this->assertMatchesRegularExpression('#^' . $this->dayDir() . '/invalid/\w+/mail\.eml$#', (string) $file);
		$this->assertFileDoesNotExist($this->root . '/escaped');
		$this->assertLogged('QID invalid format: ../../escaped');
	}

	public function testTrailingNewlineQidIsInvalid(): void {
		$file = Helper::store_raw_mail($this->q, "ABC\n", 'raw');
		$this->assertMatchesRegularExpression('#^' . $this->dayDir() . '/invalid/\w+/mail\.eml$#', (string) $file);
	}

	public function testUnknownQid(): void {
		foreach (['unknown', ''] as $qid) {
			$file = Helper::store_raw_mail($this->q, $qid, 'raw');
			$this->assertMatchesRegularExpression('#^' . $this->dayDir() . '/unknown/\w+/mail\.eml$#', (string) $file);
		}
	}

	public function testRefusals(): void {
		$this->assertNull(Helper::store_raw_mail($this->root . '/missing', 'ABC', 'raw'));
		$this->assertLogged('missing does not exist');
		$this->assertNull(Helper::store_raw_mail($this->q, 'ABC', ''));
		$this->assertLogged('No input received for ABC');
	}

	public function testDiscardRemovesQidDirOnly(): void {
		$file = Helper::store_raw_mail($this->q, 'ABC', 'raw');
		$this->assertTrue(Helper::discard_raw_mail($file));
		$this->assertDirectoryDoesNotExist(dirname($file));
		$this->assertDirectoryExists(dirname($file, 2));
		$this->assertFalse(Helper::discard_raw_mail(null));
		$this->assertFalse(Helper::discard_raw_mail(''));
	}

	public function testSiblingPrefixRefused(): void {
		mkdir($this->root . '/q-backup/x', 0750, true);
		$this->assertFalse(Helper::deleteDirectory($this->root . '/q-backup/x'));
		$this->assertDirectoryExists($this->root . '/q-backup/x');
		$this->assertLogged('is not inside QUARANTINE_DIR');
	}

	public function testTraversalRefused(): void {
		mkdir($this->root . '/outside', 0750);
		$this->assertFalse(Helper::deleteDirectory($this->q . '/../outside'));
		$this->assertDirectoryExists($this->root . '/outside');
	}

	public function testQuarantineDirItselfRefused(): void {
		$this->assertFalse(Helper::deleteDirectory($this->q));
		$this->assertDirectoryExists($this->q);
	}

	public function testSymlinksUnlinkedNotFollowed(): void {
		mkdir($this->root . '/outside', 0750);
		file_put_contents($this->root . '/outside/keep', 'x');
		mkdir($this->q . '/day/qid', 0750, true);
		symlink($this->root . '/outside', $this->q . '/day/qid/link');
		$this->assertTrue(Helper::deleteDirectory($this->q . '/day/qid'));
		$this->assertDirectoryDoesNotExist($this->q . '/day/qid');
		$this->assertFileExists($this->root . '/outside/keep');
	}

	public function testSymlinkOutRefused(): void {
		mkdir($this->root . '/outside', 0750);
		symlink($this->root . '/outside', $this->q . '/link');
		$this->assertFalse(Helper::deleteDirectory($this->q . '/link'));
		$this->assertDirectoryExists($this->root . '/outside');
	}

	#[RequiresFunction('posix_mkfifo')]
	public function testUnknownFileTypeFailsClosed(): void {
		mkdir($this->q . '/day/qid', 0750, true);
		posix_mkfifo($this->q . '/day/qid/fifo', 0600);
		$this->assertFalse(Helper::deleteDirectory($this->q . '/day/qid'));
		$this->assertLogged('Unknown file type in quarantine');
	}

	public function testUnsafeQuarantineDirRefused(): void {
		mkdir($this->q . '/day', 0750);
		foreach (['', '/', '  '] as $env) {
			$_ENV['QUARANTINE_DIR'] = $env;
			$this->assertFalse(Helper::deleteDirectory($this->q . '/day'));
		}
		unset($_ENV['QUARANTINE_DIR']);
		$this->assertFalse(Helper::deleteDirectory($this->q . '/day'));
		$this->assertDirectoryExists($this->q . '/day');
	}

	public function testMissingDirectory(): void {
		$this->assertFalse(Helper::deleteDirectory($this->q . '/nope'));
	}
}
