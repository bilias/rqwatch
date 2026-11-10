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
 qa/e2e-tests.sh runs: each test scans a mail through rspamd, whose
 metadata_exporter (contrib's rule) posts it to the importer, then compares
 the stored rows with rspamd's own verdict for that scan. The X-Import-Test
 header picks a rule from tests/EndToEnd/rspamd/rspamd.local.lua; the map
 entries come from tests/EndToEnd/fixtures.sql. Release and notifications are
 in tests/Integration, which needs no rspamd.

 This class holds the scan, import, assertion and CLI helpers; the tests are
 in the subdirectories: Import/ and Fuzzy/.
*/

namespace Tests\EndToEnd;

use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

abstract class EndToEndTestCase extends TestCase
{
	protected const string SERVER = 'mx1';
	protected const string IP = '192.0.2.10';
	protected const string MAIL_FROM = 'Sender@Sender.TEST';
	protected const string MIME_FROM = 'Sender <sender@sender.test>';
	protected const string RCPT = 'alice@rqwatch.test';
	protected const int WAIT_SECONDS = 15;
	// how a test mail carries its queue id
	protected const string QID_PROTOCOL = 'protocol';  // Queue-Id scan header (the MTA's)
	protected const string QID_HEADER = 'header';      // only X-Rspamd-Queue-Id in the mail
	protected const string QID_NONE = 'none';

	private static ?PDO $db = null;
	private static bool $mapsReady = false;

	/** @var array{mail: string, id: string, qid: string, ip: string, from: string, mime_from: string, to: list<string>} */
	protected array $sent;

	#[\Override]
	public static function setUpBeforeClass(): void {
		// once per run, not per class
		if (getenv('E2E_RSPAMD_URL') !== false && !self::$mapsReady) {
			self::waitForMaps();
			self::$mapsReady = true;
		}
	}

	#[\Override]
	protected function setUp(): void {
		if (getenv('E2E_RSPAMD_URL') === false) {
			$this->markTestSkipped('Run through qa/e2e-tests.sh');
		}
	}

	/**
	 * Scans a test mail and returns its qid, rspamd's reply and the imported
	 * row, after checking what every import must satisfy. With $wait false it
	 * returns after the scan, without a row.
	 * @param list<string> $rcpts
	 * @param list<string> $to
	 * @param list<string> $headers more header lines in the mail
	 * @return ($wait is true ? array{0: string, 1: array<array-key, mixed>, 2: array<string, mixed>} : array{0: string, 1: array<array-key, mixed>, 2: null})
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
	 * @param array<array-key, mixed> $reply
	 * @param array<string, mixed> $row
	 */
	protected function assertImported(array $reply, array $row): void {
		$this->assertSame($this->sent['qid'], $row['qid']);
		$this->assertSame(self::SERVER, $row['server']);
		$this->assertSame($reply['action'], $row['action']);
		$this->assertEqualsWithDelta(self::colFloat($reply, 'score'), self::colFloat($row, 'score'), 0.005, 'score');
		$this->assertSame($this->sent['ip'], $row['ip']);
		$this->assertSame(strtolower($this->sent['from']), $row['mail_from']);
		$this->assertSame($this->sent['mime_from'], $row['mime_from']);
		$this->assertSame(mb_strimwidth(implode(', ', $this->sent['to']), 0, 250, '!!'), $row['mime_to']);
		$this->assertSame("<{$this->sent['id']}@sender.test>", $row['message_id']);
		$this->assertSame(strlen($this->sent['mail']), self::colInt($row, 'size'), 'size');

		$data = self::query('SELECT headers, symbols FROM mail_log_data WHERE mail_log_id = ?', [self::colInt($row, 'id')]);
		$this->assertCount(1, $data, 'mail_log_data row');
		$this->assertStringContainsString("Message-ID: <{$this->sent['id']}@sender.test>", self::colString($data[0], 'headers'));

		$expected = array_map(fn (float $score): float => round($score, 2), self::symbolScores($reply));
		$stored = [];
		/** @var mixed $decoded */
		$decoded = json_decode(self::colString($data[0], 'symbols'), true, 512, JSON_THROW_ON_ERROR);
		$this->assertIsArray($decoded, 'mail_log_data.symbols');
		/** @var mixed $symbol */
		foreach ($decoded as $symbol) {
			$this->assertIsArray($symbol, 'a stored symbol');
			$stored[self::colString($symbol, 'name')] = round(self::colFloat($symbol, 'score'), 2);
		}
		ksort($expected);
		ksort($stored);
		$this->assertSame($expected, $stored, 'symbols and their scores');
	}

	/** @param array<string, mixed> $row */
	protected function assertQuarantined(array $row): void {
		$this->assertSame(1, self::colInt($row, 'mail_stored'));
		$this->assertAdminFeed($row);
		$location = self::colString($row, 'mail_location');
		$this->assertStringStartsWith(self::quarantineDir() . '/', $location);
		$this->assertFileExists($location);
		$this->assertSame($this->sent['mail'], file_get_contents($location), 'stored mail differs from the mail scanned');
	}

	/** @param array<string, mixed> $row */
	protected function assertNotQuarantined(array $row): void {
		$this->assertSame(0, self::colInt($row, 'mail_stored'));
		$this->assertAdminFeed($row);
		$this->assertNull($row['mail_location']);
		$this->assertDirectoryDoesNotExist(self::quarantineDir() . '/' . date('Y-m-d') . "/{$this->sent['qid']}");
	}

	/**
	 * The importer enrolls stored discard and reject mail in the admin feed
	 * (admin_notified = 0) while an admin recipient is set, as the harness
	 * does; anything else stays out (NULL).
	 * @param array<string, mixed> $row
	 */
	private function assertAdminFeed(array $row): void {
		$enrolled = self::colInt($row, 'mail_stored') === 1 && in_array($row['action'], ['discard', 'reject'], true);
		$this->assertSame($enrolled ? 0 : null, self::colNullableInt($row, 'admin_notified'),
			'admin_notified (' . ($enrolled ? 'enrolled' : 'not enrolled') . ')');
	}

	/** @param array<string, mixed> $row */
	protected function assertVirus(array $row): void {
		$this->assertSame(1, self::colInt($row, 'has_virus'));
		$this->assertSame(
			[['av' => 'CLAM_VIRUS', 'name' => 'Eicar-Test-Signature']],
			self::query('SELECT av, name FROM mail_log_viruses WHERE mail_log_id = ?', [self::colInt($row, 'id')])
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
			array_map(fn (array $h): array => [self::colInt($h, 'entry_model'), self::colInt($h, 'entry_id')], $hits),
			"map hit for {$symbol}"
		);
		if ($score !== null) {
			$this->assertEqualsWithDelta($score, self::colFloat($hits[0], 'score'), 0.001);
		}
	}

	protected static function quarantineDir(): string {
		return rtrim((string) getenv('E2E_QUARANTINE_DIR'), '/');
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
	 * @return array<array-key, mixed> rspamd's reply
	 */
	protected static function scan(string $mail, array $headers): array {
		$ch = curl_init(rtrim((string) getenv('E2E_RSPAMD_URL'), '/') . '/checkv2') ?: throw new RuntimeException('curl_init() failed');
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

		/** @var mixed $reply */
		$reply = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
		if (!is_array($reply) || !isset($reply['action'], $reply['score'], $reply['symbols'])) {
			throw new RuntimeException("Unexpected rspamd reply: {$body}");
		}
		return $reply;
	}

	/**
	 * Rspamd fetches the map files after it starts: probe each map until its
	 * symbol fires. qa/e2e-tests.sh runs one normal worker, so the worker
	 * probed is the one the tests reach. Probes are not exported.
	 */
	private static function waitForMaps(): void {
		// not a literal to the checkers: the loop unsets probes as their symbols appear
		/** @var array<string, array{string, string, string, list<string>}> $probes */
		$probes = [
			'RQWATCH_IP_BLACKLIST' => ['198.51.100.7', self::MAIL_FROM, self::MIME_FROM, [self::RCPT]],
			'RQWATCH_SMTP_FROM_BLACKLIST' => [self::IP, 'smtp-bl@sender.test', self::MIME_FROM, [self::RCPT]],
			'RQWATCH_MIME_FROM_BLACKLIST' => [self::IP, self::MAIL_FROM, 'Listed <mime-bl@sender.test>', [self::RCPT]],
			'RQWATCH_SMTP_FROM_WHITELIST' => [self::IP, 'smtp-wl@sender.test', self::MIME_FROM, [self::RCPT]],
			'RQWATCH_SMTP_FROM_RCPT_TO_BLACKLIST' => [self::IP, 'pair-bl@sender.test', self::MIME_FROM, ['bob@rqwatch.test']],
		];
		$deadline = microtime(true) + 60.0;
		while ($probes !== []) {
			foreach ($probes as $symbol => [$ip, $from, $mimeFrom, $rcpts]) {
				$qid = 'PROBE' . strtoupper(bin2hex(random_bytes(4)));
				$headers = self::scanHeaders($qid, $ip, $from, $rcpts);
				$headers[] = 'Settings: {symbols_disabled = ["EXPORT_METADATA_RQWATCH"]}';
				$reply = self::scan(self::message($qid, 'Probe', 'clean', $mimeFrom), $headers);
				if (isset(self::symbols($reply)[$symbol])) {
					unset($probes[$symbol]);
				}
			}
			if ($probes !== [] && microtime(true) > $deadline) {
				throw new RuntimeException('rspamd did not load the maps for: ' . implode(', ', array_keys($probes)));
			}
			usleep(500000);
		}
	}

	/** Text of over 1 KB, which rspamd needs to hash a mail; distinct per seed. */
	protected static function fuzzyBody(string $seed): string {
		$words = [];
		for ($i = 0; count($words) < 300; $i++) {
			$words[] = substr(sha1("{$seed}-{$i}"), 0, 4 + $i % 5);
		}
		return wordwrap(implode(' ', $words), 72, "\r\n");
	}

	/**
	 * Our fuzzy symbols (RQWATCH_FUZZY_*) and their scores for a copy of
	 * $body. A probe: not exported.
	 * @return array<string, float>
	 */
	protected static function fuzzyScores(string $body): array {
		$qid = 'PROBE' . strtoupper(bin2hex(random_bytes(4)));
		$headers = self::scanHeaders($qid, self::IP, self::MAIL_FROM, [self::RCPT]);
		$headers[] = 'Settings: {symbols_disabled = ["EXPORT_METADATA_RQWATCH"]}';
		$reply = self::scan(self::message($qid, 'Probe', 'clean', self::MIME_FROM, body: $body), $headers);
		$scores = array_filter(self::symbolScores($reply), fn (string $name): bool => str_starts_with($name, 'RQWATCH_FUZZY_'), ARRAY_FILTER_USE_KEY);
		ksort($scores);
		return $scores;
	}

	// the score of RQWATCH_FUZZY_DENIED (the Spam list) for a copy of $body, null without it
	protected static function fuzzyScore(string $body): ?float {
		return self::fuzzyScores($body)['RQWATCH_FUZZY_DENIED'] ?? null;
	}

	/**
	 * The fuzzy storage applies changes in batches (sync = 1s in the test):
	 * probe until a copy of $body does, or no longer does, hit the learned hash.
	 */
	protected static function waitForFuzzy(string $body, bool $hits): void {
		$deadline = microtime(true) + (float) self::WAIT_SECONDS;
		do {
			if ((self::fuzzyScore($body) !== null) === $hits) {
				return;
			}
			usleep(500000);
		} while (microtime(true) < $deadline);

		throw new RuntimeException('Fuzzy storage did not ' . ($hits ? 'learn' : 'forget') . ' the hash within ' . self::WAIT_SECONDS . 's');
	}

	/**
	 * The mail_logs row of a test mail, by its unique Message-ID, once the
	 * exporter's post has been imported.
	 * @return array<string, mixed>
	 */
	protected static function row(string $messageId): array {
		$deadline = microtime(true) + (float) self::WAIT_SECONDS;
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
		return array_map(fn (array $r): string => self::colString($r, 'recipient_email'), self::query(
			'SELECT recipient_email FROM mail_log_recipients WHERE mail_log_id = ? ORDER BY recipient_email',
			[$id]
		));
	}

	/** Runs bin/cli.php in the instance's copy and returns its output; fails on a non-zero exit. */
	protected static function cli(string ...$args): string {
		$cmd = 'cd ' . escapeshellarg((string) getenv('E2E_ROOT')) . ' && '
			. escapeshellarg(PHP_BINARY) . ' bin/cli.php ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
		exec($cmd, $out, $status);
		$output = implode("\n", $out);
		if ($status !== 0) {
			throw new RuntimeException('bin/cli.php ' . implode(' ', $args) . " exited {$status}: {$output}");
		}
		return $output;
	}

	protected static function db(): PDO {
		return self::$db ??= new PDO(
			(string) getenv('E2E_DB_DSN'),
			(string) getenv('E2E_DB_USER'),
			(string) getenv('E2E_DB_PASS'),
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
		/** @var list<array<string, mixed>> $rows FETCH_ASSOC, set in db() */
		$rows = array_values($stmt->fetchAll());
		return $rows;
	}

	/*
	 Typed reads of a database value, a row's column or a decoded reply's
	 field: another type fails the test, naming it, rather than being cast.
	 MariaDB returns integer columns as ints and FLOAT/DOUBLE as floats
	 (PHP 8.1+).
	*/

	/** @param list<int|string> $params */
	protected static function valueInt(string $sql, array $params): int {
		$stmt = self::db()->prepare($sql);
		$stmt->execute($params);
		/** @var mixed $value */
		$value = $stmt->fetchColumn();
		return is_int($value) ? $value : self::fail("{$sql}: expected an integer, got " . get_debug_type($value));
	}

	/** @param list<int|string> $params */
	protected static function valueString(string $sql, array $params): string {
		$stmt = self::db()->prepare($sql);
		$stmt->execute($params);
		/** @var mixed $value */
		$value = $stmt->fetchColumn();
		return is_string($value) ? $value : self::fail("{$sql}: expected text, got " . get_debug_type($value));
	}

	/** @param array<array-key, mixed> $row */
	protected static function colInt(array $row, string $key): int {
		/** @var mixed $value */
		$value = $row[$key] ?? null;
		return is_int($value) ? $value : self::fail("{$key}: expected an integer, got " . get_debug_type($value));
	}

	/** @param array<array-key, mixed> $row */
	protected static function colNullableInt(array $row, string $key): ?int {
		/** @var mixed $value */
		$value = $row[$key] ?? null;
		return $value === null || is_int($value) ? $value : self::fail("{$key}: expected an integer or NULL, got " . get_debug_type($value));
	}

	/** @param array<array-key, mixed> $row */
	protected static function colString(array $row, string $key): string {
		/** @var mixed $value */
		$value = $row[$key] ?? null;
		return is_string($value) ? $value : self::fail("{$key}: expected text, got " . get_debug_type($value));
	}

	// a number: rspamd sends a whole score as an int, FLOAT/DOUBLE columns come as floats
	/** @param array<array-key, mixed> $row */
	protected static function colFloat(array $row, string $key): float {
		/** @var mixed $value */
		$value = $row[$key] ?? null;
		return is_int($value) || is_float($value) ? (float) $value : self::fail("{$key}: expected a number, got " . get_debug_type($value));
	}

	/**
	 * Rspamd's symbols in a scan reply, by name
	 * @param array<array-key, mixed> $reply
	 * @return array<string, array<array-key, mixed>>
	 */
	protected static function symbols(array $reply): array {
		/** @var mixed $symbols */
		$symbols = $reply['symbols'] ?? null;
		if (!is_array($symbols)) {
			self::fail('rspamd reply without symbols');
		}
		$named = [];
		/** @var mixed $symbol */
		foreach ($symbols as $name => $symbol) {
			if (!is_string($name) || !is_array($symbol)) {
				self::fail('rspamd reply: a symbol that is not name => {...}');
			}
			$named[$name] = $symbol;
		}
		return $named;
	}

	/**
	 * Each symbol's score in a scan reply
	 * @param array<array-key, mixed> $reply
	 * @return array<string, float>
	 */
	protected static function symbolScores(array $reply): array {
		return array_map(fn (array $symbol): float => self::colFloat($symbol, 'score'), self::symbols($reply));
	}

	/**
	 * A JSON list of strings, such as stored hashes ($what names it)
	 * @return list<string>
	 */
	protected static function stringList(string $json, string $what): array {
		/** @var mixed $decoded */
		$decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		if (!is_array($decoded) || !array_is_list($decoded)) {
			self::fail("{$what}: expected a JSON list, got {$json}");
		}
		$list = [];
		/** @var mixed $item */
		foreach ($decoded as $item) {
			$list[] = is_string($item) ? $item : self::fail("{$what}: an element that is not text in {$json}");
		}
		return $list;
	}
}
