<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 cron:quarantine's work on the quarantine directories, without the
 database, in a temp QUARANTINE_DIR. The mail directories of a day are
 those store_raw_mail() makes: <qid>/ or <qid>-<hex>/, and unknown/ or
 invalid/ <hex>/, each a real directory holding only a real mail.eml. The
 prune removes the empty unknown/ and invalid/ groups of each real dated
 directory (YYYY-MM-DD, a calendar date, inside QUARANTINE_DIR), then the
 dated directory itself if empty, saying so in verbose output; it skips
 without QUARANTINE_DIR or when it is missing. Its options: -d, -l, -p and
 -s, flags.
*/

namespace Tests\Unit\Console;

use App\Console\CronQuarantine;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Tests\Unit\UnitTestCase;

final class CronQuarantineDirsTest extends UnitTestCase
{
	private string $dir = '';

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/rqwatch-cq-' . bin2hex(random_bytes(4));
		mkdir($this->dir);
		$this->setEnv('QUARANTINE_DIR', "{$this->dir}/q");
		// without a warning: a directory may be missing; one silenced with @ stays silent
		set_error_handler(fn (int $no, string $message): bool => (error_reporting() & $no) === 0 ? false : throw new \ErrorException($message, 0, $no));
	}

	#[\Override]
	protected function tearDown(): void {
		restore_error_handler();
		self::remove($this->dir);
		parent::tearDown();
	}

	private static function remove(string $path): void {
		if (is_link($path) || is_file($path)) {
			unlink($path);
			return;
		}
		if (!is_dir($path)) {
			return;
		}
		$entries = scandir($path);
		foreach ($entries === false ? [] : array_diff($entries, ['.', '..']) as $entry) {
			self::remove("{$path}/{$entry}");
		}
		rmdir($path);
	}

	// directories, a file for each name with a dot, under the temp dir
	private function make(string ...$paths): void {
		foreach ($paths as $path) {
			$full = "{$this->dir}/{$path}";
			if (str_contains(basename($path), '.')) {
				if (!is_dir(dirname($full))) {
					mkdir(dirname($full), 0777, true);
				}
				file_put_contents($full, 'mail');
			} else {
				mkdir($full, 0777, true);
			}
		}
	}

	/** @return list<array{string, string|null, bool, bool, mixed}> name, shortcut, takes a value, needs one, default */
	private static function options(Command $command): array {
		return array_values(array_map(
			fn (InputOption $o): array => [$o->getName(), $o->getShortcut(), $o->acceptValue(), $o->isValueRequired(), $o->getDefault()],
			$command->getDefinition()->getOptions()
		));
	}

	public function testOptions(): void {
		$this->assertSame([
			['delete', 'd', false, false, false],
			['local', 'l', false, false, false],
			['prune', 'p', false, false, false],
			['show', 's', false, false, false],
		], self::options(new CronQuarantine()));
	}

	/** @return list<string> */
	private function mailDirs(string $day): array {
		/** @var list<string> */
		return (new ReflectionMethod(CronQuarantine::class, 'mailDirs'))->invoke(new CronQuarantine(), "{$this->dir}/{$day}");
	}

	public function testMailDirs(): void {
		$d = 'q/2026-01-02';
		$this->make(
			"{$d}/Q1/mail.eml", "{$d}/Q2-0abc12/mail.eml", "{$d}/unknown/0a1b/mail.eml", "{$d}/invalid/ff00/mail.eml",
			// more than the mail, or none
			"{$d}/Q3/mail.eml", "{$d}/Q3/note.txt", "{$d}/Q4",
			// not a name store_raw_mail() gives
			"{$d}/bad_name/mail.eml", "{$d}/Q5-XYZ/mail.eml", "{$d}/unknown/xyz/mail.eml", "{$d}/unknown/AB12/mail.eml", "{$d}/unknown/0a1g/mail.eml",
			"{$d}/other/0a1b/mail.eml", "{$d}/QB\n/mail.eml", "{$d}/unknown/0a2b\n/mail.eml",
			// not a real directory with a real mail.eml
			"{$d}/Q8/mail.eml/x.eml", "{$d}/Q9.eml", "{$d}/QA", 'outside/mail.eml',
		);
		symlink("{$this->dir}/{$d}/Q1", "{$this->dir}/{$d}/Q6");
		symlink("{$this->dir}/outside/mail.eml", "{$this->dir}/{$d}/QA/mail.eml");
		$q = "{$this->dir}/{$d}";
		$this->assertSame(["{$q}/Q1", "{$q}/Q2-0abc12", "{$q}/invalid/ff00", "{$q}/unknown/0a1b"], $this->mailDirs($d));
	}

	public function testNoMailDirs(): void {
		$this->make('q/2026-01-02/unknown', 'q/2026-01-03/invalid.eml');
		$this->assertSame([[], [], []], [$this->mailDirs('q/2026-01-01'), $this->mailDirs('q/2026-01-02'), $this->mailDirs('q/2026-01-03')]);
	}

	private function prune(int $verbosity = OutputInterface::VERBOSITY_VERBOSE): string {
		$output = new BufferedOutput($verbosity);
		(new ReflectionMethod(CronQuarantine::class, 'pruneEmptyQuarantineDateDirs'))->invoke(new CronQuarantine(), $output);
		return $output->fetch();
	}

	public function testPrune(): void {
		$this->make(
			// pruned
			'q/2026-01-02', 'q/2026-01-03/unknown', 'q/2026-01-03/invalid', 'q/2026-01-07/invalid', 'q/2026-03-01', 'q/2026-01-04/invalid',
			// kept: not empty
			'q/2026-01-04/unknown/0a1b', 'q/2026-01-05/Q1/mail.eml', 'q/2026-01-06/invalid',  'q/2026-01-06/note.txt',
			// kept: not a date directory
			'q/2026-02-30', 'q/2026-1-02', 'q/not-a-date', 'q/2026-01-10x', 'q/x2026-01-10', 'q/12026-01-10', 'q/2026-01-11.txt',
			"q/2026-01-12\n", 'empty', 'qq', 'q/2026-01-09/real', 'q/zz-target',
		);
		// a date link: followed inside QUARANTINE_DIR only, not to a neighbour sharing its prefix
		symlink("{$this->dir}/empty", "{$this->dir}/q/2026-01-08");
		symlink("{$this->dir}/q/zz-target", "{$this->dir}/q/2026-01-14");
		symlink("{$this->dir}/qq", "{$this->dir}/q/2026-01-17");
		symlink("{$this->dir}/nowhere", "{$this->dir}/q/2026-01-13");
		symlink("{$this->dir}/q/2026-01-09/real", "{$this->dir}/q/2026-01-09/unknown");
		$q = "{$this->dir}/q";
		$this->assertSame(
			"Pruned empty quarantine date dir {$q}/2026-01-02\n"
			. "Pruned empty quarantine group dir {$q}/2026-01-03/unknown\n"
			. "Pruned empty quarantine group dir {$q}/2026-01-03/invalid\n"
			. "Pruned empty quarantine date dir {$q}/2026-01-03\n"
			. "Pruned empty quarantine group dir {$q}/2026-01-04/invalid\n"
			. "Pruned empty quarantine group dir {$q}/2026-01-06/invalid\n"
			. "Pruned empty quarantine group dir {$q}/2026-01-07/invalid\n"
			. "Pruned empty quarantine date dir {$q}/2026-01-07\n"
			. "Pruned empty quarantine date dir {$q}/zz-target\n"
			. "Pruned empty quarantine date dir {$q}/2026-03-01\n",
			$this->prune()
		);
		$left = scandir($q);
		$this->assertSame(
			['12026-01-10', '2026-01-04', '2026-01-05', '2026-01-06', '2026-01-08', '2026-01-09', '2026-01-10x', '2026-01-11.txt', "2026-01-12\n", '2026-01-13', '2026-01-14', '2026-01-17', '2026-02-30', '2026-1-02', 'not-a-date', 'x2026-01-10'],
			$left === false ? [] : array_values(array_diff($left, ['.', '..']))
		);
		$this->assertSame([true, true, true, true], [is_dir("{$q}/2026-01-04/unknown/0a1b"), is_dir("{$this->dir}/empty"), is_dir("{$this->dir}/qq"), is_dir("{$q}/2026-01-09/real")]);
	}

	// QUARANTINE_DIR given through a link, with a trailing slash; quietly
	public function testPruneThroughALink(): void {
		$this->make('q/2026-01-02');
		symlink("{$this->dir}/q", "{$this->dir}/link");
		$this->setEnv('QUARANTINE_DIR', "{$this->dir}/link/");
		$this->assertSame('', $this->prune(OutputInterface::VERBOSITY_NORMAL));
		$this->assertFalse(is_dir("{$this->dir}/q/2026-01-02"));
	}

	/** @return array<string, array{?string, string}> QUARANTINE_DIR (under the temp dir), what is said */
	public static function noRoot(): array {
		return [
			'not set' => [null, "QUARANTINE_DIR not set; skipping prune\n"],
			'empty' => ['', "QUARANTINE_DIR not set; skipping prune\n"],
			'missing' => ['{dir}/none/', "Quarantine root not found: {dir}/none; skipping prune\n"],
			'a file' => ['{dir}/file.txt', "Quarantine root not found: {dir}/file.txt; skipping prune\n"],
		];
	}

	#[DataProvider('noRoot')]
	public function testPruneWithoutRoot(?string $root, string $said): void {
		$this->make('file.txt');
		$this->setEnv('QUARANTINE_DIR', $root === null ? null : str_replace('{dir}', $this->dir, $root));
		$this->assertSame(str_replace('{dir}', $this->dir, $said), $this->prune(OutputInterface::VERBOSITY_NORMAL));
	}
}
