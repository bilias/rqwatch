<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Fuzzy hits recorded at import, without rspamd: mails posted to the importer
 with the fuzzy_hashes rspamd's metadata_exporter would send. Every learned
 mail holding a matched digest is credited once per mail, a digest no learned
 mail holds writes nothing, last_hit_at never moves back, and the hits page
 lists the mails behind a record's hits.
*/

namespace Tests\Integration\Fuzzy;

use CURLStringFile;
use PDO;
use RuntimeException;
use Tests\Integration\IntegrationTestCase;

final class HitRecordingTest extends IntegrationTestCase
{
	// learned mails
	private const A = 9801;
	private const B = 9802;
	private const C = 9803;
	// imported mails' qids start with this
	private const QID = 'QIDFZHIT';

	/** @var array<int, int> learned mail id => its mail_log_fuzzy id */
	private array $records = [];

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		// A and B share digest 2; C's last hit is in the future
		$this->learned(self::A, 11, [self::hash(1), self::hash(2)]);
		$this->learned(self::B, 11, [self::hash(2), self::hash(3)]);
		$this->learned(self::C, 13, [self::hash(4)], '2099-01-01 00:00:00');
	}

	#[\Override]
	protected function tearDown(): void {
		// hits rows and the imported mails' child rows cascade
		self::db()->exec("DELETE FROM mail_logs WHERE qid LIKE '" . self::QID . "%'");
		self::db()->exec('DELETE FROM mail_log_fuzzy WHERE mail_log_id IN (' . implode(',', [self::A, self::B, self::C]) . ')');
		parent::tearDown();
	}

	public function testEveryHolderCreditedOncePerMail(): void {
		// two of A's digests, one of them also B's
		$id = $this->import(1, [self::hash(1), self::hash(2)]);
		$this->assertHits([self::A => 1, self::B => 1, self::C => 0]);
		$this->assertSame([[$this->records[self::A], $id], [$this->records[self::B], $id]], self::hitRows());
		foreach ([self::A, self::B] as $learned) {
			$this->assertNotNull(self::value('SELECT last_hit_at FROM mail_log_fuzzy WHERE id = ?', $this->records[$learned]));
		}
	}

	// rspamd.com's digests and malformed entries
	public function testNoLearnedDigestWritesNothing(): void {
		$before = self::snapshot();
		$this->import(1, [self::hash(9), 'abc', 123, strtoupper(self::hash(1)), self::hash(1) . 'a']);
		$this->assertSame($before, self::snapshot());
		$this->assertSame([], self::hitRows());
	}

	public function testLastHitNeverMovesBack(): void {
		$this->import(1, [self::hash(4)]);
		$this->assertHits([self::A => 0, self::B => 0, self::C => 1]);
		$this->assertSame('2099-01-01 00:00:00', self::value('SELECT last_hit_at FROM mail_log_fuzzy WHERE id = ?', $this->records[self::C]));
	}

	public function testHitsPage(): void {
		$this->import(1, [self::hash(1)]);
		$this->import(2, [self::hash(1)]);
		$this->import(3, [self::hash(3)]);
		// one more hit counted on a mail since purged
		self::db()->prepare('UPDATE mail_log_fuzzy SET hits = hits + 1 WHERE id = ?')->execute([$this->records[self::A]]);

		$response = self::client('admin')->get("/admin/fuzzy/hits/{$this->records[self::A]}");
		$this->assertSame(200, $response['status']);
		$this->assertStringContainsString('SUBJ-FZHIT-1', $response['body']);
		$this->assertStringContainsString('SUBJ-FZHIT-2', $response['body']);
		$this->assertStringNotContainsString('SUBJ-FZHIT-3', $response['body'], "B's hit listed under A");
		$this->assertStringContainsString('2 of 3 hits listed', $response['body']);
	}

	/** @param list<string> $hashes */
	private function learned(int $id, int $flag, array $hashes, ?string $lastHit = null): void {
		$qid = $this->quarantined($id, 'mx1');
		self::db()->prepare('INSERT INTO mail_log_fuzzy (mail_log_id, qid, api_server, flag, weight, hashes, learned_by, last_hit_at)'
			. ' VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
			->execute([$id, $qid, 'mx1', $flag, 10, json_encode($hashes), 'admin', $lastHit]);
		$this->records[$id] = (int) self::db()->lastInsertId();
	}

	// a 128-hex-digit digest with letters, distinct per $n
	private static function hash(int $n): string {
		return str_repeat(sprintf('%02x', 0xa0 + $n), 64);
	}

	/**
	 * Posts mail $n to the importer as rspamd would, with $fuzzy as its
	 * fuzzy_hashes; returns its mail_logs id.
	 * @param list<int|string> $fuzzy
	 */
	private function import(int $n, array $fuzzy): int {
		$qid = self::QID . $n;
		$meta = [
			'qid' => $qid, 'score' => 1, 'action' => 'no action', 'ip' => '192.0.2.40',
			'from' => 'fuzzy-hit@example.net', 'rcpt' => ['admin@example.org'], 'fuzzy' => $fuzzy,
		];
		$mail = "From: fuzzy-hit@example.net\r\nTo: admin@example.org\r\nSubject: SUBJ-FZHIT-{$n}\r\n"
			. "Message-ID: <fzhit-{$n}@example.net>\r\n\r\nBody {$n}\r\n";
		$ch = curl_init(rtrim((string) getenv('INTEGRATION_API_URL'), '/') . '/api/metadata_importer_multipart.php?server=mx1')
			?: throw new RuntimeException('curl_init() failed');
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POSTFIELDS => [
				'metadata' => json_encode($meta, JSON_THROW_ON_ERROR),
				'message' => new CURLStringFile($mail, 'message', 'message/rfc822'),
			],
			CURLOPT_USERPWD => (string) getenv('INTEGRATION_RSPAMD_API_USER') . ':' . (string) getenv('INTEGRATION_RSPAMD_API_PASS'),
			CURLOPT_TIMEOUT => 30,
		]);
		$body = curl_exec($ch);
		$this->assertSame([200, 'Message saved'], [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $body], "import of {$qid}");
		return self::valueInt('SELECT id FROM mail_logs WHERE qid = ?', $qid);
	}

	/** @param array<int, int> $expected learned mail id => hits */
	private function assertHits(array $expected): void {
		$hits = [];
		foreach (array_keys($expected) as $learned) {
			$hits[$learned] = self::valueInt('SELECT hits FROM mail_log_fuzzy WHERE id = ?', $this->records[$learned]);
		}
		$this->assertSame($expected, $hits);
	}

	/** @return list<array{int, int}> fuzzy_id, mail_log_id of this test's hits */
	private static function hitRows(): array {
		$rows = self::db()->query("SELECT h.fuzzy_id, h.mail_log_id FROM mail_log_fuzzy_hits h"
			. " JOIN mail_logs m ON m.id = h.mail_log_id WHERE m.qid LIKE '" . self::QID . "%' ORDER BY h.fuzzy_id")
			?: throw new RuntimeException('hits query failed');
		$hits = [];
		foreach ($rows->fetchAll(PDO::FETCH_NUM) as $row) {
			$hits[] = [(int) $row[0], (int) $row[1]];
		}
		return $hits;
	}

	private static function snapshot(): ?string {
		return self::valueNullableString('SELECT JSON_ARRAYAGG(JSON_ARRAY(id, hits, last_hit_at, updated_at)) FROM mail_log_fuzzy');
	}
}
