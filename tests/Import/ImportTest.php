<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis
 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Rspamd metadata export into the importer, end to end, against the instance
 qa/import-tests.sh runs: each test scans a mail through rspamd, whose
 metadata_exporter (contrib's rule) posts it to the importer, then compares
 the stored rows with rspamd's own verdict for that scan. The X-Import-Test
 header picks a rule from tests/Import/rspamd/rspamd.local.lua.
*/

namespace Tests\Import;

use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ImportTest extends TestCase
{
	private const SERVER = 'mx1';
	private const IP = '192.0.2.10';
	private const MAIL_FROM = 'Sender@Sender.TEST';
	private const WAIT_SECONDS = 15;

	private static ?PDO $db = null;

	protected function setUp(): void {
		if (getenv('IMPORT_RSPAMD_URL') === false) {
			$this->markTestSkipped('Run through qa/import-tests.sh');
		}
	}

	public function testCleanMailIsLoggedNotStored(): void {
		$qid = self::qid();
		$subject = 'Καλημέρα κόσμε';
		$mail = self::message($qid, '=?UTF-8?B?' . base64_encode($subject) . '?=', 'clean');
		$reply = self::scan($qid, $mail, ['alice@rqwatch.test', 'Bob@RQWATCH.test']);
		$this->assertSame('no action', $reply['action'], 'fixture: rspamd verdict');

		$row = self::row($qid);
		$this->assertImported($reply, $row, $mail);
		$this->assertSame($subject, $row['subject']);
		$this->assertSame('alice@rqwatch.test, bob@rqwatch.test', $row['rcpt_to']);
		$this->assertSame(['alice@rqwatch.test', 'bob@rqwatch.test'], self::recipients((int) $row['id']));
		$this->assertSame(0, (int) $row['has_virus']);
		$this->assertSame(0, (int) $row['mail_stored']);
		$this->assertNull($row['mail_location']);
		$this->assertDirectoryDoesNotExist(self::quarantineDir() . '/' . date('Y-m-d') . "/{$qid}");
	}

	public function testRejectedMailIsQuarantined(): void {
		$qid = self::qid();
		$mail = self::message($qid, "Spam {$qid}", 'spam');
		$reply = self::scan($qid, $mail, ['alice@rqwatch.test']);
		$this->assertSame('reject', $reply['action'], 'fixture: rspamd verdict');

		$row = self::row($qid);
		$this->assertImported($reply, $row, $mail);
		$this->assertSame(0, (int) $row['has_virus']);
		$this->assertQuarantined($row, $mail);

		// LOCAL_* symbols are recorded as map hits (no map entry: model 0)
		$hit = self::query(
			'SELECT h.entry_model, h.score FROM mail_log_symbol_hits h
				JOIN symbol_names s ON s.id = h.symbol_id
				WHERE h.mail_log_id = ? AND s.name = ?',
			[(int) $row['id'], 'LOCAL_IMPORT_TEST_SPAM']
		);
		$this->assertCount(1, $hit, 'map hit for LOCAL_IMPORT_TEST_SPAM');
		$this->assertSame(0, (int) $hit[0]['entry_model']);
		$this->assertEqualsWithDelta(50.0, (float) $hit[0]['score'], 0.001);
	}

	public function testVirusIsDiscardedAndRecorded(): void {
		$qid = self::qid();
		$mail = self::message($qid, "Virus {$qid}", 'virus');
		$reply = self::scan($qid, $mail, ['alice@rqwatch.test']);
				$this->assertSame('discard', $reply['action'], 'fixture: contrib force_actions.conf and actions.conf');

		$row = self::row($qid);
		$this->assertImported($reply, $row, $mail);
		$this->assertSame(1, (int) $row['has_virus']);
		$this->assertQuarantined($row, $mail);
		$this->assertSame(
			[['av' => 'CLAM_VIRUS', 'name' => 'Eicar-Test-Signature']],
			self::query('SELECT av, name FROM mail_log_viruses WHERE mail_log_id = ?', [(int) $row['id']])
		);
	}

	/**
	 * The row and its mail_log_data agree with rspamd's verdict and the mail sent.
	 * @param array<string, mixed> $reply
	 * @param array<string, mixed> $row
	 */
	private function assertImported(array $reply, array $row, string $mail): void {
		$this->assertSame(self::SERVER, $row['server']);
		$this->assertSame($reply['action'], $row['action']);
		$this->assertEqualsWithDelta((float) $reply['score'], (float) $row['score'], 0.005, 'score');
		$this->assertSame(self::IP, $row['ip']);
		$this->assertSame(strtolower(self::MAIL_FROM), $row['mail_from']);
		$this->assertSame('Sender <sender@sender.test>', $row['mime_from']);
		$this->assertSame('alice@rqwatch.test', $row['mime_to']);
		$this->assertSame("<{$row['qid']}@sender.test>", $row['message_id']);
		$this->assertSame(strlen($mail), (int) $row['size'], 'size');

		$data = self::query('SELECT headers, symbols FROM mail_log_data WHERE mail_log_id = ?', [(int) $row['id']]);
		$this->assertCount(1, $data, 'mail_log_data row');
		$this->assertStringContainsString("Message-ID: <{$row['qid']}@sender.test>", (string) $data[0]['headers']);

		$expected = [];
		foreach ($reply['symbols'] as $name => $symbol) {
			$expected[$name] = round((float) $symbol['score'], 2);
		}
		$stored = [];
		foreach (json_decode((string) $data[0]['symbols'], true, 512, JSON_THROW_ON_ERROR) as $symbol) {
			$stored[$symbol['name']] = round((float) $symbol['score'], 2);
		}
		ksort($expected);
		ksort($stored);
		$this->assertSame($expected, $stored, 'symbols and their scores');
	}

	/** @param array<string, mixed> $row */
	private function assertQuarantined(array $row, string $mail): void {
		$this->assertSame(1, (int) $row['mail_stored']);
		$location = (string) $row['mail_location'];
		$this->assertStringStartsWith(self::quarantineDir() . '/', $location);
		$this->assertFileExists($location);
		$this->assertSame($mail, file_get_contents($location), 'stored mail differs from the mail scanned');
	}

	private static function quarantineDir(): string {
		return rtrim((string) getenv('IMPORT_QUARANTINE_DIR'), '/');
	}

	private static function qid(): string {
		return 'IMP' . strtoupper(bin2hex(random_bytes(6)));
	}

	private static function message(string $qid, string $subject, string $test): string {
		return implode("\r\n", [
			'From: Sender <sender@sender.test>',
			'To: alice@rqwatch.test',
			"Subject: {$subject}",
			'Date: ' . date(DATE_RFC2822),
			"Message-ID: <{$qid}@sender.test>",
			'MIME-Version: 1.0',
			'Content-Type: text/plain; charset=utf-8',
			"X-Import-Test: {$test}",
			'',
			"Body of {$qid}.",
			'',
		]);
	}

	/**
	 * Scans the mail through rspamd's normal worker, as the MTA would. As an
	 * authenticated user, so DNS-based sender checks add no score and stock
	 * thresholds (with contrib's discard) give stable verdicts.
	 * @param list<string> $rcpts
	 * @return array<string, mixed> rspamd's reply
	 */
	private static function scan(string $qid, string $mail, array $rcpts): array {
		$headers = [
			"Queue-Id: {$qid}",
			'IP: ' . self::IP,
			'From: ' . self::MAIL_FROM,
			'User: import-test',
		];
		foreach ($rcpts as $rcpt) {
			$headers[] = "Rcpt: {$rcpt}";
		}

		$ch = curl_init(rtrim((string) getenv('IMPORT_RSPAMD_URL'), '/') . '/checkv2');
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POSTFIELDS => $mail,
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_TIMEOUT => 30,
		]);
		$body = curl_exec($ch);
		$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		if (!is_string($body) || $status !== 200) {
			throw new RuntimeException("rspamd scan of {$qid} failed (HTTP {$status}): " . curl_error($ch));
		}

		$reply = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
		if (!is_array($reply) || !isset($reply['action'], $reply['score'], $reply['symbols'])) {
			throw new RuntimeException("Unexpected rspamd reply for {$qid}: {$body}");
		}
		return $reply;
	}

	/**
	 * The mail_logs row of $qid, once the exporter's post has been imported.
	 * @return array<string, mixed>
	 */
	private static function row(string $qid): array {
		$deadline = microtime(true) + self::WAIT_SECONDS;
		do {
			$rows = self::query('SELECT * FROM mail_logs WHERE qid = ?', [$qid]);
			if ($rows !== []) {
				self::assertCount(1, $rows, "rows for {$qid}");
				return $rows[0];
			}
			usleep(200000);
		} while (microtime(true) < $deadline);

		throw new RuntimeException("{$qid} was not imported within " . self::WAIT_SECONDS . 's');
	}

	/** @return list<string> */
	private static function recipients(int $id): array {
		return array_column(self::query(
			'SELECT recipient_email FROM mail_log_recipients WHERE mail_log_id = ? ORDER BY recipient_email',
			[$id]
		), 'recipient_email');
	}

	/**
	 * @param list<int|string> $params
	 * @return list<array<string, mixed>>
	 */
	private static function query(string $sql, array $params): array {
		self::$db ??= new PDO(
			(string) getenv('IMPORT_DB_DSN'),
			(string) getenv('IMPORT_DB_USER'),
			(string) getenv('IMPORT_DB_PASS'),
			[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
		);
		$stmt = self::$db->prepare($sql);
		$stmt->execute($params);
		return array_values($stmt->fetchAll());
	}
}
