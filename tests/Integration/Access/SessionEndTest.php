<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Account changes end the user's open sessions (users.session_version):
 a password change from the profile ends the user's other sessions but
 not the one that made it; an admin changing the user's e-mail, admin
 status or password, deleting the user, or deleting one of the user's
 aliases ends them all. An admin edit of anything else (the name) leaves
 them open. The test makes its own user and removes it.
*/

namespace Tests\Integration\Access;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Integration\IntegrationTestCase;
use Tests\WebClient;

final class SessionEndTest extends IntegrationTestCase
{
	private const string TEST_USER = 'sessionendtest';
	private const string EMAIL = 'sessionendtest@example.org';
	private const string ALIAS = 'sessionendtest-alias@example.org';

	private int $id;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		self::db()->prepare("INSERT INTO users (username, email, password, is_admin, auth_provider) VALUES (?, ?, ?, 0, 0)")
			->execute([self::TEST_USER, self::EMAIL, password_hash('pw-' . self::TEST_USER, PASSWORD_DEFAULT)]);
		$this->id = (int) self::db()->lastInsertId();
	}

	#[\Override]
	protected function tearDown(): void {
		self::db()->prepare('DELETE FROM mail_aliases WHERE alias = ?')->execute([self::ALIAS]);
		self::db()->prepare('DELETE FROM users WHERE username = ?')->execute([self::TEST_USER]);
		parent::tearDown();
	}

	#[TestDox("A password change from the profile ends the user's other sessions, not this one")]
	public function testOwnPasswordChange(): void {
		$changer = self::client(self::TEST_USER);
		$other = self::client(self::TEST_USER);
		self::assertOpen($other, 'before');

		$token = WebClient::match('/name="profile_form\[_token\]"[^>]*value="([^"]+)"/', $changer->get('/profile')['body']);
		$response = $changer->post('/profile', [
			'profile_form[firstname]' => 'Session',
			'profile_form[lastname]' => 'End',
			'profile_form[current_password]' => 'pw-' . self::TEST_USER,
			'profile_form[password][first]' => 'pw-changed-5678',
			'profile_form[password][second]' => 'pw-changed-5678',
			'profile_form[disable_notifications]' => 'default',
			'profile_form[add]' => '',
			'profile_form[_token]' => $token,
		]);
		$this->assertSame(302, $response['status']);

		self::assertOpen($changer, 'the session that changed it');
		self::assertEnded($other, 'the other session');
	}

	/** @return array<string, array{array<string, string>, bool}> user form changes, sessions end */
	public static function adminEdits(): array {
		return [
			'e-mail' => [['user_form[email]' => 'sessionendtest-new@example.org'], true],
			'made admin' => [['user_form[is_admin]' => '1'], true],
			'password' => [['user_form[password][first]' => 'pw-admin-set-5678', 'user_form[password][second]' => 'pw-admin-set-5678'], true],
			'first name only' => [['user_form[firstname]' => 'Renamed'], false],
		];
	}

	/** @param array<string, string> $changes */
	#[DataProvider('adminEdits')]
	public function testAdminEdit(array $changes, bool $ends): void {
		$user = self::client(self::TEST_USER);
		self::assertOpen($user, 'before');

		$admin = self::client('admin');
		$path = "/admin/user/edit/{$this->id}";
		$token = WebClient::match('/name="user_form\[_token\]"[^>]*value="([^"]+)"/', $admin->get($path)['body']);
		$response = $admin->post($path, array_merge([
			'user_form[username]' => self::TEST_USER,
			'user_form[email]' => self::EMAIL,
			'user_form[firstname]' => '',
			'user_form[lastname]' => '',
			'user_form[disable_notifications]' => 'default',
			'user_form[add]' => '',
			'user_form[_token]' => $token,
		], $changes));
		$this->assertSame(302, $response['status']);
		$this->assertStringContainsString("User '" . self::TEST_USER . "' updated",
			html_entity_decode($admin->get('/admin/users')['body'], ENT_QUOTES | ENT_HTML5));

		if ($ends) {
			self::assertEnded($user, 'after the edit');
		} else {
			self::assertOpen($user, 'after the edit');
		}
	}

	#[TestDox("Deleting the user ends the user's sessions")]
	public function testDeletedUser(): void {
		$user = self::client(self::TEST_USER);
		self::assertOpen($user, 'before');

		$admin = self::client('admin');
		$path = "/admin/user/edit/{$this->id}";
		$token = WebClient::match('/name="user_delete_form\[_token\]"[^>]*value="([^"]+)"/', $admin->get($path)['body']);
		$response = $admin->post($path, [
			'user_delete_form[id]' => (string) $this->id,
			'user_delete_form[delete]' => '',
			'user_delete_form[_token]' => $token,
		]);
		$this->assertSame(302, $response['status']);
		$this->assertSame(0, self::valueInt('SELECT COUNT(*) FROM users WHERE id = ?', $this->id));

		self::assertEnded($user, 'after the delete');
	}

	#[TestDox("Deleting one of the user's aliases ends the user's sessions")]
	public function testDeletedAlias(): void {
		self::db()->prepare('INSERT INTO mail_aliases (user_id, alias) VALUES (?, ?)')->execute([$this->id, self::ALIAS]);
		$aliasId = (int) self::db()->lastInsertId();
		$user = self::client(self::TEST_USER);
		self::assertOpen($user, 'before');

		$admin = self::client('admin');
		$link = WebClient::match('#href="(/admin/aliases/del/' . $aliasId . '\?_token=[^"]+)"#', $admin->get('/admin/aliases')['body']);
		$response = $admin->get($link);
		$this->assertSame(302, $response['status']);
		$this->assertSame(0, self::valueInt('SELECT COUNT(*) FROM mail_aliases WHERE id = ?', $aliasId));

		self::assertEnded($user, 'after the alias delete');
	}

	private static function assertOpen(WebClient $client, string $when): void {
		self::assertSame(200, $client->get('/profile')['status'], "{$when}: still logged in");
	}

	// sent to log in, told why
	private static function assertEnded(WebClient $client, string $when): void {
		$response = $client->get('/profile');
		self::assertSame(302, $response['status'], "{$when}: sent to log in");
		self::assertSame('/login', self::target($response), "{$when}: sent to log in");
		self::assertStringContainsString('Your account was changed, please log in again', $client->get('/login')['body'], $when);
	}
}
