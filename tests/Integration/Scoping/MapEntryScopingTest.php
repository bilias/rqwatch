<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis
 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Who may show, add, edit, toggle and delete map entries, with the default
 settings: USER_CAN_SEE_ADMIN_MAP_ENTRIES (true) lets a user see an entry an
 admin made for one of her addresses, USER_CAN_DEL_ADMIN_MAP_ENTRIES (false)
 keeps her from toggling or deleting it, and only an entry's creator or an
 admin may edit it (MapService::updateMapCombinedEntry()). An admin may do
 all of it. Each test gets its own entries in mail_from_rcpt_to_whitelist:
 A (alice's), B (bob's) and M (made by admin for alice@example.org); the
 fixture entries 201/202 are the route smoke test's sample ids.
 Every refusal has a matching allowed action, so a broken login, token or
 fixture cannot make a refusal pass by accident.
*/

namespace Tests\Integration\Scoping;

use Tests\Integration\IntegrationTestCase;
use Tests\Integration\WebClient;

final class MapEntryScopingTest extends IntegrationTestCase
{
	private const MAP = 'mail_from_rcpt_to_whitelist';
	private const USER_FORM = 'map_mail_from_rcpt_to_user_form';
	private const ADMIN_FORM = 'map_mail_from_rcpt_to_form';

	/** @var array{A: int, B: int, M: int} */
	private array $ids;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->ids = [
			'A' => self::entry('alice', 'acl-alice@example.net', 'alice@example.org'),
			'B' => self::entry('bob', 'acl-bob@example.net', 'bob@example.org'),
			'M' => self::entry('admin', 'acl-admin@example.net', 'alice@example.org'),
		];
	}

	#[\Override]
	protected function tearDown(): void {
		self::db()->exec("DELETE FROM maps_combined WHERE mail_from LIKE 'acl-%'");
		parent::tearDown();
	}

	public function testUserSeesOwnAndAdminEntriesForHerAddresses(): void {
		$alice = self::client('alice')->get('/user/map/' . self::MAP)['body'];
		$this->assertStringContainsString('acl-alice@example.net', $alice);
		$this->assertStringContainsString('acl-admin@example.net', $alice, 'admin entry for her address');
		$this->assertStringNotContainsString('acl-bob@example.net', $alice);

		// the admin entry is for alice's address, so bob does not see it
		$bob = self::client('bob')->get('/user/map/' . self::MAP)['body'];
		$this->assertStringContainsString('acl-bob@example.net', $bob);
		$this->assertStringNotContainsString('acl-alice@example.net', $bob);
		$this->assertStringNotContainsString('acl-admin@example.net', $bob);
	}

	public function testUserChangesOnlyHerOwnEntry(): void {
		$alice = self::client('alice');
		$base = '/user/map/' . self::MAP;
		[$toggle, $del] = self::linkTokens($alice, $base, $this->ids['A']);

		// bob's entry and the admin's: no edit form for bob's, nothing changes
		$edit = $alice->get("{$base}/edit/{$this->ids['B']}");
		$this->assertSame(302, $edit['status'], 'edit page of B');
		$this->assertStringNotContainsString('acl-bob@example.net', $edit['body'], 'edit page of B leaked it');
		foreach (['B', 'M'] as $entry) {
			$id = $this->ids[$entry];
			self::edit($alice, "{$base}/edit/{$this->ids['A']}", "{$base}/edit/{$id}", self::USER_FORM, "acl-edited-{$entry}@example.net", 'alice@example.org');
			$alice->get("{$base}/toggle/{$id}?_token=" . urlencode($toggle));
			$alice->get("{$base}/del/{$id}?_token=" . urlencode($del));
			$this->assertSame(0, self::entries("id = {$id} AND mail_from LIKE 'acl-edited-%'"), "{$entry} edited");
			$this->assertSame(0, (int) self::value('SELECT disabled FROM maps_combined WHERE id = ?', $id), "{$entry} toggled");
			$this->assertSame(1, self::entries("id = {$id}"), "{$entry} deleted");
		}

		// her own entry: edit, toggle, delete
		$id = $this->ids['A'];
		self::edit($alice, "{$base}/edit/{$id}", "{$base}/edit/{$id}", self::USER_FORM, 'acl-edited-a@example.net', 'alias-alice@example.org');
		$this->assertSame(1, self::entries("id = {$id} AND mail_from = 'acl-edited-a@example.net' AND rcpt_to = 'alias-alice@example.org'"), 'A not edited');
		$alice->get("{$base}/toggle/{$id}?_token=" . urlencode($toggle));
		$this->assertSame(1, (int) self::value('SELECT disabled FROM maps_combined WHERE id = ?', $id), 'A not toggled');
		$alice->get("{$base}/del/{$id}?_token=" . urlencode($del));
		$this->assertSame(0, self::entries("id = {$id}"), 'A not deleted');
	}

	public function testUserDeleteAllRemovesOnlyHerOwnEntries(): void {
		$alice = self::client('alice');
		$base = '/user/map/' . self::MAP;
		$delall = WebClient::match('#/delall\?_token=([^"\']+)#', $alice->get($base)['body']);

		$alice->get("{$base}/delall?_token=" . urlencode($delall));
		$this->assertSame(0, self::entries("id = {$this->ids['A']}"), 'A kept');
		$this->assertSame(1, self::entries("id = {$this->ids['B']}"), 'B deleted');
		$this->assertSame(1, self::entries("id = {$this->ids['M']}"), 'admin entry M deleted');
	}

	public function testUserAddsOnlyForHerOwnAddresses(): void {
		$alice = self::client('alice');
		$aliceId = (int) self::value("SELECT id FROM users WHERE username = 'alice'");

		self::add($alice, '/user/map/' . self::MAP . '/add', self::USER_FORM, 'acl-new-bob@example.net', 'bob@example.org');
		$this->assertSame(0, self::entries("mail_from = 'acl-new-bob@example.net'"), 'added for bob');

		self::add($alice, '/user/map/' . self::MAP . '/add', self::USER_FORM, 'acl-new-alias@example.net', 'alias-alice@example.org');
		$this->assertSame(1, self::entries("mail_from = 'acl-new-alias@example.net' AND rcpt_to = 'alias-alice@example.org' AND user_id = {$aliceId}"), 'not added for her alias');

		// an admin-only map: its list and add pages refuse her
		$this->assertSame(302, $alice->get('/user/map/mail_from_whitelist')['status'], 'admin-only map list');
		$page = $alice->get('/user/map/mail_from_whitelist/add?mail_from=acl-x@example.net');
		$this->assertSame(302, $page['status'], 'admin-only map');
		$this->assertStringNotContainsString('map_mail_from_form', $page['body']);
	}

	public function testAdminDoesAllOfIt(): void {
		$admin = self::client('admin');
		$base = '/admin/map/' . self::MAP;

		$list = $admin->get($base)['body'];
		foreach (['acl-alice@example.net', 'acl-bob@example.net', 'acl-admin@example.net'] as $mailFrom) {
			$this->assertStringContainsString($mailFrom, $list);
		}
		[$toggle, $del] = self::linkTokens($admin, $base, $this->ids['B']);

		// a user's entry: edit, toggle
		$id = $this->ids['A'];
		self::edit($admin, "{$base}/edit/{$id}", "{$base}/edit/{$id}", self::ADMIN_FORM, 'acl-edited-by-admin@example.net', 'alice@example.org');
		$this->assertSame(1, self::entries("id = {$id} AND mail_from = 'acl-edited-by-admin@example.net'"), 'A not edited');
		$admin->get("{$base}/toggle/{$this->ids['B']}?_token=" . urlencode($toggle));
		$this->assertSame(1, (int) self::value('SELECT disabled FROM maps_combined WHERE id = ?', $this->ids['B']), 'B not toggled');

		// delete users' entries and its own
		foreach (['A', 'B', 'M'] as $entry) {
			$admin->get("{$base}/del/{$this->ids[$entry]}?_token=" . urlencode($del));
			$this->assertSame(0, self::entries("id = {$this->ids[$entry]}"), "{$entry} not deleted");
		}

		// add for any address
		self::add($admin, "{$base}/add", self::ADMIN_FORM, 'acl-new-admin@example.net', 'bob@example.org');
		$this->assertSame(1, self::entries("mail_from = 'acl-new-admin@example.net' AND rcpt_to = 'bob@example.org'"), 'not added');
	}

	// an entry of MAP created by $user, as the web form would; returns its id
	private static function entry(string $user, string $mailFrom, string $rcptTo): int {
		$insert = self::db()->prepare(
			'INSERT INTO maps_combined (map_name, user_id, mail_from, rcpt_to) '
			. 'VALUES (?, (SELECT id FROM users WHERE username = ?), ?, ?)'
		);
		$insert->execute([self::MAP, $user, $mailFrom, $rcptTo]);
		return (int) self::db()->lastInsertId();
	}

	private static function entries(string $where): int {
		return (int) self::value("SELECT COUNT(*) FROM maps_combined WHERE map_name = ? AND {$where}", self::MAP);
	}

	/** @return array{string, string} the toggle and delete tokens from the links of entry $id in the list at $base */
	private static function linkTokens(WebClient $client, string $base, int $id): array {
		$list = $client->get($base)['body'];
		return [
			WebClient::match("#/toggle/{$id}\\?_token=([^\"']+)#", $list),
			WebClient::match("#/del/{$id}\\?_token=([^\"']+)#", $list),
		];
	}

	// posts $form, with the CSRF token of the edit form at $tokenPage, to $target
	private static function edit(WebClient $client, string $tokenPage, string $target, string $form, string $mailFrom, string $rcptTo): void {
		$token = WebClient::match('#name="' . preg_quote($form, '#') . '\[_token\]" value="([^"]+)"#', $client->get($tokenPage)['body']);
		$client->post($target, [
			"{$form}[mail_from]" => $mailFrom,
			"{$form}[rcpt_to]" => $rcptTo,
			"{$form}[_token]" => $token,
		]);
	}

	private static function add(WebClient $client, string $path, string $form, string $mailFrom, string $rcptTo): void {
		self::edit($client, $path, $path, $form, $mailFrom, $rcptTo);
	}
}
