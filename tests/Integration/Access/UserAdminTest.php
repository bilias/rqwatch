<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The admin's user pages over HTTP. Create: the form makes a user who can
 log in, the name stored lowercased, and the same name again in another
 case is refused. Edit: a new password is stored without the blanks around
 it (the login drops them), and the user's own name retyped in another case
 is no change. A user's page lists that user's aliases, never the admin's.
 Delete: the user's map entries for their own e-mail and aliases go with
 them, matched case-insensitively; an entry for anyone else moves to the
 admin doing the delete. Each test removes what it made.
*/

namespace Tests\Integration\Access;

use PHPUnit\Framework\Attributes\TestDox;
use Tests\Integration\IntegrationTestCase;
use Tests\WebClient;

final class UserAdminTest extends IntegrationTestCase
{
	private const string CREATED = 'newadminuser';
	private const string LEAVER = 'leaver';
	private const string MAP = 'mail_from_rcpt_to_whitelist';

	#[\Override]
	protected function tearDown(): void {
		self::db()->prepare("DELETE FROM mail_aliases WHERE alias LIKE 'user-admin-test-%'")->execute();
		self::db()->prepare("DELETE FROM maps_combined WHERE mail_from LIKE 'user-admin-test-%'")->execute();
		self::db()->prepare('DELETE FROM users WHERE username IN (?, ?)')->execute([self::CREATED, self::LEAVER]);
		parent::tearDown();
	}

	#[TestDox('Admin creates a user: stored lowercased, can log in; the same name in another case is refused')]
	public function testCreate(): void {
		$admin = self::client('admin');
		$response = self::submitAdd($admin, 'NewAdminUser');
		$this->assertSame(302, $response['status']);
		$this->assertSame('/admin/users', self::target($response));
		$this->assertStringContainsString("User 'NewAdminUser' created", self::text($admin->get('/admin/users')['body']));
		$this->assertSame(1, self::valueInt('SELECT COUNT(*) FROM users WHERE username = ?', self::CREATED));
		self::client(self::CREATED);

		$response = self::submitAdd($admin, 'NEWADMINUSER');
		$this->assertSame(200, $response['status'], 'refused: the form is shown again');
		$this->assertStringContainsString("User 'NEWADMINUSER' already exists", self::text($response['body']));
		$this->assertSame(1, self::valueInt('SELECT COUNT(*) FROM users WHERE username = ?', self::CREATED));
	}

	// a user of the test's own, with password pw-newadminuser
	private static function created(): int {
		self::db()->prepare("INSERT INTO users (username, email, password, is_admin, auth_provider) VALUES (?, 'new-admin-user@example.org', ?, 0, 0)")
			->execute([self::CREATED, password_hash('pw-' . self::CREATED, PASSWORD_DEFAULT)]);
		return (int) self::db()->lastInsertId();
	}

	/** @return array{status: int, location: string, body: string, headers: array<string, string>} */
	private static function submitEdit(WebClient $admin, int $id, string $username, string $password = ''): array {
		$path = "/admin/user/edit/{$id}";
		$token = WebClient::match('/name="user_form\[_token\]"[^>]*value="([^"]+)"/', $admin->get($path)['body']);
		return $admin->post($path, [
			'user_form[username]' => $username,
			'user_form[email]' => 'new-admin-user@example.org',
			'user_form[password][first]' => $password,
			'user_form[password][second]' => $password,
			'user_form[disable_notifications]' => 'default',
			'user_form[add]' => '',
			'user_form[_token]' => $token,
		]);
	}

	#[TestDox('Admin sets a password with blanks around it: stored without them, the user logs in without them')]
	public function testEditPasswordTrimmed(): void {
		$id = self::created();
		$admin = self::client('admin');
		$response = self::submitEdit($admin, $id, self::CREATED, ' pw-edited-1234 ');
		$this->assertSame(302, $response['status']);
		$this->assertTrue(password_verify('pw-edited-1234', self::valueString('SELECT password FROM users WHERE id = ?', $id)));
		(new WebClient((string) getenv('INTEGRATION_BASE_URL')))->login(self::CREATED, 'pw-edited-1234');
	}

	#[TestDox("Admin retypes a user's name in capitals: no change, not \"already exists\"")]
	public function testEditOwnNameInCapitals(): void {
		$id = self::created();
		$admin = self::client('admin');
		$response = self::submitEdit($admin, $id, 'NEWADMINUSER');
		$this->assertSame(302, $response['status']);
		$this->assertSame('/admin/users', self::target($response));
		$this->assertStringContainsString("User 'newadminuser' updated", self::text($admin->get('/admin/users')['body']));
		$this->assertSame(self::CREATED, self::valueString('SELECT username FROM users WHERE id = ?', $id));
	}

	#[TestDox("A user's page lists the user's aliases, not the admin's own")]
	public function testUserPageAliases(): void {
		$id = self::created();
		$adminId = self::valueInt("SELECT id FROM users WHERE username = 'admin'");
		$insert = self::db()->prepare('INSERT INTO mail_aliases (user_id, alias) VALUES (?, ?)');
		$insert->execute([$adminId, 'user-admin-test-admin@example.org']);
		$insert->execute([$id, 'user-admin-test-own@example.org']);
		// logged in after the alias was added: the admin's session holds it
		$page = self::client('admin')->get("/admin/user/{$id}");
		$this->assertSame(200, $page['status']);
		$this->assertStringContainsString('user-admin-test-own@example.org', $page['body']);
		$this->assertStringNotContainsString('user-admin-test-admin@example.org', $page['body']);
	}

	#[TestDox('Admin deletes a user: entries for their own e-mail and alias go, a shared one moves to the admin')]
	public function testDeleteReassignsMapEntries(): void {
		$db = self::db();
		$db->prepare("INSERT INTO users (username, email, password, is_admin) VALUES (?, 'Leaver@Example.org', 'x', 0)")
			->execute([self::LEAVER]);
		$id = (int) $db->lastInsertId();
		$db->prepare("INSERT INTO mail_aliases (user_id, alias) VALUES (?, 'Leaver-Alias@Example.org')")->execute([$id]);
		$insert = $db->prepare('INSERT INTO maps_combined (map_name, user_id, mail_from, rcpt_to, created_at, updated_at)'
			. ' VALUES (?, ?, ?, ?, NOW(), NOW())');
		foreach (['own' => 'leaver@example.org', 'alias' => 'leaver-alias@example.org', 'shared' => 'shared@example.org'] as $kind => $rcpt) {
			$insert->execute([self::MAP, $id, "user-admin-test-{$kind}@example.net", $rcpt]);
		}

		$admin = self::client('admin');
		$path = "/admin/user/edit/{$id}";
		$token = WebClient::match('/name="user_delete_form\[_token\]"[^>]*value="([^"]+)"/', $admin->get($path)['body']);
		$response = $admin->post($path, [
			'user_delete_form[id]' => (string) $id,
			'user_delete_form[delete]' => '',
			'user_delete_form[_token]' => $token,
		]);
		$this->assertSame(302, $response['status']);
		$this->assertSame('/admin/users', self::target($response));
		$this->assertSame(0, self::valueInt('SELECT COUNT(*) FROM users WHERE id = ?', $id), 'user deleted');

		$this->assertSame(
			'user-admin-test-shared@example.net admin',
			self::valueString("SELECT GROUP_CONCAT(m.mail_from, ' ', u.username) FROM maps_combined m"
				. " JOIN users u ON u.id = m.user_id WHERE m.mail_from LIKE 'user-admin-test-%'"),
			'only the shared entry is left, now the admin\'s'
		);
	}

	// a page's text as shown: Twig escapes the quotes in the flash messages
	private static function text(string $html): string {
		return html_entity_decode($html, ENT_QUOTES | ENT_HTML5);
	}

	/** @return array{status: int, location: string, body: string, headers: array<string, string>} */
	private static function submitAdd(WebClient $admin, string $username): array {
		$token = WebClient::match('/name="user_form\[_token\]"[^>]*value="([^"]+)"/', $admin->get('/admin/user/add')['body']);
		return $admin->post('/admin/user/add', [
			'user_form[username]' => $username,
			'user_form[email]' => 'new-admin-user@example.org',
			'user_form[password][first]' => 'pw-' . self::CREATED,
			'user_form[password][second]' => 'pw-' . self::CREATED,
			'user_form[disable_notifications]' => 'default',
			'user_form[add]' => '',
			'user_form[_token]' => $token,
		]);
	}
}
