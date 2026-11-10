<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 fuzzy_mail.php refusals that come before rspamd: bad input (400), fuzzy
 learning off (501), unknown mail or record (422), mail not stored or already
 learned, weight over the limit (409), flag or fuzzy_url not configured (501).
 The harness enables fuzzy learning with no fuzzy_url, so no request reaches
 an rspamd and none may change mail_log_fuzzy.
*/

namespace Tests\Integration\Access;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Integration\IntegrationTestCase;

final class FuzzyApiTest extends IntegrationTestCase
{
	private const STORED = 9701;
	private const NOT_STORED = 9702;
	private const LEARNED = 9703;
	private const MISSING = 9799;
	// the flags of config.php
	private const FLAG = 11;

	// mail_log_fuzzy id of LEARNED's record
	private int $record;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->quarantined(self::STORED, 'mx1');
		$this->quarantined(self::NOT_STORED, 'mx1', columns: ['mail_stored' => 0, 'mail_location' => null]);
		$qid = $this->quarantined(self::LEARNED, 'mx1');
		self::db()->prepare('INSERT INTO mail_log_fuzzy (mail_log_id, qid, api_server, flag, weight, hashes, learned_by)'
			. ' VALUES (?, ?, ?, ?, ?, ?, ?)')
			->execute([self::LEARNED, $qid, 'mx1', self::FLAG, 65535, json_encode([str_repeat('a', 64)]), 'admin']);
		$this->record = (int) self::db()->lastInsertId();
	}

	#[\Override]
	protected function tearDown(): void {
		self::db()->exec('DELETE FROM mail_log_fuzzy WHERE mail_log_id IN ('
			. implode(',', [self::STORED, self::NOT_STORED, self::LEARNED]) . ')');
		parent::tearDown();
	}

	/** @return array<string, array{array<string, string|null>}> fields replaced in a valid learn request; null drops one */
	public static function badInputCases(): array {
		return [
			'no user' => [['remote_user' => null]],
			'empty user' => [['remote_user' => '']],
			'no action' => [['action' => null]],
			'unknown action' => [['action' => 'delete']],
			'no id' => [['id' => null]],
			'id 0' => [['id' => '0']],
			'id not a number' => [['id' => 'abc']],
			'learn without a flag' => [['flag' => null]],
			'flag 0' => [['flag' => '0']],
			'boost without a weight' => [['action' => 'boost']],
			'weight 0' => [['weight' => '0']],
			'weight over 65535' => [['weight' => '65536']],
			'negative weight' => [['weight' => '-5']],
			'weight not a whole number' => [['weight' => '1.5']],
		];
	}

	/** @param array<string, string|null> $change */
	#[DataProvider('badInputCases')]
	public function testBadInput(array $change): void {
		$this->assertRefused([400, 'Missing Required info'], array_merge(
			['remote_user' => 'admin', 'action' => 'learn', 'id' => (string) self::STORED, 'flag' => (string) self::FLAG],
			$change));
	}

	// isEnabled() needs the mail_log_fuzzy migration as well as $fuzzy_learn_enable
	public function testDisabled(): void {
		$migration = "UPDATE migrations SET status = ? WHERE migration = '20261007_mail_log_fuzzy'";
		self::db()->prepare($migration)->execute(['pending']);
		try {
			foreach (['learn' => self::STORED, 'unlearn' => $this->record, 'boost' => $this->record] as $action => $id) {
				$this->assertRefused([501, 'Fuzzy learning is disabled on mx1'],
					['remote_user' => 'admin', 'action' => $action, 'id' => (string) $id, 'flag' => (string) self::FLAG, 'weight' => '1']);
			}
		} finally {
			self::db()->prepare($migration)->execute(['completed']);
		}
	}

	public function testUnknownMailOrRecord(): void {
		foreach (['learn', 'unlearn', 'boost'] as $action) {
			$this->assertRefused([422, 'Message not found'],
				['remote_user' => 'admin', 'action' => $action, 'id' => (string) self::MISSING, 'flag' => (string) self::FLAG, 'weight' => '1']);
		}
	}

	public function testLearnRefusals(): void {
		$learn = ['remote_user' => 'admin', 'action' => 'learn', 'flag' => (string) self::FLAG];
		$qid = 'QIDQ' . self::NOT_STORED;
		$this->assertRefused([409, "Mail {$qid} is not stored"], $learn + ['id' => (string) self::NOT_STORED]);
		$qid = 'QIDQ' . self::LEARNED;
		$this->assertRefused([409, "Mail {$qid} is already learned"], $learn + ['id' => (string) self::LEARNED]);
		$this->assertRefused([501, "Fuzzy flag 99 is not configured on 'mx1'"],
			['flag' => '99'] + $learn + ['id' => (string) self::STORED]);
		// a configured flag gets as far as the mail's server: mx1 has no fuzzy_url
		$this->assertRefused([501, "Fuzzy learning is not configured for 'mx1': no fuzzy_url"],
			$learn + ['id' => (string) self::STORED]);
	}

	public function testRecordRefusals(): void {
		$qid = 'QIDQ' . self::LEARNED;
		$this->assertRefused([409, "Mail {$qid}: total weight would exceed 65535"],
			['remote_user' => 'admin', 'action' => 'boost', 'id' => (string) $this->record, 'weight' => '1']);
		$this->assertRefused([501, "Fuzzy learning is not configured for 'mx1': no fuzzy_url"],
			['remote_user' => 'admin', 'action' => 'unlearn', 'id' => (string) $this->record]);
	}

	/**
	 * @param array{int, string} $expected status and body
	 * @param array<string, string|null> $fields
	 */
	private function assertRefused(array $expected, array $fields): void {
		$records = self::records();
		$fields = array_filter($fields, fn($v) => $v !== null);
		$this->assertSame($expected, self::mailApi('fuzzy_mail.php', $fields), (string) json_encode($fields));
		$this->assertSame($records, self::records(), 'mail_log_fuzzy changed');
	}

	private static function records(): ?string {
		return self::valueNullableString('SELECT JSON_ARRAYAGG(JSON_ARRAY(id, mail_log_id, weight, hashes, updated_at)) FROM mail_log_fuzzy');
	}
}
