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
 entries come from tests/Import/fixtures.sql. Mail the instance sends is
 captured by Mailpit.

 This class holds the scan, import, assertion, CLI and Mailpit helpers; the
 tests are in the *Test classes beside it.
*/

namespace Tests\Import;

use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

abstract class ImportTestCase extends TestCase
{
	protected const SERVER = 'mx1';
	protected const IP = '192.0.2.10';
	protected const MAIL_FROM = 'Sender@Sender.TEST';
	protected const MIME_FROM = 'Sender <sender@sender.test>';
	protected const RCPT = 'alice@rqwatch.test';
	protected const WAIT_SECONDS = 15;
	// how a test mail carries its queue id
	protected const QID_PROTOCOL = 'protocol';  // Queue-Id scan header (the MTA's)
	protected const QID_HEADER = 'header';      // only X-Rspamd-Queue-Id in the mail
	protected const QID_NONE = 'none';

	private static ?PDO $db = null;
	private static bool $mapsReady = false;

	/** @var array{mail: string, id: string, qid: string, ip: string, from: string, mime_from: string, to: list<string>} */
	protected array $sent;

	public static function setUpBeforeClass(): void {
		// once per run, not per class
		if (getenv('IMPORT_RSPAMD_URL') !== false && !self::$mapsReady) {
			self::waitForMaps();
			self::$mapsReady = true;
		}
	}

	protected function setUp(): void {
		if (getenv('IMPORT_RSPAMD_URL') === false) {
			$this->markTestSkipped('Run through qa/import-tests.sh');
		}
	}

	/**
	 * Scans a test mail and returns its qid, rspamd's reply and the imported
	 * row, after checking what every import must satisfy. With $wait false it
	 * returns after the scan, without a row.
	 * @param list<string> $rcpts
	 * @param list<string> $to
	 * @param list<string> $headers more header lines in the mail
	 * @return array{0: string, 1: array<string, mixed>, 2: ?array<string, mixed>}
	 */
	protected function import(
		string $test,
		?string $subject = null,
		array $rcpts = [self::RCPT],
		string $from = self::MAIL_FROM,
		string $mimeFrom = self::MIME_FROM,
		string $ip = self::IP,
		string $qid = self::QID_PROTOCOL,
		array $to = [self::RCPT],
		string $charset = 'utf-8',
		bool $wait = true,
		string $body = '',
		?string $storedMimeFrom = null,
		array $headers = [],
	): array {
		$id = self::qid();
		$extra = $qid === self::QID_HEADER ? ["X-Rspamd-Queue-Id: {$id}", ...$headers] : $headers;
		$mail = self::message($id, $subject ?? ucfirst($test) . " {$id}", $test, $mimeFrom, $to, $charset, $extra, $body);
		$this->sent = [
			'mail' => $mail, 'id' => $id, 'qid' => $qid === self::QID_NONE ? 'unknown' : $id,
			'ip' => $ip, 'from' => $from, 'mime_from' => $storedMimeFrom ?? $mimeFrom, 'to' => $to,
		];

		$reply = self::scan($mail, self::scanHeaders($qid === self::QID_PROTOCOL ? $id : null, $ip, $from, $rcpts));
		if (!$wait) {
			return [$this->sent['qid'], $reply, null];
		}
		$row = self::row("<{$id}@sender.test>");
		$this->assertImported($reply, $row);
		return [$this->sent['qid'], $reply, $row];
	}

	/**
	 * The row and its mail_log_data agree with rspamd's verdict and the mail sent.
	 * @param array<string, mixed> $reply
	 * @param array<string, mixed> $row
	 */
	protected function assertImported(array $reply, array $row): void {
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
	protected function assertQuarantined(array $row): void {
		$this->assertSame(1, (int) $row['mail_stored']);
		$location = (string) $row['mail_location'];
		$this->assertStringStartsWith(self::quarantineDir() . '/', $location);
		$this->assertFileExists($location);
		$this->assertSame($this->sent['mail'], file_get_contents($location), 'stored mail differs from the mail scanned');
	}

	/** @param array<string, mixed> $row */
	protected function assertNotQuarantined(array $row): void {
		$this->assertSame(0, (int) $row['mail_stored']);
		$this->assertNull($row['mail_location']);
		$this->assertDirectoryDoesNotExist(self::quarantineDir() . '/' . date('Y-m-d') . "/{$this->sent['qid']}");
	}

	/** @param array<string, mixed> $row */
	protected function assertVirus(array $row): void {
		$this->assertSame(1, (int) $row['has_virus']);
		$this->assertSame(
			[['av' => 'CLAM_VIRUS', 'name' => 'Eicar-Test-Signature']],
			self::query('SELECT av, name FROM mail_log_viruses WHERE mail_log_id = ?', [(int) $row['id']])
		);
	}

	protected function assertHit(int $mailLogId, string $symbol, int $model, int $entryId, ?float $score = null): void {
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

	protected static function quarantineDir(): string {
		return rtrim((string) getenv('IMPORT_QUARANTINE_DIR'), '/');
	}

	protected static function qid(): string {
		return 'IMP' . strtoupper(bin2hex(random_bytes(6)));
	}

	/**
	 * @param list<string> $to
	 * @param list<string> $extra more header lines
	 */
	protected static function message(
		string $id,
		string $subject,
		string $test,
		string $mimeFrom,
		array $to = [self::RCPT],
		string $charset = 'utf-8',
		array $extra = [],
		string $body = '',
	): string {
		return implode("\r\n", [
			"From: {$mimeFrom}",
			'To: ' . implode(', ', $to),
			"Subject: {$subject}",
			'Date: ' . date(DATE_RFC2822),
			"Message-ID: <{$id}@sender.test>",
			...$extra,
			'MIME-Version: 1.0',
			'Content-Type: text/plain' . ($charset !== '' ? "; charset={$charset}" : ''),
			"X-Import-Test: {$test}",
			'',
			$body !== '' ? $body : "Body of {$id}.",
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
	protected static function scanHeaders(?string $qid, string $ip, string $from, array $rcpts): array {
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
	protected static function scan(string $mail, array $headers): array {
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
	protected static function row(string $messageId): array {
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
	protected static function recipients(int $id): array {
		return array_column(self::query(
			'SELECT recipient_email FROM mail_log_recipients WHERE mail_log_id = ? ORDER BY recipient_email',
			[$id]
		), 'recipient_email');
	}

	/** Runs bin/cli.php in the instance's copy and returns its output; fails on a non-zero exit. */
	protected static function cli(string ...$args): string {
		$cmd = 'cd ' . escapeshellarg((string) getenv('IMPORT_ROOT')) . ' && '
			. escapeshellarg(PHP_BINARY) . ' bin/cli.php ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
		exec($cmd, $out, $status);
		$output = implode("\n", $out);
		if ($status !== 0) {
			throw new RuntimeException('bin/cli.php ' . implode(' ', $args) . " exited {$status}: {$output}");
		}
		return $output;
	}

	/** An address no other test uses, so the mail it receives is this test's. */
	protected static function address(string $prefix): string {
		return $prefix . '-' . bin2hex(random_bytes(4)) . '@rqwatch.test';
	}

	/**
	 * GET from Mailpit's API.
	 * @return array<string, mixed>
	 */
	protected static function mailpit(string $path): array {
		$ch = curl_init(rtrim((string) getenv('IMPORT_MAILPIT_URL'), '/') . $path);
		curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
		$body = curl_exec($ch);
		$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		if (!is_string($body) || $status !== 200) {
			throw new RuntimeException("Mailpit {$path} answered {$status}");
		}
		return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
	}

	/** Messages Mailpit has received in this run. */
	protected static function mailCount(): int {
		return (int) self::mailpit('/api/v1/messages?limit=1')['total'];
	}

	/**
	 * Messages delivered to $address (To, Cc or envelope-only Bcc), optionally
	 * only those matching a Mailpit search for $text.
	 * @return list<array<string, mixed>> Mailpit message summaries
	 */
	protected static function mailsFor(string $address, ?string $text = null): array {
		$found = [];
		for ($start = 0; ; $start += 500) {
			$page = $text === null
				? self::mailpit("/api/v1/messages?limit=500&start={$start}")
				: self::mailpit('/api/v1/search?limit=500&start=' . $start . '&query=' . rawurlencode($text));
			foreach ($page['messages'] as $message) {
				if (in_array(strtolower($address), self::recipientsOf($message), true)) {
					$found[] = $message;
				}
			}
			if ($start + 500 >= (int) $page['messages_count']) {
				return $found;
			}
		}
	}

	/**
	 * Every address a captured message was delivered to, sorted.
	 * @param array<string, mixed> $message Mailpit message summary
	 * @return list<string>
	 */
	protected static function recipientsOf(array $message): array {
		$addresses = [];
		foreach (['To', 'Cc', 'Bcc'] as $field) {
			foreach ($message[$field] ?? [] as $entry) {
				$addresses[] = strtolower((string) $entry['Address']);
			}
		}
		$addresses = array_values(array_unique($addresses));
		sort($addresses);
		return $addresses;
	}

	protected static function db(): PDO {
		return self::$db ??= new PDO(
			(string) getenv('IMPORT_DB_DSN'),
			(string) getenv('IMPORT_DB_USER'),
			(string) getenv('IMPORT_DB_PASS'),
			[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
		);
	}

	/**
	 * @param list<int|string> $params
	 * @return list<array<string, mixed>>
	 */
	protected static function query(string $sql, array $params): array {
		$stmt = self::db()->prepare($sql);
		$stmt->execute($params);
		return array_values($stmt->fetchAll());
	}
}
