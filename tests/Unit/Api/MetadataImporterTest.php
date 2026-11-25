<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The importer's parts that need no database and do not exit: its IP ACL
 and credentials from .env, a header decoded to UTF-8, the raw mail stored
 in a temporary quarantine (none without a message or a usable directory,
 and removed again when its path would not fit mail_location), and save()
 on a boot without a database, which spools (here, with the spool off, it
 fails). handle() answers and exits, and the database paths need one: the
 integration and end-to-end tests cover those.
*/

namespace Tests\Unit\Api;

use App\Api\MetadataImporterMultipartApi;
use App\Api\RqwatchApi;
use App\Models\MailLog;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Tests\Unit\UnitTestCase;

final class MetadataImporterTest extends UnitTestCase
{
	private string $root;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->root = sys_get_temp_dir() . '/rqwatch-unit-' . bin2hex(random_bytes(6));
		mkdir($this->root, 0750, true);
	}

	#[\Override]
	protected function tearDown(): void {
		self::rmTree($this->root);
		parent::tearDown();
	}

	// entries trimmed, empty ones dropped, as a list
	public function testAllowedIps(): void {
		$this->setEnv('RSPAMD_API_ACL', ' 10.0.0.1, ,127.0.0.1,, ::1 ');
		$this->assertSame(['10.0.0.1', '127.0.0.1', '::1'], $this->call('getAllowedIps'));
		$this->setEnv('RSPAMD_API_ACL', null);
		$this->assertSame([], $this->call('getAllowedIps'));
	}

	public function testAuthCredentials(): void {
		$this->setEnv('RSPAMD_API_USER', 'rspamd');
		$this->setEnv('RSPAMD_API_PASS', 'secret');
		$this->assertSame(['rspamd', 'secret'], $this->call('getAuthCredentials'));
		$this->setEnv('RSPAMD_API_USER', null);
		$this->setEnv('RSPAMD_API_PASS', null);
		$this->assertSame([null, null], $this->call('getAuthCredentials'));
	}

	/** @return array<string, array{string|false, list<string>, string|false}> */
	public static function headers(): array {
		return [
			'absent: false' => [false, ['iso-8859-7'], false],
			'UTF-8 as is' => ['é', ['iso-8859-7'], 'é'],
			'8-bit: a declared charset first' => ["\xE1", ['iso-8859-7'], 'α'],
			'8-bit: else the fallback' => ["\xE1", [], 'á'],
		];
	}

	/** @param list<string> $charsets */
	#[DataProvider('headers')]
	public function testHeaderToUtf8(string|false $value, array $charsets, string|false $utf8): void {
		$this->assertSame($utf8, (new ReflectionMethod(MetadataImporterMultipartApi::class, 'headerToUtf8'))
			->invoke(null, $value, $charsets, 'iso-8859-1'));
	}

	public function testStoreRawMail(): void {
		$q = $this->quarantine($this->root . '/q');
		$location = $this->call('storeRawMail', 'Q1', "Subject: x\r\n\r\nbody");
		$this->assertIsString($location);
		$this->assertMatchesRegularExpression('#^' . preg_quote($q, '#') . '/\d{4}-\d{2}-\d{2}/Q1/mail\.eml$#', $location);
		$this->assertSame("Subject: x\r\n\r\nbody", file_get_contents($location));
		$this->assertSame(["info Q1 stored in quarantine: {$location}"], $this->log->lines);
	}

	// Helper::store_raw_mail() refuses it as well: the importer says why first
	public function testStoreRawMailNoMessage(): void {
		$this->quarantine($this->root . '/q');
		$this->assertNull($this->call('storeRawMail', 'Q1', ''));
		$this->assertSame(['error [MetadataImporter] Q1: to be stored in quarantine, but there is no message'], $this->log->lines);
	}

	public function testStoreRawMailFails(): void {
		$this->setEnv('QUARANTINE_DIR', $this->root . '/missing');
		$this->assertNull($this->call('storeRawMail', 'Q1', 'body'));
		$this->assertSame([
			"error {$this->root}/missing does not exist",
			'error Error storing Q1 in quarantine. Check PHP logs',
			'error Error storing Q1 in quarantine. Check PHP logs',
		], $this->log->lines);
	}

	/** @return array<string, array{int, bool}> */
	public static function locationLengths(): array {
		$limit = MailLog::FIELD_LIMITS['mail_location'];
		return [
			'at the column limit: stored' => [$limit, true],
			'a byte over: removed' => [$limit + 1, false],
		];
	}

	// the column would cut a longer path: the row would point nowhere
	#[DataProvider('locationLengths')]
	public function testStoreRawMailPathLength(int $length, bool $stored): void {
		// a location is the quarantine, '/', the date, '/Q1/mail.eml'
		$q = $this->root . '/';
		$q = $this->quarantine($q . str_repeat('p', $length - strlen($q) - strlen('/YYYY-MM-DD/Q1/mail.eml')));

		$location = $this->call('storeRawMail', 'Q1', 'body');

		if ($stored) {
			$this->assertIsString($location);
			$this->assertSame($length, strlen($location));
			$this->assertFileExists($location);
			$this->assertSame(["info Q1 stored in quarantine: {$location}"], $this->log->lines);
		} else {
			$this->assertNull($location);
			$this->assertSame([], glob("{$q}/*/Q1"), 'the stored mail is removed again');
			$this->assertCount(1, $this->log->lines);
			$this->assertMatchesRegularExpression('#^error \[MetadataImporter\] Q1: quarantine path longer than '
				. MailLog::FIELD_LIMITS['mail_location'] . ' bytes, not stored: ' . preg_quote($q, '#') . '/\d{4}-\d{2}-\d{2}/Q1/mail\.eml$#',
				implode("\n", $this->log->lines));
		}
	}

	// a boot without a database spools without trying it; the spool is off here
	public function testSaveWithoutDatabase(): void {
		$this->assertSame([null, false, 'Database unavailable and spool failed'], $this->call('save', ['qid' => 'Q1'], ['a@example.org'], 'Q1'));
		$this->assertSame([
			'critical [MetadataImporter] Q1 no database connection, spooling',
			'critical Q1 no database connection, spooling',
		], $this->log->lines);
	}

	private function quarantine(string $dir): string {
		mkdir($dir, 0750, true);
		$this->setEnv('QUARANTINE_DIR', $dir);
		return $dir;
	}

	private function call(string $method, mixed ...$args): mixed {
		$api = (new ReflectionClass(MetadataImporterMultipartApi::class))->newInstanceWithoutConstructor();
		(new ReflectionProperty(RqwatchApi::class, 'fileLogger'))->setValue($api, $this->log);
		(new ReflectionProperty(RqwatchApi::class, 'syslogLogger'))->setValue($api, $this->log);
		(new ReflectionProperty(RqwatchApi::class, 'logPrefix'))->setValue($api, 'MetadataImporter');
		return (new ReflectionMethod($api, $method))->invoke($api, ...$args);
	}

	private static function rmTree(string $path): void {
		if (is_link($path) || is_file($path)) {
			unlink($path);
			return;
		}
		if (!is_dir($path)) {
			return;
		}
		$entries = scandir($path);
		if ($entries === false) {
			throw new RuntimeException("Cannot read {$path}");
		}
		foreach (array_diff($entries, ['.', '..']) as $entry) {
			self::rmTree("{$path}/{$entry}");
		}
		rmdir($path);
	}
}
