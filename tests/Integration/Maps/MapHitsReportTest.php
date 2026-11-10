<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The map hit reports over HTTP, read from hits seeded directly: three mails
 credited to one mail_from_whitelist entry (today, 3 and 20 days ago, one
 action each) and one mail hitting an unmapped symbol today. Every count is
 distinct, so a value read wrongly shows: the report's windows and last
 day, the outcome per action and per day, the mail lists of the map, the
 entry and the symbol, the entry's hits on the map page and the map link
 on a mail's detail page. The reports turn a query error into a flash and
 a redirect, so each page is also checked to render (200).
*/

namespace Tests\Integration\Maps;

use PHPUnit\Framework\Attributes\TestDox;
use Tests\Integration\IntegrationTestCase;

final class MapHitsReportTest extends IntegrationTestCase
{
	private const string MAP = 'mail_from_whitelist';
	private const string MAPPED = 'RQ_HITS_TEST_MAPPED';
	private const string UNMAPPED = 'RQ_HITS_TEST_UNMAPPED';
	private const array MAILS = [
		// qid => [action, days ago, released, notified, sender]
		'QIDHITS1' => ['reject', 0, 0, 1, 'hits-a@example.net'],
		'QIDHITS2' => ['discard', 3, 1, 0, 'hits-b@example.net'],
		'QIDHITS3' => ['no action', 20, 0, 0, 'hits-c@example.net'],
		'QIDHITS4' => ['reject', 0, 0, 0, 'hits-d@example.net'],
	];

	private int $entry;
	private int $mail;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$db = self::db();
		$db->prepare("INSERT INTO maps_combined (map_name, user_id, mail_from, created_at, updated_at)"
			. " SELECT ?, id, 'hits-entry@example.net', NOW(), NOW() FROM users WHERE username = 'admin'")->execute([self::MAP]);
		$this->entry = (int) $db->lastInsertId();
		$db->prepare('INSERT INTO symbol_names (name) VALUES (?), (?)')->execute([self::MAPPED, self::UNMAPPED]);

		$mail = $db->prepare("INSERT INTO mail_logs (qid, server, action, score, mail_from, rcpt_to, created_at, mail_stored, released, notified)"
			. " VALUES (?, 'mx1', ?, 15, ?, 'alice@example.org', NOW() - INTERVAL ? DAY, 0, ?, ?)");
		$hit = $db->prepare('INSERT INTO mail_log_symbol_hits (mail_log_id, symbol_id, entry_model, entry_id, created_day)'
			. ' SELECT ?, id, ?, ?, CURDATE() - INTERVAL ? DAY FROM symbol_names WHERE name = ?');
		foreach (self::MAILS as $qid => [$action, $ago, $released, $notified, $sender]) {
			$mail->execute([$qid, $action, $sender, $ago, $released, $notified]);
			$id = (int) $db->lastInsertId();
			$unmapped = $qid === 'QIDHITS4';
			$hit->execute([$id, $unmapped ? 0 : 1, $unmapped ? 0 : $this->entry, $ago, $unmapped ? self::UNMAPPED : self::MAPPED]);
			if ($qid === 'QIDHITS1') {
				$this->mail = $id;
				$db->prepare("INSERT INTO mail_log_data (mail_log_id, headers, symbols, fuzzy_hashes) VALUES (?, '', ?, '[]')")
					->execute([$id, '[{"name":"' . self::MAPPED . '","score":1}]']);
			}
		}
	}

	#[\Override]
	protected function tearDown(): void {
		$db = self::db();
		$qids = implode(',', array_fill(0, count(self::MAILS), '?'));
		$db->prepare("DELETE FROM mail_logs WHERE qid IN ({$qids})")->execute(array_keys(self::MAILS));
		$db->prepare('DELETE FROM symbol_names WHERE name IN (?, ?)')->execute([self::MAPPED, self::UNMAPPED]);
		$db->prepare('DELETE FROM maps_combined WHERE id = ?')->execute([$this->entry]);
		parent::tearDown();
	}

	#[TestDox('The report: the map per window and its last day, the unmapped symbol per window')]
	public function testReport(): void {
		$page = self::client('admin')->get('/admin/maps/hits');
		$this->assertSame(200, $page['status']);
		foreach ([1 => 1, 7 => 2, 31 => 3] as $days => $mails) {
			$this->assertStringContainsString("mails?map=" . self::MAP . "&amp;days={$days}\">{$mails}</a>", $page['body'], "{$days} days");
			$this->assertStringContainsString("mails?symbol=" . self::UNMAPPED . "&amp;days={$days}\">1</a>", $page['body'], "unmapped, {$days} days");
		}
		$this->assertStringContainsString("<td class='center'>" . self::day(0) . '</td>', $page['body'], 'last hit: today');
	}

	#[TestDox('The outcome: mails, released and notified per action, and the mails per day')]
	public function testOutcome(): void {
		$page = self::client('admin')->get('/admin/maps/hits/outcome?map=' . self::MAP . '&days=31');
		$this->assertSame(200, $page['status']);
		$mails = '/admin/maps/hits/mails?map=' . self::MAP . '&amp;days=31';
		$links = [
			'&amp;action=discard">1', '&amp;action=discard&amp;released=1">1',
			'&amp;action=reject">1', '&amp;action=reject&amp;notified=1">1',
			'&amp;action=no%20action">1',
			// the totals
			'">3', '&amp;released=1">1', '&amp;notified=1">1',
		];
		foreach ($links as $link) {
			$this->assertStringContainsString("{$mails}{$link}</a>", $page['body'], $link);
		}
		$daily = array_fill(0, 31, 0);
		foreach ([20, 3, 0] as $ago) {
			$daily[30 - $ago] = 1;
		}
		$this->assertStringContainsString('"data":[' . implode(',', $daily) . ']', $page['body'], 'mails per day, oldest first');
	}

	#[TestDox('The mail lists of the map, of the entry and of the unmapped symbol')]
	public function testMailLists(): void {
		$admin = self::client('admin');
		$lists = [
			'map=' . self::MAP => ['hits-a', 'hits-b', 'hits-c'],
			'map=' . self::MAP . "&entry={$this->entry}" => ['hits-a', 'hits-b', 'hits-c'],
			'symbol=' . self::UNMAPPED => ['hits-d'],
		];
		foreach ($lists as $query => $senders) {
			$page = $admin->get("/admin/maps/hits/mails?{$query}&days=31");
			$this->assertSame(200, $page['status'], $query);
			preg_match_all('/hits-[a-d]@example\.net/', $page['body'], $found);
			$shown = array_values(array_unique(array_map(fn (string $s) => substr($s, 0, 6), $found[0])));
			sort($shown);
			$this->assertSame($senders, $shown, $query);
		}
	}

	#[TestDox("The entry's hits on the map page, and the map link on the mail's detail page")]
	public function testEntryAndMailLinks(): void {
		$admin = self::client('admin');
		$map = $admin->get('/admin/map/' . self::MAP);
		$this->assertSame(200, $map['status']);
		$this->assertStringContainsString('mails?map=' . self::MAP . "&amp;entry={$this->entry}&amp;days=31\">3</a>", $map['body']);

		$detail = $admin->get("/admin/detail/id/{$this->mail}");
		$this->assertSame(200, $detail['status']);
		$this->assertStringContainsString('<a href="/admin/map/' . self::MAP . '">', $detail['body'], 'credited through the entry');
	}

	private static function day(int $ago): string {
		return self::valueString('SELECT CURDATE() - INTERVAL ? DAY', $ago);
	}
}
