<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 DbAuth's lookup on RecordingDatabase: the user is looked up by username,
 and the password checked against the stored hash. The login gives the
 user's name, admin flag and id as stored, the e-mail trimmed and lower
 case (one saved before may have capitals), or none. A wrong password, an
 unknown user, and an external account (EXTERNAL_AUTH, no hash) are
 refused, and give no user.
*/

namespace Tests\Unit\Auth;

use App\Core\App;
use App\Core\AppContainer;
use App\Core\Auth\DbAuth;
use Illuminate\Database\Eloquent\Model;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Unit\RecordingDatabase;
use Tests\Unit\UnitTestCase;

final class DbAuthTest extends UnitTestCase
{
	private RecordingDatabase $db;

	/** @var list<array<string, mixed>> the users the lookup finds */
	private array $users = [];

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->db = new RecordingDatabase($this->createStub(PDO::class));
		$this->db->capsule->bootEloquent();
		$this->db->answer = fn (): array => $this->users;
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, $this->db->capsule, null, null, false));
	}

	#[\Override]
	protected function tearDown(): void {
		Model::unsetConnectionResolver();
		RecordingDatabase::forget();
		parent::tearDown();
	}

	/** @return array<string, mixed> a users row, as stored */
	private static function user(string $password): array {
		return ['id' => '7', 'username' => 'Alice', 'password' => $password, 'email' => 'alice@example.org', 'is_admin' => 1];
	}

	public function testLogin(): void {
		$this->users = [self::user(password_hash('secret', PASSWORD_BCRYPT, ['cost' => 4]))];
		$auth = new DbAuth('Alice', 'secret');
		$this->assertTrue($auth->authenticate());
		$this->assertSame(['Alice', 'alice@example.org', true, 7], [$auth->getAuthenticatedUser(), $auth->getEmail(), $auth->getIsAdmin(), $auth->getId()]);
		$this->assertSame([['select `id`, `username`, `password`, `email`, `is_admin` from `users` where `username` = ? limit 1', ['Alice']]], $this->db->selects);
	}

	/** @return array<string, array{?string, ?string}> the stored e-mail, the one given */
	public static function emails(): array {
		return ['capitals and spaces' => [' Alice@Example.ORG ', 'alice@example.org'], 'none' => [null, null]];
	}

	#[DataProvider('emails')]
	public function testLoginEmail(?string $stored, ?string $email): void {
		$this->users = [['email' => $stored] + self::user(password_hash('secret', PASSWORD_BCRYPT, ['cost' => 4]))];
		$auth = new DbAuth('Alice', 'secret');
		$this->assertTrue($auth->authenticate());
		$this->assertSame($email, $auth->getEmail());
	}

	/** @return array<string, array{?string, string}> the stored password (null: no such user), the one given */
	public static function refused(): array {
		return [
			'wrong password' => ['secret', 'Secret'],
			'unknown user' => [null, 'secret'],
			'external account' => ['EXTERNAL_AUTH', 'EXTERNAL_AUTH'],
		];
	}

	#[DataProvider('refused')]
	public function testRefused(?string $stored, string $given): void {
		$this->users = $stored === null ? [] : [self::user($stored === 'EXTERNAL_AUTH' ? $stored : password_hash($stored, PASSWORD_BCRYPT, ['cost' => 4]))];
		$auth = new DbAuth('Alice', $given);
		$this->assertFalse($auth->authenticate());
		$this->assertCount(1, $this->db->selects);
		$this->expectException(RuntimeException::class);
		$auth->getAuthenticatedUser();
	}
}
