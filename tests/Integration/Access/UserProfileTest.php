<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 A user's own profile over HTTP, as a user of the test's own: a new
 password needs the current one, wrong it changes nothing; right, the new
 password is stored, the session that changed it stays logged in and a
 fresh login takes the new one. And the admin's delete button names the
 user it deletes. The test removes its user.
*/

namespace Tests\Integration\Access;

use PHPUnit\Framework\Attributes\TestDox;
use Tests\Integration\IntegrationTestCase;
use Tests\WebClient;

final class UserProfileTest extends IntegrationTestCase
{
	private const string PROFILE_USER = 'profiletest';
	private const string FORM = 'profile_form';

	private int $id;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		self::db()->prepare("INSERT INTO users (username, email, password, is_admin, auth_provider) VALUES (?, 'profiletest@example.org', ?, 0, 0)")
			->execute([self::PROFILE_USER, password_hash('pw-' . self::PROFILE_USER, PASSWORD_DEFAULT)]);
		$this->id = (int) self::db()->lastInsertId();
	}

	#[\Override]
	protected function tearDown(): void {
		self::db()->prepare('DELETE FROM users WHERE username = ?')->execute([self::PROFILE_USER]);
		parent::tearDown();
	}

	#[TestDox('A new password with the wrong current one changes nothing')]
	public function testWrongCurrentPasswordRefused(): void {
		$client = self::client(self::PROFILE_USER);
		$before = self::hash();
		$response = self::changePassword($client, 'not-the-password', 'pw-changed-1234');
		$this->assertSame(302, $response['status']);
		$this->assertSame('/profile', self::target($response));
		$this->assertStringContainsString('Current password is wrong, password not changed', $client->get('/profile')['body']);
		$this->assertSame($before, self::hash());
	}

	#[TestDox('A new password with the right current one is stored; this session stays in, a new login takes it')]
	public function testPasswordChanged(): void {
		$client = self::client(self::PROFILE_USER);
		$before = self::hash();
		$response = self::changePassword($client, 'pw-' . self::PROFILE_USER, 'pw-changed-1234');
		$this->assertSame(302, $response['status']);
		$this->assertNotSame($before, self::hash());
		$this->assertTrue(password_verify('pw-changed-1234', self::hash()));

		$this->assertSame(200, $client->get('/profile')['status'], 'still logged in');
		(new WebClient((string) getenv('INTEGRATION_BASE_URL')))->login(self::PROFILE_USER, 'pw-changed-1234');
	}

	#[TestDox("The admin's delete button names the user")]
	public function testDeleteConfirmationNamesTheUser(): void {
		$page = self::client('admin')->get("/admin/user/edit/{$this->id}");
		$this->assertSame(200, $page['status']);
		$this->assertStringContainsString('Are you sure you want to delete user "' . self::PROFILE_USER . '"?',
			html_entity_decode($page['body'], ENT_QUOTES | ENT_HTML5));
	}

	/** @return array{status: int, location: string, body: string, headers: array<string, string>} */
	private static function changePassword(WebClient $client, string $current, string $new): array {
		$token = WebClient::match('/name="' . self::FORM . '\[_token\]"[^>]*value="([^"]+)"/', $client->get('/profile')['body']);
		return $client->post('/profile', [
			self::FORM . '[firstname]' => 'Profile',
			self::FORM . '[lastname]' => 'Test',
			self::FORM . '[current_password]' => $current,
			self::FORM . '[password][first]' => $new,
			self::FORM . '[password][second]' => $new,
			self::FORM . '[disable_notifications]' => 'default',
			self::FORM . '[add]' => '',
			self::FORM . '[_token]' => $token,
		]);
	}

	private static function hash(): string {
		return self::valueString('SELECT password FROM users WHERE username = ?', self::PROFILE_USER);
	}
}
