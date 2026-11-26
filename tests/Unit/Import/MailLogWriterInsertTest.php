<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 MailLogWriter::insert() on a database that records its INSERTs: the mail
 goes to mail_logs, its headers, symbols and fuzzy hashes to mail_log_data
 under the new id, and each recipient once, lower-case and trimmed, blanks
 left out, to mail_log_recipients. A failed insert reaches the caller.
 After the commit the fuzzy, map and virus records run (a virus record only
 for a virus mail); what they cannot read is logged and the id still
 returned. With no migration completed they query nothing; with map hits
 completed, the map record asks for the custom maps.
*/

namespace Tests\Unit\Import;

use App\Core\App;
use App\Core\AppContainer;
use App\Core\Database\MigrationStatus;
use App\Inventory\Migrations;
use App\Services\Import\MailLogWriter;
use PDO;
use RuntimeException;
use Tests\Unit\RecordingDatabase;
use Tests\Unit\UnitTestCase;

final class MailLogWriterInsertTest extends UnitTestCase
{
	private RecordingDatabase $db;

	private MigrationStatus $status;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->db = new RecordingDatabase($this->createStub(PDO::class));
		$this->status = new MigrationStatus(null, $this->log);
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, $this->db->capsule, $this->status, null, false));
	}

	#[\Override]
	protected function tearDown(): void {
		RecordingDatabase::forget();
		parent::tearDown();
	}

	public function testInsert(): void {
		$id = (new MailLogWriter())->insert(
			['qid' => 'Q1', 'score' => 7.5, 'symbols' => '{}', 'headers' => 'Subject: hi', 'fuzzy_hashes' => '[]'],
			[' Alice@Example.org ', '', 'bob@example.org', 'bob@example.org', "\t"]
		);
		$this->assertSame(101, $id);
		$this->assertSame([['qid' => 'Q1', 'score' => 7.5]], $this->db->rows('mail_logs'));
		$this->assertSame([['symbols' => '{}', 'headers' => 'Subject: hi', 'fuzzy_hashes' => '[]', 'mail_log_id' => 101]], $this->db->rows('mail_log_data'));
		$this->assertSame([
			['mail_log_id' => 101, 'recipient_email' => 'alice@example.org'],
			['mail_log_id' => 101, 'recipient_email' => 'bob@example.org'],
		], $this->db->rows('mail_log_recipients'));
		$this->assertSame([], $this->log->lines);
	}

	// none, or only blanks: no recipient row, the mail still written
	public function testNoRecipients(): void {
		$writer = new MailLogWriter();
		$this->assertSame([101, 102], [$writer->insert(['qid' => 'Q1'], []), $writer->insert(['qid' => 'Q2'], ['', ' '])]);
		$this->assertSame([['qid' => 'Q1'], ['qid' => 'Q2']], $this->db->rows('mail_logs'));
		$this->assertSame([], $this->db->rows('mail_log_recipients'));
	}

	public function testInsertFails(): void {
		$this->db->failInsert['Q1'] = new RuntimeException('Duplicate entry');
		try {
			(new MailLogWriter())->insert(['qid' => 'Q1', 'has_virus' => 1, 'symbols' => 5], ['bob@example.org']);
			$this->fail('no exception');
		} catch (RuntimeException $e) {
			$this->assertSame('Duplicate entry', $e->getMessage());
		}
		$this->assertSame([[], []], [$this->db->rows('mail_log_recipients'), $this->log->lines], 'no record after a failed insert');
	}

	// after the commit: each record logs what it cannot read, the id is returned
	public function testRecordsAfterTheCommit(): void {
		$id = (new MailLogWriter())->insert(
			['qid' => 'Q1', 'has_virus' => 1, 'created_at' => 1700000000, 'symbols' => 5, 'fuzzy_hashes' => '["abc"]'],
			['bob@example.org']
		);
		$this->assertSame(101, $id);
		$this->assertSame([
			'error [MailLogWriter] Q1 could not record fuzzy hits: created_at is int, not text',
			'error [MailLogWriter] Q1 could not record map hits: symbols is int, not text',
			'error [MailLogWriter] Q1 could not record viruses: symbols is int, not text',
		], $this->log->lines);
	}

	public function testNoVirusRecordForOtherMail(): void {
		(new MailLogWriter())->insert(['qid' => 'Q1', 'has_virus' => 0, 'symbols' => 5], []);
		$this->assertSame(['error [MailLogWriter] Q1 could not record map hits: symbols is int, not text'], $this->log->lines);
	}

	// with map hits on, the record asks for the custom maps: here, with no Eloquent connection, logged
	public function testMapHitsRecorded(): void {
		$this->status->setMigrationState(Migrations::MAP_HITS, Migrations::STATUS_COMPLETED);
		$this->assertSame(101, (new MailLogWriter())->insert(['qid' => 'Q1', 'symbols' => '{}'], []));
		$this->assertSame(['error [MapHitService_recordHits] Q1 could not record map hits: Call to a member function connection() on null'], $this->log->lines);
	}
}
