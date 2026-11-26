<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The importer's request, on a boot without a database, so every mail goes
 to the spool (Redis: a stub client). A request that is not multipart, or
 without metadata that decodes to an object, is refused 400, as is one
 without a qid, a score and an action. Otherwise the mail record is built
 from the metadata and the uploaded message and spooled (200), its raw
 file kept in the quarantine for the replay. With the spool off the mail
 is lost: 500, and its raw file removed again.
*/

namespace Tests\Unit\Api;

use App\Api\MetadataImporterMultipartApi;
use App\Configuration\Config;
use App\Core\App;
use App\Core\AppContainer;
use App\Core\Cache\RedisCache;
use App\Core\Database\MigrationStatus;
use App\Inventory\Migrations;
use PHPUnit\Framework\Attributes\DataProvider;
use Redis;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ImporterHandleTest extends ApiTestCase
{
	private const string MAIL = "From: =?UTF-8?B?w4lsaXNl?= <Elise@Example.org>\r\n"
		. "To: bob@example.org\r\nSubject: Invoice\r\nMessage-ID: <m1@example.org>\r\n"
		. "X-Rspamd-Queue-Id: HDRQID1\r\n\r\nbody\r\n";

	private string $root;

	private int $uploads = 0;

	/** @var list<string> payloads stored in the stub Redis */
	private array $spooled = [];

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->root = sys_get_temp_dir() . '/rqwatch-unit-' . bin2hex(random_bytes(6));
		mkdir("{$this->root}/q", 0750, true);
		$this->setEnv('QUARANTINE_DIR', "{$this->root}/q");
		$this->setEnv('MY_API_SERVER_ALIAS', 'mx1');
		$this->setEnv('REDIS_ENABLE', 'true');
		Config::set('import_spool', true);
		Config::set('import_spool_max', 10);
		Config::set('import_spool_ttl', 60);
		Config::set('import_spool_redis_key', 'rq_spool');
		Config::set('store_settings', ['add header' => true, 'reject' => true]);

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
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, null, new MigrationStatus(null, $this->log), $cache, false));
	}

	private function migrationStatus(): MigrationStatus {
		return App::migrationStatus();
	}

	#[\Override]
	protected function tearDown(): void {
		self::rmTree($this->root);
		parent::tearDown();
	}

	/** @param array<string, mixed>|null $meta null: no metadata field */
	private function import(?array $meta, ?string $mail = self::MAIL, string $contentType = 'multipart/form-data; boundary=x', string $server = 'mail3'): Response {
		$files = [];
		if ($mail !== null) {
			$path = "{$this->root}/upload-" . $this->uploads++;
			file_put_contents($path, $mail);
			$files['message'] = new UploadedFile($path, 'message.eml', 'message/rfc822', UPLOAD_ERR_OK, true);
		}
		$post = $meta === null ? [] : ['metadata' => (string) json_encode($meta)];
		$request = Request::create("/api/metadata_importer_multipart.php?server={$server}", 'POST', $post, [], $files,
			['CONTENT_TYPE' => $contentType]);
		return $this->answer(fn () => (new MetadataImporterMultipartApi($request))->handle());
	}

	/** @return array<string, mixed> the spooled mail record */
	private function record(): array {
		$this->assertCount(1, $this->spooled);
		/** @var mixed $entry */
		$entry = json_decode(implode('', $this->spooled), true);
		if (!is_array($entry) || !is_array($entry['data'] ?? null)) {
			throw new RuntimeException('not a spool entry');
		}
		/** @var array<string, mixed> $data */
		$data = $entry['data'];
		return $data;
	}

	public function testSpooled(): void {
		$meta = [
			'qid' => 'QID1', 'score' => 7.5, 'action' => 'add header', 'ip' => '192.0.2.50',
			'from' => 'Sender@Example.org', 'subject' => 'rspamd subject', 'size' => '1234',
			'rcpt' => ['Bob@Example.org ', 'carol@example.org'], 'fuzzy' => ['aa', 'bb'],
			'symbols' => ['BAYES_SPAM' => ['score' => 5.1]],
		];
		$response = $this->import($meta);
		$this->assertAnswer(200, 'Message spooled', $response);
		$this->assertSame('text/plain', $response->headers->get('Content-Type'));
		$data = $this->record();
		$this->assertSame('QID1', $data['qid']);
		$this->assertSame('mail3', $data['server']);
		$this->assertSame('Invoice', $data['subject'], 'the subject from the message');
		$this->assertSame([7.5, 'add header', '192.0.2.50'], [$data['score'], $data['action'], $data['ip']]);
		$this->assertSame('sender@example.org', $data['mail_from']);
		$this->assertSame('Élise <Elise@Example.org>', $data['mime_from']);
		$this->assertSame('bob@example.org', $data['mime_to']);
		$this->assertSame('bob@example.org, carol@example.org', $data['rcpt_to']);
		$this->assertSame('["aa","bb"]', $data['fuzzy_hashes']);
		$this->assertSame('<m1@example.org>', $data['message_id']);
		$this->assertSame(1234, $data['size']);
		$this->assertSame(0, $data['has_virus']);
		$this->assertSame(1, $data['mail_stored'], "'add header' is stored");
		$this->assertIsString($data['mail_location']);
		$this->assertStringStartsWith("{$this->root}/q/", $data['mail_location']);
		$this->assertSame(self::MAIL, file_get_contents($data['mail_location']), 'kept for the replay');
		$this->assertSame(substr(self::MAIL, 0, (int) strpos(self::MAIL, "\r\n\r\n")), $data['headers'], 'the header block, trimmed');
		$this->assertIsString($data['symbols']);
		$this->assertStringContainsString('"name":"BAYES_SPAM"', $data['symbols']);
		$this->assertSame(0, $this->logged('/no usable message part|8-bit headers decoded|exceeded max/'));
	}

	// the To header to 250 characters, "!!" at the end; a column too long is cut, logged
	public function testLongValues(): void {
		$to = str_repeat('x', 300) . '@example.org';
		$mail = str_replace('To: bob@example.org', "To: {$to}", self::MAIL);
		$this->assertAnswer(200, 'Message spooled', $this->import(['qid' => 'QID1', 'score' => 1, 'action' => 'no action'], $mail, server: 'mail3.example.org'));
		$data = $this->record();
		$this->assertSame(str_repeat('x', 248) . '!!', $data['mime_to']);
		$this->assertSame('mail3.exam', $data['server']);
		$this->assertContains('warning [MetadataImporterMultipartApi] QID1: Field server exceeded max 10, truncated.', $this->log->lines);
	}

	// unencoded 8-bit headers: decoded, and that logged
	public function testEightBitHeaders(): void {
		$mail = str_replace('Subject: Invoice', "Subject: Caf\xE9", self::MAIL);
		$this->assertAnswer(200, 'Message spooled', $this->import(['qid' => 'QID1', 'score' => 1, 'action' => 'no action'], $mail));
		$this->assertSame('Café', $this->record()['subject']);
		$this->assertContains('debug QID1: 8-bit headers decoded, declared charsets: none', $this->log->lines);
	}

	// an empty Subject header: rspamd's subject instead
	public function testEmptySubject(): void {
		$mail = str_replace('Subject: Invoice', 'Subject: ', self::MAIL);
		$this->assertAnswer(200, 'Message spooled', $this->import(['qid' => 'QID1', 'score' => 1, 'action' => 'no action', 'subject' => 'rspamd subject'], $mail));
		$this->assertSame('rspamd subject', $this->record()['subject']);
	}

	public function testOneRecipientAsText(): void {
		$this->assertAnswer(200, 'Message spooled', $this->import(['qid' => 'QID1', 'score' => 1, 'action' => 'no action', 'rcpt' => ' Dave@Example.org']));
		$this->assertSame('dave@example.org', $this->record()['rcpt_to']);
	}

	/** @return array<string, array{mixed}> */
	public static function noFuzzy(): array {
		return ['null' => [null], 'empty' => [''], 'unknown' => ['unknown']];
	}

	// no hashes, silently
	#[DataProvider('noFuzzy')]
	public function testNoFuzzy(mixed $fuzzy): void {
		$this->assertAnswer(200, 'Message spooled', $this->import(['qid' => 'QID1', 'score' => 1, 'action' => 'no action', 'fuzzy' => $fuzzy]));
		$this->assertSame('[]', $this->record()['fuzzy_hashes']);
		$this->assertSame(0, $this->logged('/fuzzy/'));
	}

	// no envelope sender: a placeholder, logged with the qid
	public function testNoSender(): void {
		$this->assertAnswer(200, 'Message spooled', $this->import(['qid' => 'QID1', 'score' => 1, 'action' => 'no action']));
		$this->assertSame('empty-mail-from@localhost', $this->record()['mail_from']);
		$index = array_search('warning Unknown envelope address, using empty-mail-from@localhost', $this->log->lines, true);
		$this->assertIsInt($index);
		$this->assertSame(['qid' => 'QID1'], $this->log->contexts[$index] ?? null);
	}

	// an action no $store_settings key can name
	public function testNoActionNotStored(): void {
		Config::set('store_settings', ['' => true]);
		$this->assertAnswer(200, 'Message spooled', $this->import(['qid' => 'QID1', 'score' => 1, 'action' => '']));
		$this->assertSame(0, $this->record()['mail_stored']);
	}

	// not an array: config.php's defaults, which store 'reject'; logged with the qid
	public function testStoreSettingsMistyped(): void {
		Config::set('store_settings', 'yes');
		$this->assertAnswer(200, 'Message spooled', $this->import(['qid' => 'QID1', 'score' => 1, 'action' => 'reject']));
		$this->assertSame(1, $this->record()['mail_stored']);
		$this->assertSame(1, $this->logged("/^error \\[MetadataImporterMultipartApi\\] QID1: store_settings .+; using config\\.php's defaults$/"));
		$this->assertSame(1, $this->logged("/^error QID1: store_settings .+; using config\\.php's defaults$/"));
	}

	/** @return array<string, array{string, ?string, bool, bool}> action, admin_notification_rcpt, migration completed, admin_notified = 0 */
	public static function adminNotified(): array {
		return [
			'reject' => ['reject', 'admin@example.org', true, true],
			'discard' => ['discard', 'admin@example.org', true, true],
			'another action' => ['add header', 'admin@example.org', true, false],
			'no recipient' => ['reject', null, true, false],
			'before the migration' => ['reject', 'admin@example.org', false, false],
		];
	}

	// only a stored mail rejected or discarded while admins are notified awaits the notice
	#[DataProvider('adminNotified')]
	public function testAdminNotified(string $action, ?string $rcpt, bool $migrated, bool $awaits): void {
		Config::set('store_settings', ['add header' => true, 'reject' => true, 'discard' => true]);
		Config::set('admin_notification_rcpt', $rcpt);
		if ($migrated) {
			$this->migrationStatus()->setMigrationState(Migrations::ADMIN_NOTIFIED, Migrations::STATUS_COMPLETED);
		}
		$this->assertAnswer(200, 'Message spooled', $this->import(['qid' => 'QID1', 'score' => 1, 'action' => $action]));
		$data = $this->record();
		$this->assertSame(1, $data['mail_stored']);
		$this->assertSame($awaits ? 0 : null, $data['admin_notified'] ?? null);
	}

	// not stored: nothing to notify about
	public function testAdminNotifiedNeedsTheFile(): void {
		Config::set('store_settings', ['reject' => false]);
		Config::set('admin_notification_rcpt', 'admin@example.org');
		$this->migrationStatus()->setMigrationState(Migrations::ADMIN_NOTIFIED, Migrations::STATUS_COMPLETED);
		$this->assertAnswer(200, 'Message spooled', $this->import(['qid' => 'QID1', 'score' => 1, 'action' => 'reject']));
		$this->assertArrayNotHasKey('admin_notified', $this->record());
	}

	// an error past the request: 500, nothing left in the quarantine
	public function testImportError(): void {
		Config::set('admin_notification_rcpt', 'admin@example.org');
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, null, null, App::cache(), false));
		$this->assertAnswer(500, 'Unexpected error', $this->import(['qid' => 'QID1', 'score' => 1, 'action' => 'reject']));
		$this->assertSame([], $this->spooled);
		$this->assertSame([], glob("{$this->root}/q/*/QID1/*"));
		$this->assertContains('critical [MetadataImporterMultipartApi] QID1 import error: RuntimeException: MigrationStatus is not available in this context.', $this->log->lines);
		$this->assertContains('critical QID1 import error: RuntimeException: MigrationStatus is not available in this context.', $this->log->lines);
	}

	/** @return array<string, array{mixed, ?string, bool}> log_to_files, its directory (a subdirectory of the test's), written */
	public static function dumps(): array {
		return [
			'on' => [true, 'dump', true],
			'off' => [false, 'dump', false],
			'no directory' => [true, null, false],
			'empty directory' => [true, '', false],
		];
	}

	// the debug dump, written beside the import
	#[DataProvider('dumps')]
	public function testLogToFiles(mixed $on, ?string $dir, bool $written): void {
		Config::set('log_to_files', $on);
		Config::set('log_to_files_dir', $dir === null || $dir === '' ? $dir : "{$this->root}/{$dir}");
		$this->assertAnswer(200, 'Message spooled', $this->import(['qid' => 'QID1', 'score' => 1, 'action' => 'no action']));
		$this->assertSame($written, is_file("{$this->root}/dump/symbols"));
	}

	// a dump that fails does not change the mail's outcome
	public function testLogToFilesFails(): void {
		Config::set('log_to_files', 'yes');
		Config::set('log_to_files_dir', "{$this->root}/dump");
		$this->assertAnswer(200, 'Message spooled', $this->import(['qid' => 'QID1', 'score' => 1, 'action' => 'no action']));
		$this->assertSame(1, $this->logged('/^error \\[MetadataImporterMultipartApi\\] QID1: log_to_files not written: log_to_files must be /'));
	}

	// unset, $store_settings stores only viruses
	public function testNotStored(): void {
		Config::set('store_settings', null);
		$this->assertAnswer(200, 'Message spooled', $this->import(['qid' => 'QID1', 'score' => 1, 'action' => 'add header']));
		$data = $this->record();
		$this->assertSame([0, null], [$data['mail_stored'], $data['mail_location']]);
		$this->assertSame([], glob("{$this->root}/q/*"));
	}

	// the qid from rspamd's header when the metadata has none fit for a path
	/** @return array<string, array{mixed}> */
	public static function badQids(): array {
		return [
			'none' => [null], 'unknown' => ['unknown'], 'not alphanumeric' => ['../x'], 'empty' => [''],
			'a line feed after' => ["QID1\n"], 'more after' => ['QID1-x'],
		];
	}

	#[DataProvider('badQids')]
	public function testQidFromHeader(mixed $qid): void {
		$this->assertAnswer(200, 'Message spooled', $this->import(['qid' => $qid, 'score' => 1, 'action' => 'no action']));
		$this->assertSame('HDRQID1', $this->record()['qid']);
	}

	/** @return array<string, array{string, string}> the header(s), the qid */
	public static function headerQids(): array {
		return [
			'not alphanumeric' => ["X-Rspamd-Queue-Id: x-y\r\n", 'unknown'],
			'repeated: the last' => ["X-Rspamd-Queue-Id: FIRST1\r\nX-Rspamd-Queue-Id: LAST1\r\n", 'LAST1'],
		];
	}

	#[DataProvider('headerQids')]
	public function testHeaderQid(string $headers, string $qid): void {
		$mail = str_replace("X-Rspamd-Queue-Id: HDRQID1\r\n", $headers, self::MAIL);
		$this->assertAnswer(200, 'Message spooled', $this->import(['score' => 1, 'action' => 'no action'], $mail));
		$this->assertSame($qid, $this->record()['qid']);
	}

	// no message: the metadata alone, logged; no headers, mail_from defaulted
	public function testWithoutMessage(): void {
		$this->assertAnswer(200, 'Message spooled', $this->import(['score' => 1, 'action' => 'no action', 'rcpt' => 'unknown'], null));
		$data = $this->record();
		$this->assertSame(['unknown', 'empty-mail-from@localhost', 'unknown', null, '', ''],
			[$data['qid'], $data['mail_from'], $data['rcpt_to'], $data['mime_from'], $data['mime_to'], $data['headers']]);
		$this->assertContains('error [MetadataImporterMultipartApi] unknown: no usable message part (Missing multipart file: message); importing the metadata without the mail', $this->log->lines);
		$this->assertContains('error unknown: no usable message part (Missing multipart file: message); importing the metadata without the mail', $this->log->lines);
	}

	public function testEmptyMessage(): void {
		$this->assertAnswer(200, 'Message spooled', $this->import(['qid' => 'QID1', 'score' => 1, 'action' => 'no action'], ''));
		$this->assertContains('error QID1: no usable message part (Empty or unreadable message content); importing the metadata without the mail', $this->log->lines);
	}

	// values of the wrong kind are logged and left out, the mail kept
	public function testOddMetadata(): void {
		$meta = ['qid' => 'QID1', 'score' => ['x'], 'action' => 'no action', 'subject' => true, 'fuzzy' => 5, 'rcpt' => ['a@example.org', ['x'], 0, ' 0 ', 7]];
		$this->assertAnswer(200, 'Message spooled', $this->import($meta));
		$data = $this->record();
		$this->assertSame([null, 'Invoice', '[]', 'a@example.org, 7'], [$data['score'], $data['subject'], $data['fuzzy_hashes'], $data['rcpt_to']]);
		foreach ([
			"warning [MetadataImporterMultipartApi] non-scalar value for metadata field 'score'; ignoring it",
			"warning [MetadataImporterMultipartApi] metadata field 'subject' is bool, not text; ignoring it",
			"warning [MetadataImporterMultipartApi] metadata field 'fuzzy' is not a list of hashes (int); ignoring it",
			"warning [MetadataImporterMultipartApi] metadata field 'rcpt' has a value of type array, not text; ignoring it",
			"warning [MetadataImporterMultipartApi] metadata field 'rcpt' has the value '0'; ignoring it",
		] as $line) {
			$this->assertContains($line, $this->log->lines);
		}
	}

	public function testSpoolOff(): void {
		Config::set('import_spool', false);
		$response = $this->import(['qid' => 'QID1', 'score' => 1, 'action' => 'reject']);
		$this->assertAnswer(500, 'Database unavailable and spool failed', $response);
		$this->assertSame([], $this->spooled);
		$this->assertSame([], glob("{$this->root}/q/*/QID1/*"), 'the raw file removed');
		$this->assertSame(1, $this->logged('/^info \[MetadataImporterMultipartApi\] QID1 removed from quarantine: /'));
		$this->assertSame(1, $this->logged('/^info QID1 removed from quarantine: /'));
		$this->assertSame(2, $this->logged('/^critical \[MetadataImporterMultipartApi\] Error storing QID1 in DB by MetadataImporterMultipartApi\. Check PHP\/rspamd logs \| /'));
	}

	/**
	 * how many log lines match $pattern
	 * @param non-empty-string $pattern
	 */
	private function logged(string $pattern): int {
		$lines = preg_grep($pattern, $this->log->lines);
		return $lines === false ? 0 : count($lines);
	}

	public function testNothingToImport(): void {
		$this->assertAnswer(400, 'qid, score and action missing', $this->import(['ip' => '192.0.2.50', 'score' => 'high']));
		$this->assertSame([], $this->spooled);
		$this->assertSame([
			'error qid, score and action missing',
			'critical [MetadataImporterMultipartApi] qid, score and action missing',
			'critical [MetadataImporterMultipartApi] qid, score and action missing',
		], $this->log->lines);
	}

	/** @return array<string, array{array<string, mixed>}> */
	public static function enough(): array {
		return ['a qid' => [['qid' => 'QID1']], 'a score' => [['score' => 0]], 'an action' => [['action' => 'no action']]];
	}

	// any one of the three is enough
	/** @param array<string, mixed> $meta */
	#[DataProvider('enough')]
	public function testEnough(array $meta): void {
		$this->assertAnswer(200, 'Message spooled', $this->import($meta));
	}

	/** @return array<string, array{int}> */
	public static function depths(): array {
		return ['512 levels' => [512], '513 levels' => [513]];
	}

	// metadata nests up to json_decode()'s 512 levels
	#[DataProvider('depths')]
	public function testDepth(int $depth): void {
		$json = '{"qid":"QID1","x":' . str_repeat('[', $depth - 2) . str_repeat(']', $depth - 2) . '}';
		$request = Request::create('/api/metadata_importer_multipart.php', 'POST', ['metadata' => $json], [], [],
			['CONTENT_TYPE' => 'multipart/form-data; boundary=x']);
		$response = $this->answer(fn () => (new MetadataImporterMultipartApi($request))->handle());
		if ($depth <= 512) {
			$this->assertAnswer(200, 'Message spooled', $response);
		} else {
			$this->assertAnswer(400, 'Invalid request (parse error)', $response);
			$this->assertSame('error [MetadataImporterMultipartApi] Request parse error: Maximum stack depth exceeded', $this->log->lines[0] ?? null);
		}
	}

	/** @return array<string, array{?array<string, mixed>, string, string}> metadata, content type, the reason */
	public static function parseErrors(): array {
		return [
			'not multipart' => [['qid' => 'QID1'], 'application/json', 'Missing multipart/form-data content type'],
			'no metadata' => [null, 'multipart/form-data; boundary=x', 'Missing multipart field: metadata'],
		];
	}

	/** @param array<string, mixed>|null $meta */
	#[DataProvider('parseErrors')]
	public function testParseError(?array $meta, string $contentType, string $reason): void {
		$this->assertAnswer(400, 'Invalid request (parse error)', $this->import($meta, self::MAIL, $contentType));
		$this->assertSame([
			"error [MetadataImporterMultipartApi] Request parse error: {$reason}",
			"critical [MetadataImporterMultipartApi] {$reason}",
			"critical [MetadataImporterMultipartApi] {$reason}",
		], $this->log->lines);
	}

	/** @return array<string, array{string, string}> */
	public static function badJson(): array {
		return ['not JSON' => ['{', 'Syntax error'], 'not an object' => ['"x"', 'Invalid metadata JSON']];
	}

	#[DataProvider('badJson')]
	public function testBadMetadata(string $json, string $reason): void {
		$request = Request::create('/api/metadata_importer_multipart.php', 'POST', ['metadata' => $json], [], [],
			['CONTENT_TYPE' => 'multipart/form-data; boundary=x']);
		$this->assertAnswer(400, 'Invalid request (parse error)', $this->answer(fn () => (new MetadataImporterMultipartApi($request))->handle()));
		$this->assertSame("error [MetadataImporterMultipartApi] Request parse error: {$reason}", $this->log->lines[0] ?? null);
	}

	private static function rmTree(string $path): void {
		if (is_dir($path) && !is_link($path)) {
			$entries = scandir($path);
			if ($entries === false) {
				throw new RuntimeException("Cannot read {$path}");
			}
			foreach (array_diff($entries, ['.', '..']) as $entry) {
				self::rmTree("{$path}/{$entry}");
			}
			rmdir($path);
		} elseif (file_exists($path) || is_link($path)) {
			unlink($path);
		}
	}
}
