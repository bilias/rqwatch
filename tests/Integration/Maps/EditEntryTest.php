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
 by ZAP's active scan). A custom map's entry: a new pattern is saved and
 written to the map file; a pattern starting with # is saved without it,
 disabled, and left out of the map file. The tests create their own
 entries (and custom map) and remove them afterwards.
*/

namespace Tests\Integration\Maps;

use PHPUnit\Framework\Attributes\TestDox;
use Tests\Integration\IntegrationTestCase;
use Tests\WebClient;

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

	private const string CUSTOM_MAP = 'edit-custom-map';

	#[\Override]
	protected function tearDown(): void {
		$db = self::db();
		$db->prepare('DELETE FROM maps_combined WHERE id = ?')->execute([$this->id]);
		foreach (['maps_custom', 'custom_map_config', 'map_activity_logs'] as $table) {
			$db->prepare("DELETE FROM {$table} WHERE map_name = ?")->execute([self::CUSTOM_MAP]);
		}
		@unlink(self::mapFile());
		parent::tearDown();
	}

	#[TestDox('Custom map edit: a new pattern is saved and in the map file; with # first, saved disabled and left out')]
	public function testCustomEntryEdit(): void {
		$db = self::db();
		$db->prepare("INSERT INTO custom_map_config (map_name, map_description, field_name, field_label) VALUES (?, 'Edit custom', 'domain', 'Domain')")
			->execute([self::CUSTOM_MAP]);
		$db->prepare("INSERT INTO maps_custom (map_name, pattern, disabled, created_at, updated_at) VALUES (?, 'old.example.net', 0, NOW(), NOW())")
			->execute([self::CUSTOM_MAP]);
		$id = (int) $db->lastInsertId();
		$admin = self::client('admin');
		$path = '/admin/map/' . self::CUSTOM_MAP . "/edit/{$id}";
		$form = 'map_with_custom_field_form';

		foreach (['new.example.net' => [0, true], '#new.example.net' => [1, false]] as $submitted => [$disabled, $inFile]) {
			$token = WebClient::match('/name="' . $form . '\[_token\]"[^>]*value="([^"]+)"/', $admin->get($path)['body']);
			$response = $admin->post($path, ["{$form}[domain]" => $submitted, "{$form}[_token]" => $token]);
			$this->assertSame(302, $response['status'], $submitted);
			$this->assertSame('/admin/map/' . self::CUSTOM_MAP, self::target($response), "{$submitted}: success goes to the map");
			$this->assertSame("new.example.net {$disabled}", self::valueString('SELECT CONCAT(pattern, " ", disabled) FROM maps_custom WHERE id = ?', $id), $submitted);
			$file = (string) file_get_contents(self::mapFile());
			$this->assertSame($inFile, str_contains($file, "\nnew.example.net"), "{$submitted}: in the map file");
		}
	}

	private static function mapFile(): string {
		return self::root() . '/web/maps/' . self::CUSTOM_MAP . '.txt';
	}

	#[TestDox('Map edit: the same values submitted three times in a row all succeed')]
	public function testUnchangedResubmitSucceeds(): void {
		$alice = self::client('alice');
		$before = self::valueNullableString('SELECT CONCAT(mail_from, " ", rcpt_to) FROM maps_combined WHERE id = ?', $this->id);
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
