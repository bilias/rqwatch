<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The search filters that query their own tables, run against MariaDB:
 Virus (mail_log_viruses: the name, or the antivirus symbol when the name
 is empty), Local symbol and Map hit (mail_log_symbol_hits), Fuzzy hit
 (mail_log_fuzzy_hits), RCPT To (mail_log_recipients), Headers and Rspamd
 symbols (mail_log_data) and Notification skipped (notified IS NULL), each
 positive and negated. Map hit is run with a Date filter and without one,
 which take different queries. As alice, the same filters never reach
 bob's mail, and RCPT To cannot probe for his address.
*/

namespace Tests\Integration\Search;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Integration\IntegrationTestCase;
use Tests\WebClient;

final class SearchFiltersTest extends IntegrationTestCase
{
	private const string FORM = 'search_form';
	private const string DAY = '2023-06-14';
	private const string LOCAL = 'RQWATCH_ITEST_FILTER_LOCAL';
	private const string MAPPED = 'RQWATCH_ITEST_FILTER_MAPPED';
	private const string LEARNED_BY = 'search-filters-test';

	// alice's 9951-9953, bob's 9954
	private const array MAILS = [9951, 9952, 9953, 9954];

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$db = self::db();
		foreach (self::MAILS as $id) {
			$rcpt = $id === 9954 ? 'bob@example.org' : 'alice@example.org';
			$symbols = $id === 9951 ? ['ITEST_DATA_SYMBOL' => ['name' => 'ITEST_DATA_SYMBOL', 'score' => 1]] : [];
			$this->quarantined($id, 'mx1', [$rcpt], [
				'created_at' => self::DAY . ' 10:00:00',
				// only 9951 was never handled by the notifier
				'notified' => $id === 9951 ? null : 0,
			], $symbols);
		}
		$db->exec("UPDATE mail_log_data SET headers = 'Subject: SUBJ-Q-9953\r\nX-Itest: header-needle' WHERE mail_log_id = 9953");

		// a named virus on 9951 and bob's 9954; a scanner failure (no name) on 9952
		$virus = $db->prepare('INSERT INTO mail_log_viruses (mail_log_id, created_day, av, name) VALUES (?, ?, ?, ?)');
		foreach ([[9951, 'CLAM_VIRUS', 'Eicar-Test-Signature'], [9952, 'ITEST_AV_FAIL', ''], [9954, 'CLAM_VIRUS', 'Eicar-Test-Signature']] as [$id, $av, $name]) {
			$virus->execute([$id, self::DAY, $av, $name]);
		}

		// a local symbol on 9951 and 9954, not linked to a map; map entries credited on 9952 and 9954
		$db->prepare('INSERT IGNORE INTO symbol_names (name) VALUES (?), (?)')->execute([self::LOCAL, self::MAPPED]);
		$hit = $db->prepare('INSERT INTO mail_log_symbol_hits (symbol_id, created_day, mail_log_id, entry_model, entry_id)'
			. ' VALUES ((SELECT id FROM symbol_names WHERE name = ?), ?, ?, ?, ?)');
		foreach ([[self::LOCAL, 9951, 0], [self::LOCAL, 9954, 0], [self::MAPPED, 9952, 1], [self::MAPPED, 9954, 1]] as [$symbol, $id, $model]) {
			$hit->execute([$symbol, self::DAY, $id, $model, $model === 0 ? 0 : 999999]);
		}

		// 9952 hit a learned mail
		$db->prepare("INSERT INTO mail_log_fuzzy (api_server, flag, weight, hashes, learned_by) VALUES ('mx1', 1, 1, '[]', ?)")
			->execute([self::LEARNED_BY]);
		$db->prepare('INSERT INTO mail_log_fuzzy_hits (fuzzy_id, mail_log_id) VALUES (?, 9952)')->execute([(int) $db->lastInsertId()]);
	}

	// the mails' hits go with them (ON DELETE CASCADE)
	#[\Override]
	protected function tearDown(): void {
		parent::tearDown();
		self::db()->prepare('DELETE FROM mail_log_fuzzy WHERE learned_by = ?')->execute([self::LEARNED_BY]);
		self::db()->prepare('DELETE FROM symbol_names WHERE name IN (?, ?)')->execute([self::LOCAL, self::MAPPED]);
	}

	/** @return array<string, array{list<array{string, string, string}>, list<int>}> filters besides the Date, the admin's mails */
	public static function adminCases(): array {
		return [
			'virus name' => [[['Virus', 'is equal to', 'Eicar-Test-Signature']], [9951, 9954]],
			'virus, the antivirus symbol when no name' => [[['Virus', 'is equal to', 'ITEST_AV_FAIL']], [9952]],
			'virus contains, any case' => [[['Virus', 'contains', 'eicar']], [9951, 9954]],
			'virus is not' => [[['Virus', 'is not equal to', 'Eicar-Test-Signature']], [9952, 9953]],
			'virus regexp' => [[['Virus', 'matches REGEXP', '^itest_av']], [9952]],
			'local symbol' => [[['Local symbol', 'is equal to', self::LOCAL]], [9951, 9954]],
			'local symbol is exact' => [[['Local symbol', 'is equal to', strtolower(self::LOCAL)]], []],
			'local symbol contains, any case' => [[['Local symbol', 'contains', 'itest_filter_local']], [9951, 9954]],
			'local symbol does not contain' => [[['Local symbol', 'does not contain', 'itest_filter_local']], [9952, 9953]],
			'no such local symbol' => [[['Local symbol', 'is equal to', 'ITEST_NO_SUCH_SYMBOL']], []],
			'no such local symbol, negated' => [[['Local symbol', 'is not equal to', 'ITEST_NO_SUCH_SYMBOL']], self::MAILS],
			'map hit' => [[['Map hit (0/1)', 'is equal to', '1']], [9952, 9954]],
			'no map hit' => [[['Map hit (0/1)', 'is equal to', '0']], [9951, 9953]],
			'map hit, as a number' => [[['Map hit (0/1)', 'is greater than', '0']], [9952, 9954]],
			'fuzzy hit' => [[['Fuzzy hit (0/1)', 'is equal to', '1']], [9952]],
			'no fuzzy hit' => [[['Fuzzy hit (0/1)', 'is not equal to', '1']], [9951, 9953, 9954]],
			'recipient' => [[['RCPT To', 'is equal to', 'BOB@example.org']], [9954]],
			'recipient is not' => [[['RCPT To', 'is not equal to', 'bob@example.org']], [9951, 9952, 9953]],
			'rspamd symbols, any case' => [[['Rspamd symbols', 'contains', 'itest_data_symbol']], [9951]],
			'headers' => [[['Headers', 'contains', 'header-needle']], [9953]],
			'headers do not contain' => [[['Headers', 'does not contain', 'header-needle']], [9951, 9952, 9954]],
			'notification skipped' => [[['Notification skipped (0/1)', 'is equal to', '1']], [9951]],
			'two filters' => [[['Virus', 'contains', 'eicar'], ['Map hit (0/1)', 'is equal to', '1']], [9954]],
		];
	}

	/**
	 * @param list<array{string, string, string}> $filters
	 * @param list<int> $expected
	 */
	#[DataProvider('adminCases')]
	public function testAdmin(array $filters, array $expected): void {
		$this->assertMails(self::search('admin', [['Date', 'is equal to', self::DAY], ...$filters]), $expected);
	}

	// without a Date the map hit filter is an EXISTS, with one a per-mail probe
	public function testMapHitWithoutDate(): void {
		$body = self::search('admin', [['Subject', 'contains', 'SUBJ-Q-995'], ['Map hit (0/1)', 'is equal to', '1']]);
		$this->assertMails($body, [9952, 9954]);
	}

	/** @return array<string, array{list<array{string, string, string}>, list<int>}> filters besides the Date, alice's mails */
	public static function userCases(): array {
		return [
			'virus name' => [[['Virus', 'is equal to', 'Eicar-Test-Signature']], [9951]],
			'virus is not' => [[['Virus', 'is not equal to', 'Eicar-Test-Signature']], [9952, 9953]],
			'local symbol' => [[['Local symbol', 'is equal to', self::LOCAL]], [9951]],
			'local symbol, negated' => [[['Local symbol', 'is not equal to', self::LOCAL]], [9952, 9953]],
			'map hit' => [[['Map hit (0/1)', 'is equal to', '1']], [9952]],
			'no map hit' => [[['Map hit (0/1)', 'is equal to', '0']], [9951, 9953]],
			"someone else's recipient" => [[['RCPT To', 'is equal to', 'bob@example.org']], []],
			'not her own recipient' => [[['RCPT To', 'is not equal to', 'alice@example.org']], []],
			'headers do not contain' => [[['Headers', 'does not contain', 'header-needle']], [9951, 9952]],
		];
	}

	/**
	 * @param list<array{string, string, string}> $filters
	 * @param list<int> $expected
	 */
	#[DataProvider('userCases')]
	public function testUserStaysInScope(array $filters, array $expected): void {
		$this->assertMails(self::search('alice', [['Date', 'is equal to', self::DAY], ...$filters]), $expected);
	}

	/**
	 * Adds the filters in a fresh session of $user and returns the results page.
	 * @param list<array{string, string, string}> $filters
	 */
	private static function search(string $user, array $filters): string {
		$client = self::client($user);
		$base = $user === 'admin' ? '/admin' : '';
		foreach ($filters as [$filter, $choice, $value]) {
			$token = WebClient::match('/name="' . self::FORM . '\[_token\]"[^>]*value="([^"]+)"/', $client->get("{$base}/search")['body']);
			$posted = $client->post("{$base}/search", [
				self::FORM . '[filter]' => $filter,
				self::FORM . '[choice]' => $choice,
				self::FORM . '[value]' => $value,
				self::FORM . '[search]' => '',
				self::FORM . '[_token]' => $token,
			]);
			if ($posted['status'] !== 302) {
				self::fail("{$user}: filter {$filter} {$choice} '{$value}' not added (status {$posted['status']})");
			}
		}
		$results = $client->get("{$base}/results");
		if ($results['status'] !== 200) {
			self::fail("{$user}: results status {$results['status']}");
		}
		return $results['body'];
	}

	/** @param list<int> $expected the test's mails listed, the rest absent */
	private function assertMails(string $body, array $expected): void {
		foreach (self::MAILS as $id) {
			if (in_array($id, $expected, true)) {
				$this->assertStringContainsString("SUBJ-Q-{$id}", $body, "{$id} missing");
			} else {
				$this->assertStringNotContainsString("SUBJ-Q-{$id}", $body, "{$id} listed");
			}
		}
	}
}
