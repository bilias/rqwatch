<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The importer on malformed or failing input, without rspamd: requests
 posted as metadata_exporter would. The metadata is kept wherever it can
 be, and every loss is logged:
 - a fuzzy value that is not a list of hashes is stored as none, a text
   field holding true is treated as absent, and a recipient that is not
   text, or is '0', is dropped and the others kept;
 - without a usable message part the metadata is still imported;
 - unusable metadata is refused;
 - a mail the database rejects is saved without its free text, and one it
   rejects even so is answered 500 with its raw file removed, so none is
   left that no row points to;
 - a mistyped store_settings, mail_fallback_charset or log_to_files falls
   back (config.php's defaults, Windows-1252, no dump) and is logged.
 The log names the mail by qid, and a database error in full (SQLSTATE,
 code and the failed SQL): never the metadata itself. A trigger stands in
 for the database rejecting a mail.
*/

namespace Tests\Integration\Import;

use CURLStringFile;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Integration\IntegrationTestCase;

final class MetadataTest extends IntegrationTestCase
{
	// imported mails' qids start with this
	private const QID = 'QIDMETA';

	// a subject the trigger rejects, and a qid it rejects even without one
	private const REJECTED_SUBJECT = 'SUBJ-META-REJECTED';
	private const REJECTED_QID = self::QID . 'REJECTED';

	#[\Override]
	protected function tearDown(): void {
		self::db()->exec('DROP TRIGGER IF EXISTS rqwatch_test_reject');
		// the imported mails' child rows cascade
		self::db()->exec("DELETE FROM mail_logs WHERE qid LIKE '" . self::QID . "%'");
		parent::tearDown();
	}

	public function testFuzzyNotAListStoredAsNone(): void {
		$from = self::logSize();
		$response = self::post(self::meta(1, ['fuzzy' => 'abc']), 'message');
		$this->assertSame([200, 'Message saved'], $response);
		$this->assertSame('[]', self::valueString(
			'SELECT d.fuzzy_hashes FROM mail_log_data d JOIN mail_logs m ON m.id = d.mail_log_id WHERE m.qid = ?', self::QID . '1'));
		$this->assertStringContainsString("metadata field 'fuzzy' is not a list of hashes (string); ignoring it", self::logSince($from));
	}

	public function testTextFieldTrueIgnored(): void {
		$from = self::logSize();
		$response = self::post(self::meta(2, ['from' => true]), 'message');
		$this->assertSame([200, 'Message saved'], $response);
		$this->assertSame('empty-mail-from@localhost', self::valueString('SELECT mail_from FROM mail_logs WHERE qid = ?', self::QID . '2'));
		$this->assertStringContainsString("metadata field 'from' is bool, not text; ignoring it", self::logSince($from));
	}

	public function testRcptValuesNotTextDropped(): void {
		$from = self::logSize();
		$response = self::post(self::meta(11, ['rcpt' => ['Meta-A@example.org', ['meta-b@example.org'], true, 0]]), 'message');
		$this->assertSame([200, 'Message saved'], $response);
		$this->assertSame('meta-a@example.org', self::valueString(
			'SELECT GROUP_CONCAT(r.recipient_email) FROM mail_log_recipients r JOIN mail_logs m ON m.id = r.mail_log_id WHERE m.qid = ?', self::QID . '11'));
		$this->assertSame('meta-a@example.org', self::valueString('SELECT rcpt_to FROM mail_logs WHERE qid = ?', self::QID . '11'));
		$log = self::logSince($from);
		$this->assertStringContainsString("metadata field 'rcpt' has a value of type array, not text; ignoring it", $log);
		$this->assertStringContainsString("metadata field 'rcpt' has a value of type bool, not text; ignoring it", $log);
		$this->assertStringContainsString("metadata field 'rcpt' has the value '0'; ignoring it", $log);
	}

	public function testRcptNotAListOrTextIgnored(): void {
		$from = self::logSize();
		$response = self::post(self::meta(12, ['rcpt' => true]), 'message');
		$this->assertSame([200, 'Message saved'], $response);
		$this->assertSame('unknown', self::valueString('SELECT rcpt_to FROM mail_logs WHERE qid = ?', self::QID . '12'));
		$this->assertStringContainsString("metadata field 'rcpt' has a value of type bool, not text; ignoring it", self::logSince($from));
	}

	/** @return array<string, array{string}> */
	public static function messageMissingCases(): array {
		return [
			'no message part' => ['other'],
			'message[]' => ['message[0]'],
		];
	}

	#[DataProvider('messageMissingCases')]
	public function testMetadataImportedWithoutMessage(string $field): void {
		$from = self::logSize();
		$response = self::post(self::meta(3, ['action' => 'reject']), $field);
		$this->assertSame([200, 'Message saved'], $response);
		$this->assertSame(0, self::valueInt('SELECT mail_stored FROM mail_logs WHERE qid = ?', self::QID . '3'));
		$this->assertNull(self::value('SELECT mail_location FROM mail_logs WHERE qid = ?', self::QID . '3'));
		$log = self::logSince($from);
		$this->assertStringContainsString(self::QID . '3: no usable message part (Missing multipart file: message); importing the metadata without the mail', $log);
		$this->assertStringContainsString(self::QID . '3: to be stored in quarantine, but there is no message', $log);
	}

	public function testInvalidMetadataRefusedAndLogged(): void {
		$from = self::logSize();
		[$status] = self::postRaw('{"qid": "' . self::QID . '4", broken', 'message');
		$this->assertSame(400, $status);
		$log = self::logSince($from);
		$this->assertStringContainsString('Request parse error', $log);
		$this->assertStringNotContainsString(self::QID . '4', $log, 'the metadata text is not logged');
	}

	// the free text is left out to keep the rest of the record
	public function testRejectedMailSavedWithoutFreeText(): void {
		self::rejectingTrigger();
		$from = self::logSize();
		$response = self::post(self::meta(5, ['action' => 'reject']), 'message', self::REJECTED_SUBJECT);
		$this->assertSame([200, 'Message saved'], $response);
		$this->assertNull(self::value('SELECT subject FROM mail_logs WHERE qid = ?', self::QID . '5'));
		$this->assertSame(1, self::valueInt('SELECT mail_stored FROM mail_logs WHERE qid = ?', self::QID . '5'),
			'the raw file is kept: the row points to it');
		$this->assertFileExists(self::valueString('SELECT mail_location FROM mail_logs WHERE qid = ?', self::QID . '5'));
		$log = self::logSince($from);
		$this->assertStringContainsString(self::QID . '5 the database rejected the mail: SQLSTATE 45000, error 1644:', $log);
		$this->assertStringContainsString('rejected by rqwatch_test_reject', $log, 'why, in full');
		$this->assertStringContainsString(self::QID . '5 saved without its free text', $log);
	}

	// rejected even without its free text: nothing in the database, nothing left in quarantine
	public function testRejectedEvenSoLoggedAndRemoved(): void {
		self::rejectingTrigger();
		$from = self::logSize();
		[$status] = self::post(['qid' => self::REJECTED_QID] + self::meta(6, ['action' => 'reject']), 'message');
		$this->assertSame(500, $status);
		$this->assertSame(0, self::valueInt('SELECT COUNT(*) FROM mail_logs WHERE qid = ?', self::REJECTED_QID));
		$this->assertSame([], glob(self::root() . '/q/*/' . self::REJECTED_QID));
		$log = self::logSince($from);
		$this->assertStringContainsString(self::REJECTED_QID . ' import error: SQLSTATE 45000, error 1644:', $log);
		$this->assertStringContainsString('insert into', $log, 'the failed SQL, to see why');
		$this->assertStringContainsString(self::REJECTED_QID . ' removed from quarantine', $log);
	}

	// a mistyped setting: boot reports it but lets the importer run, which falls back and logs

	public function testStoreSettingsNotArrayUsesDefaults(): void {
		self::withSetting("\$store_settings = 'reject';", function (): void {
			$from = self::logSize();
			$response = self::post(self::meta(7, ['action' => 'reject']), 'message');
			$this->assertSame([200, 'Message saved'], $response);
			$this->assertSame(1, self::valueInt('SELECT mail_stored FROM mail_logs WHERE qid = ?', self::QID . '7'),
				"config.php's defaults store a rejected mail");
			$this->assertStringContainsString(
				self::QID . "7: store_settings must be an array, not string in config; using config.php's defaults", self::logSince($from));
		});
	}

	public function testFallbackCharsetNotTextDecodesWithDefault(): void {
		self::withSetting('$mail_fallback_charset = 5;', function (): void {
			$from = self::logSize();
			$response = self::post(self::meta(8, []), 'message', "Caf\xE9 META");
			$this->assertSame([200, 'Message saved'], $response);
			$this->assertSame('Café META', self::value('SELECT subject FROM mail_logs WHERE qid = ?', self::QID . '8'));
			$this->assertStringContainsString(
				self::QID . '8: mail_fallback_charset must be a string, not int in config; decoding with Windows-1252', self::logSince($from));
		});
	}

	public function testLogToFilesNotBoolSavesTheMail(): void {
		self::withSetting("\$log_to_files = 'yes';\n\$log_to_files_dir = '/tmp';", function (): void {
			$from = self::logSize();
			$response = self::post(self::meta(9, []), 'message');
			$this->assertSame([200, 'Message saved'], $response);
			$this->assertSame(1, self::valueInt('SELECT COUNT(*) FROM mail_logs WHERE qid = ?', self::QID . '9'));
			$this->assertStringContainsString(
				self::QID . '9: log_to_files not written: log_to_files must be true or false, not string in config', self::logSince($from));
		});
	}

	// the debug dump fails before the give-up branch: the raw file is still removed
	public function testLogToFilesNotBoolOnGiveUpRemovesTheFile(): void {
		self::rejectingTrigger();
		self::withSetting("\$log_to_files = 'yes';", function (): void {
			[$status] = self::post(['qid' => self::REJECTED_QID] + self::meta(10, ['action' => 'reject']), 'message');
			$this->assertSame(500, $status);
			$this->assertSame([], glob(self::root() . '/q/*/' . self::REJECTED_QID));
		});
	}

	// $php appended to config.local.php, under a fresh config cache key for the Redis leg
	private static function withSetting(string $php, callable $test): void {
		self::withEnv(['REDIS_CONFIG_KEY' => '"rqwatch_config_meta_' . bin2hex(random_bytes(4)) . '"'], function () use ($php, $test): void {
			self::withLocalConfig($php, $test);
		});
	}

	// rejects a row by its subject, or always for REJECTED_QID
	private static function rejectingTrigger(): void {
		self::db()->exec('DROP TRIGGER IF EXISTS rqwatch_test_reject');
		self::db()->exec("CREATE TRIGGER rqwatch_test_reject BEFORE INSERT ON mail_logs FOR EACH ROW"
			. " IF NEW.subject = '" . self::REJECTED_SUBJECT . "' OR NEW.qid = '" . self::REJECTED_QID . "' THEN"
			. " SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'rejected by rqwatch_test_reject'; END IF");
	}

	/**
	 * @param array<string, mixed> $override
	 * @return array<string, mixed>
	 */
	private static function meta(int $n, array $override): array {
		return $override + [
			'qid' => self::QID . $n, 'score' => 1, 'action' => 'no action', 'ip' => '192.0.2.41',
			'from' => 'meta@example.net', 'rcpt' => ['admin@example.org'],
		];
	}

	/**
	 * Posts $meta and a small mail, the mail as form field $field
	 * @param array<string, mixed> $meta
	 * @return array{int, string} HTTP status, body
	 */
	private static function post(array $meta, string $field, string $subject = 'SUBJ-META'): array {
		return self::postRaw(json_encode($meta, JSON_THROW_ON_ERROR), $field, $subject);
	}

	/** @return array{int, string} HTTP status, body */
	private static function postRaw(string $metadata, string $field, string $subject = 'SUBJ-META'): array {
		$mail = "From: meta@example.net\r\nTo: admin@example.org\r\nSubject: {$subject}\r\n"
			. "Message-ID: <meta-" . uniqid() . "@example.net>\r\n\r\nBody\r\n";
		$ch = curl_init(rtrim((string) getenv('INTEGRATION_API_URL'), '/') . '/api/metadata_importer_multipart.php?server=mx1')
			?: throw new RuntimeException('curl_init() failed');
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POSTFIELDS => [
				'metadata' => $metadata,
				$field => new CURLStringFile($mail, 'message', 'message/rfc822'),
			],
			CURLOPT_USERPWD => (string) getenv('INTEGRATION_RSPAMD_API_USER') . ':' . (string) getenv('INTEGRATION_RSPAMD_API_PASS'),
			CURLOPT_TIMEOUT => 30,
		]);
		$body = curl_exec($ch);
		return [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), is_string($body) ? $body : ''];
	}

	private static function logSize(): int {
		clearstatcache();
		$log = self::root() . '/logs/rqwatch.log';
		return is_file($log) ? (int) filesize($log) : 0;
	}

	private static function logSince(int $from): string {
		clearstatcache();
		return (string) file_get_contents(self::root() . '/logs/rqwatch.log', false, null, $from);
	}
}
