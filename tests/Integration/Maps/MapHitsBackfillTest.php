<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 db:map_hits_backfill records a mail's map hits again from its stored
 symbols, replacing the ones it had: the last 31 days, or the whole log
 with -a, and only with -r. The test makes its own entries in
 mail_from_rcpt_to_whitelist, read by RQWATCH_SMTP_FROM_RCPT_TO_WHITELIST
 in config.php's map_symbols: other tests change the fixture ones (a
 user's delete-all removes alice's entry 201).
*/

namespace Tests\Integration\Maps;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\Attributes\TestDox;
use RuntimeException;
use Tests\Integration\IntegrationTestCase;

final class MapHitsBackfillTest extends IntegrationTestCase
{
	private const string WHITELIST = 'RQWATCH_SMTP_FROM_RCPT_TO_WHITELIST';
	// matched by local_symbol_patterns, read by no map
	private const string LOCAL = 'LOCAL_BACKFILL_TEST';
	private const string STALE = 'STALE_BACKFILL_TEST';

	/** @var array{alice: int, bob: int} whitelist entry ids */
	private array $entries;

	// mail, symbol, entry model and id, score, and whether the hit is on the mail's own day
	private const string HITS = 'SELECT CONCAT_WS(" ", h.mail_log_id, s.name, h.entry_model, h.entry_id, IFNULL(h.score, "-"),'
		. ' h.created_day = ml.created_day) FROM mail_log_symbol_hits h JOIN symbol_names s ON s.id = h.symbol_id'
		. ' JOIN mail_logs ml ON ml.id = h.mail_log_id WHERE h.mail_log_id BETWEEN 9501 AND 9504 ORDER BY h.mail_log_id, s.name';

	#[\Override]
	protected function tearDown(): void {
		// the mails go, and their hits with them
		parent::tearDown();
		if (getenv('INTEGRATION_BASE_URL') !== false) {
			self::db()->exec("DELETE FROM symbol_names WHERE name IN ('" . self::LOCAL . "', '" . self::STALE . "')");
			self::db()->exec("DELETE FROM maps_combined WHERE mail_from LIKE 'backfill-%'");
		}
	}

	#[TestDox('Without -r the mails are only counted: no hit written or removed')]
	public function testDryRun(): void {
		$this->mails();

		$this->assertStringContainsString('Use -r to record their hits', self::cli('db:map_hits_backfill', '-v'));
		self::cli('db:map_hits_backfill', '-a');

		$this->assertSame(['9503 ' . self::STALE . ' 0 0 - 1'], self::hits());
	}

	#[TestDox('-r: hits again from the stored symbols, the old ones replaced, for the last 31 days; -a: the whole log')]
	public function testBackfill(): void {
		$this->mails();
		$recent = [
			'9501 ' . self::WHITELIST . " 1 {$this->entries['alice']} -10 1",
			'9502 ' . self::LOCAL . ' 0 0 2.5 1',
		];

		self::cli('db:map_hits_backfill', '-r', '-b', '2');
		$this->assertSame($recent, self::hits(), 'last 31 days: 9503 has no map symbol left, 9504 is older');

		self::cli('db:map_hits_backfill', '-r', '-a', '-b', '2');
		$this->assertSame([...$recent, '9504 ' . self::WHITELIST . " 1 {$this->entries['bob']} -10 1"], self::hits(), 'whole log, recent hits not doubled');
	}

	// two whitelist entries; two map hits and a local symbol in the last 31 days, one hit older, one stale hit
	private function mails(): void {
		foreach (['alice', 'bob'] as $user) {
			self::db()->prepare('INSERT INTO maps_combined (map_name, user_id, mail_from, rcpt_to)'
				. ' SELECT ?, id, ?, ? FROM users WHERE username = ?')
				->execute(['mail_from_rcpt_to_whitelist', "backfill-{$user}@example.net", "{$user}@example.org", $user]);
			$this->entries[$user] = (int) self::db()->lastInsertId();
		}

		$whitelisted = fn (string $option): array => [
			self::WHITELIST => ['name' => self::WHITELIST, 'score' => -10, 'options' => [$option]],
			'TEST_SYMBOL' => ['name' => 'TEST_SYMBOL', 'score' => 15],
		];
		$this->quarantined(9501, 'mx1', ['alice@example.org'], [], $whitelisted('backfill-alice@example.net|alice@example.org'));
		$this->quarantined(9502, 'mx1', ['alice@example.org'], [], [self::LOCAL => ['name' => self::LOCAL, 'score' => 2.5]]);
		$this->quarantined(9503, 'mx1');
		$this->quarantined(9504, 'mx1', ['bob@example.org'],
			['created_at' => (new DateTimeImmutable('-40 days'))->format('Y-m-d H:i:s')],
			$whitelisted('backfill-bob@example.net|bob@example.org'));

		// a hit 9503's symbols no longer give, as after a map's symbols changed
		self::db()->exec("INSERT INTO symbol_names (name) VALUES ('" . self::STALE . "')");
		self::db()->exec('INSERT INTO mail_log_symbol_hits (symbol_id, created_day, mail_log_id)'
			. " SELECT s.id, ml.created_day, ml.id FROM symbol_names s JOIN mail_logs ml ON ml.id = 9503 WHERE s.name = '" . self::STALE . "'");
	}

	/** @return list<string> */
	private static function hits(): array {
		$stmt = self::db()->query(self::HITS);
		if ($stmt === false) {
			throw new RuntimeException('Cannot read the map hits');
		}
		$hits = [];
		foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $hit) {
			$hits[] = (string) $hit;
		}
		return $hits;
	}
}
