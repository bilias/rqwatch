<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The importer with a database (RecordingDatabase): the mail is saved, and
 syslog says so with its score, action and id. A database that cannot take
 any mail (a lost connection) has the mail spooled, logged critical with
 the driver's error. One that rejects this mail's data (an invalid string)
 has it saved again without its free text, logged.
*/

namespace Tests\Unit\Api;

use App\Api\MetadataImporterMultipartApi;
use App\Configuration\Config;
use App\Core\App;
use App\Core\AppContainer;
use App\Core\Cache\RedisCache;
use App\Core\Database\MigrationStatus;
use Illuminate\Database\QueryException;
use PDO;
use PDOException;
use Redis;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\Unit\RecordingDatabase;

final class ImporterDatabaseTest extends ApiTestCase
{
	private const string MAIL = "From: a@example.org\r\nTo: bob@example.org\r\nSubject: Invoice\r\nMessage-ID: <m1@example.org>\r\n\r\nbody\r\n";

	// QueryException's message ends in the statement
	private const string SQL = ' (Connection: default, SQL: insert into `mail_logs`)';

	private RecordingDatabase $db;

	private string $root;

	/** @var list<string> payloads stored in the stub Redis */
	private array $spooled = [];

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->root = sys_get_temp_dir() . '/rqwatch-idb-' . bin2hex(random_bytes(6));
		mkdir("{$this->root}/q", 0750, true);
		$this->setEnv('QUARANTINE_DIR', "{$this->root}/q");
		$this->setEnv('MY_API_SERVER_ALIAS', 'mx1');
		$this->setEnv('REDIS_ENABLE', 'true');
		Config::set('import_spool', true);
		Config::set('import_spool_max', 10);
		Config::set('import_spool_ttl', 60);
		Config::set('import_spool_redis_key', 'rq_spool');
		Config::set('store_settings', ['add header' => true]);
		$redis = $this->createStub(Redis::class);
		$redis->method('get')->willReturn(false);
		$redis->method('set')->willReturnCallback(function (string $key, mixed $value): bool {
			$this->spooled[] = is_string($value) ? $value : '';
			return true;
		});
		$redis->method('incr')->willReturn(1);
		$redis->method('expire')->willReturn(true);
		$cache = new RedisCache($this->log);
		(new ReflectionProperty(RedisCache::class, 'client'))->setValue($cache, $redis);
		$this->db = new RecordingDatabase($this->createStub(PDO::class));
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, $this->db->capsule, new MigrationStatus(null, $this->log), $cache, true));
	}

	#[\Override]
	protected function tearDown(): void {
		RecordingDatabase::forget();
		self::rmTree($this->root);
		parent::tearDown();
	}

	private static function rmTree(string $path): void {
		if (is_dir($path) && !is_link($path)) {
			$entries = scandir($path);
			foreach ($entries === false ? [] : array_diff($entries, ['.', '..']) as $entry) {
				self::rmTree("{$path}/{$entry}");
			}
			rmdir($path);
		} elseif (file_exists($path) || is_link($path)) {
			unlink($path);
		}
	}

	private function import(): Response {
		$path = "{$this->root}/upload";
		file_put_contents($path, self::MAIL);
		$meta = ['qid' => 'QID1', 'score' => 7.5, 'action' => 'add header', 'ip' => '192.0.2.50', 'from' => 'a@example.org', 'rcpt' => ['bob@example.org']];
		$request = Request::create('/api/metadata_importer_multipart.php?server=mail3', 'POST', ['metadata' => (string) json_encode($meta)], [],
			['message' => new UploadedFile($path, 'message.eml', 'message/rfc822', UPLOAD_ERR_OK, true)], ['CONTENT_TYPE' => 'multipart/form-data; boundary=x']);
		return $this->answer(fn () => (new MetadataImporterMultipartApi($request))->handle());
	}

	/** @param array{string, int, string} $errorInfo */
	private static function failure(array $errorInfo): QueryException {
		$pdo = new PDOException($errorInfo[2]);
		$pdo->errorInfo = $errorInfo;
		return new QueryException('default', 'insert into `mail_logs`', [], $pdo);
	}

	public function testSaved(): void {
		$this->assertAnswer(200, 'Message saved', $this->import());
		$this->assertSame(['QID1'], array_column($this->db->rows('mail_logs'), 'qid'));
		$this->assertSame([], $this->spooled);
		$saved = array_values(array_filter($this->log->lines, fn (string $line): bool => str_starts_with($line, "info QID1 score: 7.50 'add header' saved in DB [id: 101] by MetadataImporterMultipartApi | ")));
		$this->assertCount(1, $saved);
	}

	// the database down: spooled, the driver's error logged
	public function testDatabaseGone(): void {
		$this->db->failInsert['QID1'] = self::failure(['HY000', 2006, 'MySQL server has gone away']);
		$this->assertAnswer(200, 'Message spooled', $this->import());
		$this->assertCount(1, $this->spooled);
		$this->assertSame([], $this->db->rows('mail_logs'));
		$this->assertContains('critical [MetadataImporterMultipartApi] QID1 DB error: SQLSTATE HY000, error 2006: MySQL server has gone away' . self::SQL, $this->log->lines);
		$this->assertContains('critical QID1 DB error: SQLSTATE HY000, error 2006: MySQL server has gone away' . self::SQL, $this->log->lines);
	}

	// this mail's data refused: saved again without its free text
	public function testDataRejected(): void {
		$this->db->failInsert['QID1'] = self::failure(['22007', 1366, 'Incorrect string value']);
		$this->assertAnswer(200, 'Message saved', $this->import());
		$rows = $this->db->rows('mail_logs');
		$this->assertCount(1, $rows);
		$saved = $rows[0] + ($this->db->rows('mail_log_data')[0] ?? []);
		foreach (['subject', 'mime_from', 'mime_to', 'message_id', 'headers', 'symbols', 'fuzzy_hashes'] as $column) {
			$this->assertArrayHasKey($column, $saved);
			$this->assertNull($saved[$column], $column);
		}
		$cut = 'QID1 the database rejected the mail: SQLSTATE 22007, error 1366: Incorrect string value' . self::SQL . '; saving it without subject, mime_from, mime_to, message_id, headers, symbols, fuzzy_hashes';
		$this->assertContains("critical [MetadataImporterMultipartApi] {$cut}", $this->log->lines);
		$this->assertContains("critical {$cut}", $this->log->lines);
		$this->assertContains('warning [MetadataImporterMultipartApi] QID1 saved without its free text [id: 101]', $this->log->lines);
	}
}
