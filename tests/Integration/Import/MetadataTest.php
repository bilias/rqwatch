<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The importer on malformed rspamd metadata, without rspamd: requests posted
 as metadata_exporter would. A fuzzy value that is not a list of hashes is
 logged and stored as none, where it used to go into the JSON column as it
 came; a text field holding true is logged and treated as absent; a message
 part sent as message[] is refused as a missing message.
*/

namespace Tests\Integration\Import;

use CURLStringFile;
use RuntimeException;
use Tests\Integration\IntegrationTestCase;

final class MetadataTest extends IntegrationTestCase
{
	// imported mails' qids start with this
	private const QID = 'QIDMETA';

	#[\Override]
	protected function tearDown(): void {
		// the imported mails' child rows cascade
		self::db()->exec("DELETE FROM mail_logs WHERE qid LIKE '" . self::QID . "%'");
		parent::tearDown();
	}

	public function testFuzzyNotAListStoredAsNone(): void {
		$from = self::logSize();
		$response = self::post(self::meta(1, ['fuzzy' => 'abc']), 'message');
		$this->assertSame([200, 'Message saved'], $response);
		$this->assertSame('[]', (string) self::value(
			'SELECT d.fuzzy_hashes FROM mail_log_data d JOIN mail_logs m ON m.id = d.mail_log_id WHERE m.qid = ?', self::QID . '1'));
		$this->assertStringContainsString("metadata field 'fuzzy' is not a list of hashes (string); ignoring it", self::logSince($from));
	}

	public function testTextFieldTrueIgnored(): void {
		$from = self::logSize();
		$response = self::post(self::meta(2, ['from' => true]), 'message');
		$this->assertSame([200, 'Message saved'], $response);
		$this->assertSame('empty-mail-from@localhost', (string) self::value('SELECT mail_from FROM mail_logs WHERE qid = ?', self::QID . '2'));
		$this->assertStringContainsString("metadata field 'from' is bool, not text; ignoring it", self::logSince($from));
	}

	public function testMessageArrayRefused(): void {
		$from = self::logSize();
		[$status] = self::post(self::meta(3, []), 'message[0]');
		$this->assertSame(400, $status);
		$this->assertStringContainsString('Missing multipart file: message', self::logSince($from));
		$this->assertSame(0, (int) self::value('SELECT COUNT(*) FROM mail_logs WHERE qid = ?', self::QID . '3'));
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
	private static function post(array $meta, string $field): array {
		$mail = "From: meta@example.net\r\nTo: admin@example.org\r\nSubject: SUBJ-META\r\n"
			. "Message-ID: <meta-" . uniqid() . "@example.net>\r\n\r\nBody\r\n";
		$ch = curl_init(rtrim((string) getenv('INTEGRATION_API_URL'), '/') . '/api/metadata_importer_multipart.php?server=mx1')
			?: throw new RuntimeException('curl_init() failed');
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POSTFIELDS => [
				'metadata' => json_encode($meta, JSON_THROW_ON_ERROR),
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
