<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The admin's mail page shows the Fuzzy row of the detail page, after the
 Rspamd Symbols row, for a mail stored on this node and for one on mx2,
 whose body comes from mx2's GetMail API while the row comes from the
 database. The recipient's mail page has no Fuzzy row.
*/

namespace Tests\Integration\Fuzzy;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Integration\IntegrationTestCase;

final class MailPageTest extends IntegrationTestCase
{
	private const ROW = "<div class='info'>Fuzzy:</div>";
	private const LEARNED = 'Learned as Spam (RQWATCH_FUZZY_DENIED), weight 10:';
	private const MX1 = 9971;
	private const MX2 = 9972;

	/** @var array<int, int> learned mail id => its mail_log_fuzzy id */
	private array $records = [];

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		foreach ([self::MX1 => 'mx1', self::MX2 => 'mx2'] as $id => $server) {
			$qid = $this->quarantined($id, $server);
			self::db()->prepare('INSERT INTO mail_log_fuzzy (mail_log_id, qid, api_server, flag, weight, hashes, learned_by)'
				. ' VALUES (?, ?, ?, ?, ?, ?, ?)')
				->execute([$id, $qid, $server, 11, 10, json_encode([str_repeat(sprintf('%02x', $id % 256), 64)]), 'admin']);
			$this->records[$id] = (int) self::db()->lastInsertId();
		}
	}

	#[\Override]
	protected function tearDown(): void {
		self::db()->exec('DELETE FROM mail_log_fuzzy WHERE mail_log_id IN (' . self::MX1 . ', ' . self::MX2 . ')');
		parent::tearDown();
	}

	/** @return array<string, array{int}> */
	public static function serverCases(): array {
		return [
			'mail on this node' => [self::MX1],
			'mail on mx2, its body through the GetMail API' => [self::MX2],
		];
	}

	#[DataProvider('serverCases')]
	#[TestDox('Fuzzy row on the admin mail page, after Rspamd Symbols, as on the detail page')]
	public function testAdminMailPage(int $id): void {
		$admin = self::client('admin');
		$page = $admin->get("/admin/mail/{$id}");
		$this->assertSame(200, $page['status']);
		$this->assertStringContainsString("Body of SUBJ-Q-{$id}", $page['body'], 'mail body shown');
		$symbols = strpos($page['body'], 'Rspamd Symbols:');
		$row = strpos($page['body'], self::ROW);
		$this->assertNotFalse($symbols, 'Rspamd Symbols row');
		$this->assertNotFalse($row, 'Fuzzy row');
		$this->assertGreaterThan($symbols, $row, 'Fuzzy row after Rspamd Symbols');
		$this->assertStringContainsString(self::LEARNED, strip_tags($page['body']));
		$this->assertStringContainsString("/admin/fuzzy/unlearn/{$this->records[$id]}?", $page['body'], 'Unlearn link');

		$this->assertStringContainsString(self::LEARNED, strip_tags($admin->get("/admin/detail/id/{$id}")['body']), 'detail page');
	}

	#[TestDox('Fuzzy row: none on the recipient\'s mail page')]
	public function testRecipientMailPage(): void {
		$page = self::client('alice')->get('/mail/' . self::MX1);
		$this->assertSame(200, $page['status']);
		$this->assertStringContainsString('Body of SUBJ-Q-' . self::MX1, $page['body'], 'mail body shown');
		$this->assertStringNotContainsString(self::ROW, $page['body']);
		$this->assertStringNotContainsString('/fuzzy/', $page['body']);
	}
}
