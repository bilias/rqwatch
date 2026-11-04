<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Editing a map entry with unchanged values succeeds, however often and
 fast it is submitted: MariaDB's UPDATE counts changed rows, so a resubmit
 within the same second (unchanged values and updated_at) affects none,
 which MapService::updateMapCombinedEntry() reported as a failure (found
 by ZAP's active scan). The test creates its own entry for alice and
 removes it afterwards.
*/

namespace Tests\Integration\Maps;

use PHPUnit\Framework\Attributes\TestDox;
use Tests\Integration\IntegrationTestCase;
use Tests\Integration\WebClient;

final class EditEntryTest extends IntegrationTestCase
{
	private const PATH = '/user/map/mail_from_rcpt_to_whitelist/edit/';
	private const FORM = 'map_mail_from_rcpt_to_user_form';
	private const SENDER = 'edit-twice@example.net';

	// the entry's id, assigned by the database (no explicit id: it would
	// move AUTO_INCREMENT under other tests' inserts)
	private int $id = 0;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		self::db()->prepare("INSERT INTO maps_combined (map_name, user_id, mail_from, rcpt_to, created_at, updated_at)"
			. " SELECT 'mail_from_rcpt_to_whitelist', id, ?, 'alice@example.org', NOW(), NOW() FROM users WHERE username = 'alice'")
			->execute([self::SENDER]);
		$this->id = (int) self::db()->lastInsertId();
	}

	#[\Override]
	protected function tearDown(): void {
		self::db()->prepare('DELETE FROM maps_combined WHERE id = ?')->execute([$this->id]);
		parent::tearDown();
	}

	#[TestDox('Map edit: the same values submitted three times in a row all succeed')]
	public function testUnchangedResubmitSucceeds(): void {
		$alice = self::client('alice');
		$before = self::value('SELECT CONCAT(mail_from, " ", rcpt_to) FROM maps_combined WHERE id = ?', $this->id);
		// three quick submits: at least two land in the same second
		foreach ([1, 2, 3] as $n) {
			$token = WebClient::match('/name="' . self::FORM . '\[_token\]"[^>]*value="([^"]+)"/', $alice->get(self::PATH . $this->id)['body']);
			$response = $alice->post(self::PATH . $this->id, [
				self::FORM . '[mail_from]' => self::SENDER,
				self::FORM . '[rcpt_to]' => 'alice@example.org',
				self::FORM . '[_token]' => $token,
			]);
			$this->assertSame(302, $response['status'], "submit {$n}");
			$this->assertSame('/user/map/mail_from_rcpt_to_whitelist', self::target($response), "submit {$n}: success goes to the map");
		}
		$this->assertSame($before, self::value('SELECT CONCAT(mail_from, " ", rcpt_to) FROM maps_combined WHERE id = ?', $this->id), 'entry unchanged');
	}
}
