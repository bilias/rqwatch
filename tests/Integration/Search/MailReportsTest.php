<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The admin's mail reports over HTTP, read from four mails of their own:
 two from one.example (reject), one from two.example (discard, no size,
 no ip) in the last week, and one 40 days old from two.example with no
 action. Each value is distinct, so one read wrongly shows: the symbols
 report's rows and the number of mails it read, the informational symbol
 only on request, the virus report's virus and scan failure, and the
 reports by sender domain and by action, the mail without an action
 counted on its own row. Rows are compared as text, in any order: equal
 totals have no fixed order.
*/

namespace Tests\Integration\Search;

use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Integration\IntegrationTestCase;

final class MailReportsTest extends IntegrationTestCase
{
	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$ago = fn (int $days): string => (new DateTimeImmutable("-{$days} days"))->format('Y-m-d H:i:s');
		$mails = [
			9801 => [['action' => 'reject', 'mail_from' => 'a@one.example', 'size' => 1000, 'has_virus' => 1, 'created_at' => $ago(0)],
				[['name' => 'REP_A', 'score' => 2.5], ['name' => 'REP_B', 'score' => -1], ['name' => 'CLAM_VIRUS', 'score' => 10, 'group' => 'antivirus', 'options' => ['Eicar']]]],
			9802 => [['action' => 'reject', 'mail_from' => 'b@one.example', 'size' => 3000, 'mail_stored' => 0, 'mail_location' => null, 'created_at' => $ago(1)],
				[['name' => 'REP_A', 'score' => 1.5], ['name' => 'REP_INFO', 'score' => 0]]],
			9803 => [['action' => 'discard', 'mail_from' => 'c@two.example', 'size' => null, 'ip' => null, 'has_virus' => 1, 'created_at' => $ago(2)],
				[['name' => 'REP_A', 'score' => 3], ['name' => 'CLAM_VIRUS_FAIL', 'score' => 0, 'group' => 'antivirus']]],
			9804 => [['action' => null, 'mail_from' => 'd@two.example', 'size' => 500, 'mail_stored' => 0, 'mail_location' => null, 'created_at' => $ago(40)],
				[['name' => 'REP_B', 'score' => -2]]],
		];
		foreach ($mails as $id => [$columns, $symbols]) {
			$this->quarantined($id, 'mx1', ['alice@example.org'], $columns, $symbols);
		}
		self::db()->exec("INSERT INTO mail_log_viruses (mail_log_id, created_day, av, name) VALUES"
			. " (9801, CURDATE(), 'CLAM_VIRUS', 'Eicar'), (9803, CURDATE() - INTERVAL 2 DAY, 'CLAM_VIRUS_FAIL', '')");
	}

	#[TestDox('The symbols report: mails, actions and average score per symbol, and the mails it read')]
	public function testSymbolsReport(): void {
		$admin = self::client('admin');
		$page = $admin->get('/admin/symbols/report');
		$this->assertSame(200, $page['status']);
		$rows = self::rows($page['body']);
		$this->assertContains('REP_A 3 2 1 2,33', $rows);
		$this->assertContains('REP_B 1 1 0 -1,00', $rows, 'the 40-day-old mail is outside the default week');
		$this->assertNotContains('REP_INFO 1 1 0 0,00', $rows, 'informational symbols only on request');

		$week = self::valueInt('SELECT COUNT(*) FROM mail_logs WHERE created_day >= CURDATE() - INTERVAL 6 DAY');
		$this->assertStringContainsString("in the newest {$week} mails of the search", self::text($page['body']));

		$this->assertContains('REP_INFO 1 1 0 0,00', self::rows($admin->get('/admin/symbols/report?info=1')['body']));
	}

	#[TestDox('The virus report: a virus by name, and a scan failure apart')]
	public function testVirusReport(): void {
		$page = self::client('admin')->get('/admin/viruses/report');
		$this->assertSame(200, $page['status']);
		$rows = self::rows($page['body']);
		$this->assertContains('EicarCLAM_VIRUS 1', $rows);
		$this->assertContains('CLAM_VIRUS_FAIL 0 0 1 1', $rows);
	}

	#[TestDox('The reports by sender domain and by action, with totals, stored and size; no action on its own row')]
	public function testReports(): void {
		$admin = self::client('admin');
		$domains = self::rows($admin->get('/admin/reports/mail_from_domain/count')['body']);
		$this->assertContains('one.example 2 1 3,9K', $domains);
		$this->assertContains('two.example 2 1 500B', $domains, 'a mail without a size adds nothing to it');

		$actions = self::rows($admin->get('/admin/reports/action/count')['body']);
		$this->assertContains('1 0 500B', $actions, 'the mail without an action');
	}

	/** @return list<string> the page's table rows, as text */
	private static function rows(string $html): array {
		if ($html === '') {
			return [];
		}
		$doc = new DOMDocument();
		@$doc->loadHTML($html);
		$nodes = (new DOMXPath($doc))->query("//tr | //div[contains(concat(' ', normalize-space(@class), ' '), ' row ')]");
		$rows = [];
		if ($nodes !== false) {
			/** @var mixed $node */
			foreach ($nodes as $node) {
				if ($node instanceof DOMElement) {
					$rows[] = trim((string) preg_replace('/\s+/', ' ', $node->textContent));
				}
			}
		}
		return $rows;
	}

	private static function text(string $html): string {
		return (string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5));
	}
}
