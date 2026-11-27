<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 FuzzyService::recordHits() on RecordingDatabase. The learned mails whose
 hashes hold any of the mail's digests get a hit: hits + 1 and last_hit_at
 the later of the old one and the mail's time (NOW() for either missing),
 in one UPDATE; and, once the hit mails migration is running or done, a
 row per learned mail (as INSERT IGNORE) tying it to the mail. The hit is
 logged to file and syslog with the learned mails' qids. No digests, the
 hits migration not done, or no learned mail found: nothing written. A
 failing query is logged, never thrown.
*/

namespace Tests\Unit\Services;

use App\Core\App;
use App\Core\AppContainer;
use App\Core\Database\MigrationStatus;
use App\Inventory\Migrations;
use App\Services\FuzzyService;
use Illuminate\Database\Eloquent\Model;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Unit\RecordingDatabase;
use Tests\Unit\UnitTestCase;

final class FuzzyHitRecordTest extends UnitTestCase
{
	private const string UPDATE = 'UPDATE mail_log_fuzzy SET hits = hits + 1, '
		. 'last_hit_at = GREATEST(COALESCE(last_hit_at, COALESCE(?, NOW())), COALESCE(?, NOW())) WHERE id IN ';

	private RecordingDatabase $db;

	private MigrationStatus $status;

	/** @var list<array<string, mixed>> the learned mails the lookup finds */
	private array $found = [['id' => 4, 'qid' => 'L4'], ['id' => 9, 'qid' => 'L9']];

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->db = new RecordingDatabase($this->createStub(PDO::class));
		$this->db->capsule->bootEloquent();
		$this->db->answer = function (string $sql, array $bindings): array {
			if (str_contains($sql, 'from `mail_log_fuzzy`')) {
				return $this->found;
			}
			throw new RuntimeException("Unexpected query: {$sql}");
		};
		$this->status = new MigrationStatus(null, $this->log);
		$this->status->setMigrationState(Migrations::MAIL_LOG_FUZZY_HITS, Migrations::STATUS_COMPLETED);
		$this->status->setMigrationState(Migrations::MAIL_LOG_FUZZY_HITS_MAILS, Migrations::STATUS_COMPLETED);
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, $this->db->capsule, $this->status, null, false));
	}

	#[\Override]
	protected function tearDown(): void {
		Model::unsetConnectionResolver();
		RecordingDatabase::forget();
		parent::tearDown();
	}

	public function testRecorded(): void {
		$a = str_repeat('a1', 64);
		$b = str_repeat('0f', 64);
		(new FuzzyService())->recordHits(7, [$a, 'junk', $b, $a], '2026-11-20 10:00:00', 'Q1');
		$this->assertSame([[
			'select `id`, `qid` from `mail_log_fuzzy` where JSON_CONTAINS(hashes, JSON_QUOTE(?)) or JSON_CONTAINS(hashes, JSON_QUOTE(?))',
			[$a, $b],
		]], $this->db->selects);
		$this->assertSame([[self::UPDATE . '(?,?)', ['2026-11-20 10:00:00', '2026-11-20 10:00:00', 4, 9]]], $this->db->changes);
		$this->assertSame([['fuzzy_id' => 4, 'mail_log_id' => 7], ['fuzzy_id' => 9, 'mail_log_id' => 7]], $this->db->rows('mail_log_fuzzy_hits'));
		$this->assertSame([
			'info Q1 fuzzy hit on learned mail(s) L4, L9',
			'info Q1 fuzzy hit on learned mail(s) L4, L9',
		], $this->log->lines);
	}

	// one learned mail, no time: NOW() in the UPDATE; the hit mails table being filled
	public function testOneWithoutTime(): void {
		$this->found = [['id' => 4, 'qid' => 'L4']];
		$this->status->setMigrationState(Migrations::MAIL_LOG_FUZZY_HITS_MAILS, Migrations::STATUS_RUNNING);
		(new FuzzyService())->recordHits(7, [str_repeat('a1', 64)], null, 'Q1');
		$this->assertSame([[self::UPDATE . '(?)', [null, null, 4]]], $this->db->changes);
		$this->assertSame([['fuzzy_id' => 4, 'mail_log_id' => 7]], $this->db->rows('mail_log_fuzzy_hits'));
		$this->assertSame('info Q1 fuzzy hit on learned mail(s) L4', $this->log->lines[0] ?? null);
	}

	/** @return array<string, array{?string}> */
	public static function mailsNotWritable(): array {
		return [
			'pending' => [Migrations::STATUS_PENDING],
			'failed' => [Migrations::STATUS_FAILED],
			'unknown' => [null],
		];
	}

	// the hit mails table not there yet: the learned mails' hits only
	#[DataProvider('mailsNotWritable')]
	public function testMailsNotWritable(?string $state): void {
		$this->status->setMigrationState(Migrations::MAIL_LOG_FUZZY_HITS_MAILS, $state);
		(new FuzzyService())->recordHits(7, [str_repeat('a1', 64)], null, 'Q1');
		$this->assertCount(1, $this->db->changes);
		$this->assertSame([], $this->db->rows('mail_log_fuzzy_hits'));
		$this->assertCount(2, $this->log->lines);
	}

	public function testNoDigests(): void {
		(new FuzzyService())->recordHits(7, ['junk', 5], null, 'Q1');
		$this->assertSame([[], [], [], []], [$this->db->selects, $this->db->changes, $this->db->rows('mail_log_fuzzy_hits'), $this->log->lines]);
	}

	public function testHitsMigrationNotDone(): void {
		$this->status->setMigrationState(Migrations::MAIL_LOG_FUZZY_HITS, Migrations::STATUS_RUNNING);
		(new FuzzyService())->recordHits(7, [str_repeat('a1', 64)], null, 'Q1');
		$this->assertSame([[], [], [], []], [$this->db->selects, $this->db->changes, $this->db->rows('mail_log_fuzzy_hits'), $this->log->lines]);
	}

	public function testNoneFound(): void {
		$this->found = [];
		(new FuzzyService())->recordHits(7, [str_repeat('a1', 64)], null, 'Q1');
		$this->assertCount(1, $this->db->selects);
		$this->assertSame([[], [], []], [$this->db->changes, $this->db->rows('mail_log_fuzzy_hits'), $this->log->lines]);
	}

	public function testFailureLogged(): void {
		$this->db->answer = fn (): never => throw new RuntimeException('server has gone away');
		(new FuzzyService())->recordHits(7, [str_repeat('a1', 64)], null, 'Q1');
		$this->assertSame(['error [FuzzyService_recordHits] Q1 could not record fuzzy hits: server has gone away'], $this->log->lines);
	}
}
