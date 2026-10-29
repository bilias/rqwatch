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
 header picks a rule from tests/Import/rspamd/rspamd.local.lua; the map
 entries come from tests/Import/fixtures.sql.
*/

namespace Tests\Import;

use PDO;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ImportTest extends TestCase
{
	private const SERVER = 'mx1';
	private const IP = '192.0.2.10';
	private const MAIL_FROM = 'Sender@Sender.TEST';
	private const MIME_FROM = 'Sender <sender@sender.test>';
	private const RCPT = 'alice@rqwatch.test';
	private const WAIT_SECONDS = 15;
	// how a test mail carries its queue id
	private const QID_PROTOCOL = 'protocol';  // Queue-Id scan header (the MTA's)
	private const QID_HEADER = 'header';      // only X-Rspamd-Queue-Id in the mail
	private const QID_NONE = 'none';
	// MapHitService::ENTRY_COMBINED
	private const ENTRY_COMBINED = 1;

	private static ?PDO $db = null;

	/** @var array{mail: string, id: string, qid: string, ip: string, from: string, mime_from: string, to: list<string>} */
	private array $sent;

	public static function setUpBeforeClass(): void {
		if (getenv('IMPORT_RSPAMD_URL') !== false) {
			self::waitForMaps();
		}
	}

	protected function setUp(): void {
		if (getenv('IMPORT_RSPAMD_URL') === false) {
			$this->markTestSkipped('Run through qa/import-tests.sh');
		}
	}

	#[TestDox('Clean mail: logged with UTF-8 subject and every recipient, not quarantined')]
	public function testCleanMailIsLoggedNotStored(): void {
		$subject = 'Καλημέρα κόσμε';
		[, $reply, $row] = $this->import('clean', '=?UTF-8?B?' . base64_encode($subject) . '?=',
			rcpts: [self::RCPT, 'Bob@RQWATCH.test']);
		$this->assertSame('no action', $reply['action'], 'fixture: rspamd verdict');

		$this->assertSame($subject, $row['subject']);
		$this->assertSame('alice@rqwatch.test, bob@rqwatch.test', $row['rcpt_to']);
		$this->assertSame(['alice@rqwatch.test', 'bob@rqwatch.test'], self::recipients((int) $row['id']));
		$this->assertSame(0, (int) $row['has_virus']);
		$this->assertNotQuarantined($row);
	}

	#[TestDox('Reject by score: quarantined byte for byte, LOCAL_* symbol recorded as a hit')]
	public function testRejectedMailIsQuarantined(): void {
		[, $reply, $row] = $this->import('spam');
		$this->assertSame('reject', $reply['action'], 'fixture: rspamd verdict');

		$this->assertSame(0, (int) $row['has_virus']);
		$this->assertQuarantined($row);
		$this->assertHit((int) $row['id'], 'LOCAL_IMPORT_TEST_SPAM', 0, 0, 50.0);
	}

	#[TestDox('Discard by score: contrib actions.conf threshold (12) below reject')]
	public function testScoreDiscardIsQuarantined(): void {
		[, $reply, $row] = $this->import('discard');
		$this->assertSame('discard', $reply['action'], 'fixture: contrib actions.conf');

		$this->assertQuarantined($row);
	}

	#[TestDox('Virus: discarded by contrib force_actions.conf, virus recorded')]
	public function testVirusIsDiscardedAndRecorded(): void {
		[, $reply, $row] = $this->import('virus');
		$this->assertSame('discard', $reply['action'], 'fixture: contrib force_actions.conf and actions.conf');

		$this->assertQuarantined($row);
		$this->assertVirus($row);
	}

	#[TestDox('IP blacklist (CIDR entry): rejected, hit credited to the entry')]
	public function testIpBlacklistRejects(): void {
		[, $reply, $row] = $this->import('clean', ip: '198.51.100.7');
		$this->assertSame('reject', $reply['action'], 'fixture: contrib multimap.conf');

		$this->assertQuarantined($row);
		$this->assertMapHit($row, 'RQWATCH_IP_BLACKLIST', 'ip_blacklist');
	}

	#[TestDox('SMTP From blacklist: discarded, hit credited to the entry')]
	public function testSmtpFromBlacklistDiscards(): void {
		[, $reply, $row] = $this->import('clean', from: 'smtp-bl@sender.test');
		$this->assertSame('discard', $reply['action'], 'fixture: contrib multimap.conf');

		$this->assertQuarantined($row);
		$this->assertMapHit($row, 'RQWATCH_SMTP_FROM_BLACKLIST', 'mail_from_blacklist');
	}

	#[TestDox('MIME From blacklist: discarded, hit credited to the entry')]
	public function testMimeFromBlacklistDiscards(): void {
		[, $reply, $row] = $this->import('clean', mimeFrom: 'Listed <mime-bl@sender.test>');
		$this->assertSame('discard', $reply['action'], 'fixture: contrib multimap.conf');

		$this->assertQuarantined($row);
		$this->assertMapHit($row, 'RQWATCH_MIME_FROM_BLACKLIST', 'mime_from_blacklist');
	}

	#[TestDox('SMTP From/RCPT To blacklist (Lua map): rejected, hit credited to the entry')]
	public function testSmtpFromRcptToBlacklistRejects(): void {
		[, $reply, $row] = $this->import('clean', from: 'pair-bl@sender.test', rcpts: [self::RCPT, 'bob@rqwatch.test']);
		$this->assertSame('reject', $reply['action'], 'fixture: contrib lua.local.d and groups.conf');

		$this->assertQuarantined($row);
		$this->assertMapHit($row, 'RQWATCH_SMTP_FROM_RCPT_TO_BLACKLIST', 'mail_from_rcpt_to_blacklist');
	}

	#[TestDox('SMTP From whitelist: spam accepted, not quarantined, hit credited')]
	public function testWhitelistAcceptsSpam(): void {
		[, $reply, $row] = $this->import('spam', from: 'smtp-wl@sender.test');
		$this->assertSame('no action', $reply['action'], 'fixture: contrib multimap.conf');

		$this->assertNotQuarantined($row);
		$this->assertMapHit($row, 'RQWATCH_SMTP_FROM_WHITELIST', 'mail_from_whitelist');
	}

	#[TestDox('SMTP From whitelist with a virus: still discarded by force_actions.conf')]
	public function testWhitelistedVirusIsDiscarded(): void {
		[, $reply, $row] = $this->import('virus', from: 'smtp-wl@sender.test');
		$this->assertSame('discard', $reply['action'], 'fixture: contrib force_actions.conf');

		$this->assertQuarantined($row);
		$this->assertVirus($row);
		$this->assertMapHit($row, 'RQWATCH_SMTP_FROM_WHITELIST', 'mail_from_whitelist');
	}

	#[TestDox('No Queue-Id: the qid comes from the X-Rspamd-Queue-Id header')]
	public function testQidFromHeader(): void {
		[, , $row] = $this->import('spam', qid: self::QID_HEADER);

		$this->assertQuarantined($row);
		$this->assertStringContainsString("/{$row['qid']}/mail.eml", (string) $row['mail_location']);
	}

	#[TestDox('No qid at all: logged as unknown, quarantined under unknown/')]
	public function testUnknownQid(): void {
		[, , $row] = $this->import('spam', qid: self::QID_NONE);

		$this->assertQuarantined($row);
		$this->assertMatchesRegularExpression('#/\d{4}-\d{2}-\d{2}/unknown/[^/]+/mail\.eml$#', (string) $row['mail_location']);
	}

	#[TestDox('Unencoded 8-bit Windows-1253 subject: imported as valid UTF-8, not refused by strict mode')]
	public function testEightBitSubject(): void {
		$subject = (string) iconv('UTF-8', 'CP1253', 'Καλημέρα κόσμε');
		[, , $row] = $this->import('clean', $subject, charset: 'windows-1253');

		$this->assertTrue(mb_check_encoding((string) $row['subject'], 'UTF-8'), 'subject is valid UTF-8');
		$this->assertNotSame('', (string) $row['subject']);
	}

	#[TestDox('60 recipients: rcpt_to and mime_to truncated, every recipient listed')]
	public function testRecipientOverflow(): void {
		$rcpts = [];
		for ($i = 1; $i <= 60; $i++) {
			$rcpts[] = sprintf('recipient-%02d-with-a-long-local-part@rqwatch.test', $i);
		}
		[, , $row] = $this->import('clean', rcpts: $rcpts, to: array_slice($rcpts, 0, 10));

		$this->assertSame(mb_substr(implode(', ', $rcpts), 0, 1024), $row['rcpt_to']);
		$this->assertSame($rcpts, self::recipients((int) $row['id']));
	}

	/**
	 * Scans a test mail and returns its qid, rspamd's reply and the imported
	 * row, after checking what every import must satisfy.
	 * @param list<string> $rcpts
	 * @return array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}
	 */
	private function import(
		string $test,
		?string $subject = null,
		array $rcpts = [self::RCPT],
		string $from = self::MAIL_FROM,
		string $mimeFrom = self::MIME_FROM,
		string $ip = self::IP,
		string $qid = self::QID_PROTOCOL,
		array $to = [self::RCPT],
		string $charset = 'utf-8',
	): array {
		$id = self::qid();
		$extra = $qid === self::QID_HEADER ? ["X-Rspamd-Queue-Id: {$id}"] : [];
		$mail = self::message($id, $subject ?? ucfirst($test) . " {$id}", $test, $mimeFrom, $to, $charset, $extra);
		$this->sent = [
			'mail' => $mail, 'id' => $id, 'qid' => $qid === self::QID_NONE ? 'unknown' : $id,
			'ip' => $ip, 'from' => $from, 'mime_from' => $mimeFrom, 'to' => $to,
		];

		$reply = self::scan($mail, self::scanHeaders($qid === self::QID_PROTOCOL ? $id : null, $ip, $from, $rcpts));
		$row = self::row("<{$id}@sender.test>");
		$this->assertImported($reply, $row);
		return [$this->sent['qid'], $reply, $row];
	}

	/**
	 * The row and its mail_log_data agree with rspamd's verdict and the mail sent.
	 * @param array<string, mixed> $reply
	 * @param array<string, mixed> $row
	 */
	private function assertImported(array $reply, array $row): void {
		$this->assertSame($this->sent['qid'], $row['qid']);
		$this->assertSame(self::SERVER, $row['server']);
		$this->assertSame($reply['action'], $row['action']);
		$this->assertEqualsWithDelta((float) $reply['score'], (float) $row['score'], 0.005, 'score');
		$this->assertSame($this->sent['ip'], $row['ip']);
		$this->assertSame(strtolower($this->sent['from']), $row['mail_from']);
		$this->assertSame($this->sent['mime_from'], $row['mime_from']);
		$this->assertSame(mb_strimwidth(implode(', ', $this->sent['to']), 0, 250, '!!'), $row['mime_to']);
		$this->assertSame("<{$this->sent['id']}@sender.test>", $row['message_id']);
		$this->assertSame(strlen($this->sent['mail']), (int) $row['size'], 'size');

		$data = self::query('SELECT headers, symbols FROM mail_log_data WHERE mail_log_id = ?', [(int) $row['id']]);
		$this->assertCount(1, $data, 'mail_log_data row');
		$this->assertStringContainsString("Message-ID: <{$this->sent['id']}@sender.test>", (string) $data[0]['headers']);

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
	private function assertQuarantined(array $row): void {
		$this->assertSame(1, (int) $row['mail_stored']);
		$location = (string) $row['mail_location'];
		$this->assertStringStartsWith(self::quarantineDir() . '/', $location);
		$this->assertFileExists($location);
		$this->assertSame($this->sent['mail'], file_get_contents($location), 'stored mail differs from the mail scanned');
	}

	/** @param array<string, mixed> $row */
	private function assertNotQuarantined(array $row): void {
		$this->assertSame(0, (int) $row['mail_stored']);
		$this->assertNull($row['mail_location']);
		$this->assertDirectoryDoesNotExist(self::quarantineDir() . '/' . date('Y-m-d') . "/{$this->sent['qid']}");
	}

	/** @param array<string, mixed> $row */
	private function assertVirus(array $row): void {
		$this->assertSame(1, (int) $row['has_virus']);
		$this->assertSame(
			[['av' => 'CLAM_VIRUS', 'name' => 'Eicar-Test-Signature']],
			self::query('SELECT av, name FROM mail_log_viruses WHERE mail_log_id = ?', [(int) $row['id']])
		);
	}

	/** @param array<string, mixed> $row */
	private function assertMapHit(array $row, string $symbol, string $map): void {
		$entries = self::query('SELECT id FROM maps_combined WHERE map_name = ?', [$map]);
		$this->assertCount(1, $entries, "fixture: one {$map} entry");
		$this->assertHit((int) $row['id'], $symbol, self::ENTRY_COMBINED, (int) $entries[0]['id']);
	}

	private function assertHit(int $mailLogId, string $symbol, int $model, int $entryId, ?float $score = null): void {
		$hits = self::query(
			'SELECT h.entry_model, h.entry_id, h.score FROM mail_log_symbol_hits h
				JOIN symbol_names s ON s.id = h.symbol_id
				WHERE h.mail_log_id = ? AND s.name = ?',
			[$mailLogId, $symbol]
		);
		$this->assertSame(
			[[$model, $entryId]],
			array_map(fn($h) => [(int) $h['entry_model'], (int) $h['entry_id']], $hits),
			"map hit for {$symbol}"
		);
		if ($score !== null) {
			$this->assertEqualsWithDelta($score, (float) $hits[0]['score'], 0.001);
		}
	}

	private static function quarantineDir(): string {
		return rtrim((string) getenv('IMPORT_QUARANTINE_DIR'), '/');
	}

	private static function qid(): string {
		return 'IMP' . strtoupper(bin2hex(random_bytes(6)));
	}

	/**
	 * @param list<string> $to
	 * @param list<string> $extra more header lines
	 */
	private static function message(
		string $id,
		string $subject,
		string $test,
		string $mimeFrom,
		array $to = [self::RCPT],
		string $charset = 'utf-8',
		array $extra = [],
	): string {
		return implode("\r\n", [
			"From: {$mimeFrom}",
			'To: ' . implode(', ', $to),
			"Subject: {$subject}",
			'Date: ' . date(DATE_RFC2822),
			"Message-ID: <{$id}@sender.test>",
			...$extra,
			'MIME-Version: 1.0',
			"Content-Type: text/plain; charset={$charset}",
			"X-Import-Test: {$test}",
			'',
			"Body of {$id}.",
			'',
		]);
	}

	/**
	 * Scan headers as the MTA would send them. As an authenticated user, so
	 * DNS-based sender checks add no score and stock thresholds (with
	 * contrib's discard) give stable verdicts.
	 * @param list<string> $rcpts
	 * @return list<string>
	 */
	private static function scanHeaders(?string $qid, string $ip, string $from, array $rcpts): array {
		$headers = ["IP: {$ip}", "From: {$from}", 'User: import-test'];
		if ($qid !== null) {
			$headers[] = "Queue-Id: {$qid}";
		}
		foreach ($rcpts as $rcpt) {
			$headers[] = "Rcpt: {$rcpt}";
		}
		return $headers;
	}

	/**
	 * Scans the mail through rspamd's normal worker.
	 * @param list<string> $headers
	 * @return array<string, mixed> rspamd's reply
	 */
	private static function scan(string $mail, array $headers): array {
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
			throw new RuntimeException("rspamd scan failed (HTTP {$status}): " . curl_error($ch));
		}

		$reply = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
		if (!is_array($reply) || !isset($reply['action'], $reply['score'], $reply['symbols'])) {
			throw new RuntimeException("Unexpected rspamd reply: {$body}");
		}
		return $reply;
	}

	/**
	 * Rspamd fetches the map files after it starts: probe each map until its
	 * symbol fires. qa/import-tests.sh runs one normal worker, so the worker
	 * probed is the one the tests reach. Probes are not exported.
	 */
	private static function waitForMaps(): void {
		$probes = [
			'RQWATCH_IP_BLACKLIST' => ['198.51.100.7', self::MAIL_FROM, self::MIME_FROM, [self::RCPT]],
			'RQWATCH_SMTP_FROM_BLACKLIST' => [self::IP, 'smtp-bl@sender.test', self::MIME_FROM, [self::RCPT]],
			'RQWATCH_MIME_FROM_BLACKLIST' => [self::IP, self::MAIL_FROM, 'Listed <mime-bl@sender.test>', [self::RCPT]],
			'RQWATCH_SMTP_FROM_WHITELIST' => [self::IP, 'smtp-wl@sender.test', self::MIME_FROM, [self::RCPT]],
			'RQWATCH_SMTP_FROM_RCPT_TO_BLACKLIST' => [self::IP, 'pair-bl@sender.test', self::MIME_FROM, ['bob@rqwatch.test']],
		];
		$deadline = microtime(true) + 60;
		while ($probes !== []) {
			foreach ($probes as $symbol => [$ip, $from, $mimeFrom, $rcpts]) {
				$qid = 'PROBE' . strtoupper(bin2hex(random_bytes(4)));
				$headers = self::scanHeaders($qid, $ip, $from, $rcpts);
				$headers[] = 'Settings: {symbols_disabled = ["EXPORT_METADATA_RQWATCH"]}';
				$reply = self::scan(self::message($qid, 'Probe', 'clean', $mimeFrom), $headers);
				if (isset($reply['symbols'][$symbol])) {
					unset($probes[$symbol]);
				}
			}
			if ($probes !== [] && microtime(true) > $deadline) {
				throw new RuntimeException('rspamd did not load the maps for: ' . implode(', ', array_keys($probes)));
			}
			usleep(500000);
		}
	}

	/**
	 * The mail_logs row of a test mail, by its unique Message-ID, once the
	 * exporter's post has been imported.
	 * @return array<string, mixed>
	 */
	private static function row(string $messageId): array {
		$deadline = microtime(true) + self::WAIT_SECONDS;
		do {
			$rows = self::query('SELECT * FROM mail_logs WHERE message_id = ?', [$messageId]);
			if ($rows !== []) {
				self::assertCount(1, $rows, "rows for {$messageId}");
				return $rows[0];
			}
			usleep(200000);
		} while (microtime(true) < $deadline);

		throw new RuntimeException("{$messageId} was not imported within " . self::WAIT_SECONDS . 's');
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
