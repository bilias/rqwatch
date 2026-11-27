<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The viruses of a mail, from its symbols: each antivirus symbol's name with
 each of its options (an option that is not text as ''; none as one ''),
 each pair once, the scanner cut to 128 characters and the name to 255.
 Other symbols, and what is not a symbol, are left out. The virus report
 leaves out the Date filter, its windows being the dates; for display it
 marks it. Recording a mail's viruses waits for their table (its migration
 RUNNING), skips a mail with none, and never throws: a failure is logged.
 On RecordingDatabase: the rows go in (as INSERT IGNORE) on the mail's own
 day, as stored; a mail that is gone is logged, nothing recorded.
*/

namespace Tests\Unit\Services;

use App\Core\App;
use App\Core\AppContainer;
use App\Core\Database\MigrationStatus;
use App\Inventory\Migrations;
use App\Services\VirusService;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Unit\RecordingDatabase;
use Tests\Unit\UnitTestCase;

final class VirusServiceTest extends UnitTestCase
{
	/** @return array<string, array{mixed, list<array{av: string, name: string}>}> */
	public static function symbols(): array {
		return [
			'two scanners' => [[
				['name' => 'CLAM_VIRUS', 'group' => 'antivirus', 'options' => ['Eicar-Signature']],
				['name' => 'BAYES_SPAM', 'group' => 'statistics', 'options' => ['99%']],
				['name' => 'SOPHOS', 'group' => 'antivirus', 'options' => ['EICAR-AV-Test', 'Troj/X']],
			], [
				['av' => 'CLAM_VIRUS', 'name' => 'Eicar-Signature'],
				['av' => 'SOPHOS', 'name' => 'EICAR-AV-Test'],
				['av' => 'SOPHOS', 'name' => 'Troj/X'],
			]],
			'no options: one unnamed' => [[['name' => 'CLAM_VIRUS', 'group' => 'antivirus']], [['av' => 'CLAM_VIRUS', 'name' => '']]],
			'empty options: one unnamed' => [[['name' => 'CLAM_VIRUS', 'group' => 'antivirus', 'options' => []]], [['av' => 'CLAM_VIRUS', 'name' => '']]],
			'an option not text: unnamed' => [[['name' => 'CLAM_VIRUS', 'group' => 'antivirus', 'options' => [['x'], 7]]],
				[['av' => 'CLAM_VIRUS', 'name' => ''], ['av' => 'CLAM_VIRUS', 'name' => '7']]],
			'one option as text' => [[['name' => 'CLAM_VIRUS', 'group' => 'antivirus', 'options' => 'Eicar']], [['av' => 'CLAM_VIRUS', 'name' => 'Eicar']]],
			'a pair once' => [[
				['name' => 'CLAM_VIRUS', 'group' => 'antivirus', 'options' => ['Eicar', 'Eicar']],
				['name' => 'CLAM_VIRUS', 'group' => 'antivirus', 'options' => ['Eicar']],
			], [['av' => 'CLAM_VIRUS', 'name' => 'Eicar']]],
			'a scanner named by a number' => [[['name' => 42, 'group' => 'antivirus', 'options' => ['x']]], [['av' => '42', 'name' => 'x']]],
			'no name, or not text' => [[
				['group' => 'antivirus', 'options' => ['x']],
				['name' => ['CLAM'], 'group' => 'antivirus', 'options' => ['x']],
			], []],
			'not a symbol' => [['CLAM_VIRUS', null], []],
			'no group' => [[['name' => 'CLAM_VIRUS', 'options' => ['x']]], []],
			'not symbols' => ['[]', []],
			'none' => [[], []],
		];
	}

	/** @param list<array{av: string, name: string}> $viruses */
	#[DataProvider('symbols')]
	public function testExtractViruses(mixed $symbols, array $viruses): void {
		// without a warning: a symbol may have no group or options
		set_error_handler(fn (int $no, string $message): never => throw new \ErrorException($message, 0, $no));
		try {
			$this->assertSame($viruses, VirusService::extractViruses($symbols));
		} finally {
			restore_error_handler();
		}
	}

	// in characters: a multi-byte name is not cut mid-character
	public function testCut(): void {
		$viruses = VirusService::extractViruses([['name' => str_repeat('Ω', 130), 'group' => 'antivirus', 'options' => [str_repeat('ü', 300)]]]);
		$this->assertSame([['av' => str_repeat('Ω', 128), 'name' => str_repeat('ü', 255)]], $viruses);
	}

	/** @return array<array-key, array{filter: string, choice: string, value: string}> */
	private static function filters(): array {
		return [
			3 => ['filter' => 'Date', 'choice' => 'is equal to', 'value' => '2026-11-26'],
			5 => ['filter' => 'Subject', 'choice' => 'contains', 'value' => 'invoice'],
			8 => ['filter' => 'date', 'choice' => 'contains', 'value' => 'x'],
		];
	}

	public function testReportFilters(): void {
		$this->assertSame([
			['filter' => 'Subject', 'choice' => 'contains', 'value' => 'invoice'],
			['filter' => 'date', 'choice' => 'contains', 'value' => 'x'],
		], VirusService::reportFilters(self::filters()));
	}

	public function testMarkIgnoredFilters(): void {
		$this->assertSame([
			3 => ['filter' => 'Date', 'choice' => 'is equal to', 'value' => '2026-11-26', 'ignored' => true],
			5 => ['filter' => 'Subject', 'choice' => 'contains', 'value' => 'invoice', 'ignored' => false],
			8 => ['filter' => 'date', 'choice' => 'contains', 'value' => 'x', 'ignored' => false],
		], VirusService::markIgnoredFilters(self::filters()));
	}

	private const string VIRUS = '[{"name":"CLAM_VIRUS","group":"antivirus","options":["Eicar"]}]';

	private function withState(?string $state, ?RecordingDatabase $db = null): void {
		$status = new MigrationStatus(null, $this->log);
		$status->setMigrationState(Migrations::MAIL_LOG_VIRUSES, $state);
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, $db?->capsule, $status, null, false));
	}

	#[\Override]
	protected function tearDown(): void {
		RecordingDatabase::forget();
		parent::tearDown();
	}

	/** @param list<array<string, mixed>> $mails the mail_logs rows the day's lookup finds */
	private function database(array $mails): RecordingDatabase {
		$db = new RecordingDatabase($this->createStub(PDO::class));
		$db->answer = fn (): array => $mails;
		$this->withState(Migrations::STATUS_RUNNING, $db);
		return $db;
	}

	// on the mail's own day, each scanner and name once
	public function testRecorded(): void {
		$db = $this->database([['created_day' => '2026-11-20']]);
		(new VirusService())->recordViruses(7, '[{"name":"CLAM_VIRUS","group":"antivirus","options":["Eicar","Eicar"]},{"name":"SOPHOS","group":"antivirus"}]', 'Q1');
		$this->assertSame([['select `created_day` from `mail_logs` where `id` = ? limit 1', [7]]], $db->selects);
		$this->assertSame([
			['av' => 'CLAM_VIRUS', 'created_day' => '2026-11-20', 'mail_log_id' => 7, 'name' => 'Eicar'],
			['av' => 'SOPHOS', 'created_day' => '2026-11-20', 'mail_log_id' => 7, 'name' => ''],
		], $db->rows('mail_log_viruses'));
		$this->assertSame([[], []], [$db->changes, $this->log->lines]);
	}

	public function testMailGone(): void {
		$db = $this->database([]);
		(new VirusService())->recordViruses(7, self::VIRUS, 'Q1');
		$this->assertSame([[], ['error [VirusService_recordViruses] Q1 mail id 7 not found']], [$db->rows('mail_log_viruses'), $this->log->lines]);
	}

	// no database here: reaching it would be logged
	public function testNotRecordedBeforeTheTable(): void {
		$this->withState(Migrations::STATUS_PENDING);
		(new VirusService())->recordViruses(7, self::VIRUS, 'Q1');
		$this->assertSame([], $this->log->lines);
	}

	/** @return array<string, array{string}> */
	public static function noViruses(): array {
		return ['other symbols' => ['[{"name":"BAYES_SPAM","group":"statistics"}]'], 'not JSON' => ['{'], 'none' => ['[]']];
	}

	#[DataProvider('noViruses')]
	public function testNothingToRecord(string $symbols): void {
		$this->withState(Migrations::STATUS_RUNNING);
		(new VirusService())->recordViruses(7, $symbols, 'Q1');
		$this->assertSame([], $this->log->lines);
	}

	public function testFailureLogged(): void {
		$this->withState(Migrations::STATUS_COMPLETED);
		(new VirusService())->recordViruses(7, self::VIRUS, 'Q1');
		$this->assertSame(['error [VirusService_recordViruses] Q1 could not record viruses: Database capsule is not available in this context.'], $this->log->lines);
	}

	public function testNoMigrationStatusLogged(): void {
		(new VirusService())->recordViruses(7, self::VIRUS, 'Q1');
		$this->assertSame(['error [VirusService_recordViruses] Q1 could not record viruses: MigrationStatus is not available in this context.'], $this->log->lines);
	}
}
