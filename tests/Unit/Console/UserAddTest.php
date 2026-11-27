<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 user:add on RecordingDatabase. Only one run at a time. A username taken
 (trimmed, lower case) is refused, as is an LDAP admin (LDAP_ADMINS says
 who). An LDAP user gets no password (EXTERNAL_AUTH); a database user the
 one given with -p, or else typed twice at a hidden prompt (after a summary
 of the user), refused when the two differ or none is given; it is stored
 hashed. An admin with -a. Notifications on or off with
 --[no-]notifications, else following user_notifications_default (on
 until its migration). A user not stored is reported.
*/

namespace Tests\Unit\Console;

use App\Configuration\Config;
use App\Console\UserAdd;
use App\Core\App;
use App\Core\AppContainer;
use App\Core\Database\MigrationStatus;
use Illuminate\Database\Eloquent\Model;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Tests\Unit\PrivateLock;
use Tests\Unit\RecordingDatabase;
use Tests\Unit\UnitTestCase;

// the hidden prompt read from the tester's input, not a terminal: each test in a process of its own
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class UserAddTest extends UnitTestCase
{
	private RecordingDatabase $db;

	private string $dir = '';

	private bool $exists = false;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->db = new RecordingDatabase($this->createStub(PDO::class));
		$this->db->capsule->bootEloquent();
		$this->db->answer = fn (): array => [['exists' => $this->exists ? 1 : 0]];
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, $this->db->capsule, new MigrationStatus(null, $this->log), null, false));
		Config::set('password_hash', PASSWORD_BCRYPT);
		$this->dir = sys_get_temp_dir() . '/rqwatch-ua-' . bin2hex(random_bytes(4));
		mkdir($this->dir);
		QuestionHelper::disableStty();
	}

	#[\Override]
	protected function tearDown(): void {
		$files = glob("{$this->dir}/*");
		foreach ($files === false ? [] : $files as $file) {
			unlink($file);
		}
		rmdir($this->dir);
		Model::unsetConnectionResolver();
		RecordingDatabase::forget();
		parent::tearDown();
	}

	/**
	 * @param array<string, mixed> $input
	 * @param list<string> $answers typed at the prompts
	 * @return array{int, string}
	 */
	private function add(array $input, array $answers = []): array {
		$tester = new CommandTester(PrivateLock::give(new UserAdd(), $this->dir));
		$tester->setInputs($answers);
		$status = $tester->execute($input + ['username' => ' Bob ', 'mail' => 'bob@example.org']);
		return [$status, $tester->getDisplay()];
	}

	/** @return array<string, mixed> the user stored */
	private function stored(): array {
		$rows = $this->db->rows('users');
		$this->assertCount(1, $rows);
		return $rows[0];
	}

	public function testAddWithPassword(): void {
		[$status, $display] = $this->add(['-p' => 'secret', '-f' => 'Bob', '-s' => 'Smith', '-a' => true, '--notifications' => true]);
		$this->assertSame([Command::SUCCESS, "User 'bob' created\n"], [$status, $display]);
		$user = $this->stored();
		$this->assertSame(['bob', 'bob@example.org', 'Bob', 'Smith', 1, 0, 0], [
			$user['username'] ?? null, $user['email'] ?? null, $user['firstname'] ?? null, $user['lastname'] ?? null,
			$user['is_admin'] ?? null, $user['auth_provider'] ?? null, $user['disable_notifications'] ?? null,
		]);
		$hash = $user['password'] ?? null;
		$this->assertIsString($hash);
		$this->assertTrue(password_verify('secret', $hash));
		$this->assertSame([['select exists(select * from `users` where `username` = ?) as `exists`', ['bob']]], $this->db->selects);
	}

	/** @return array<string, array{array<string, true>, ?int}> the option, disable_notifications stored */
	public static function notifications(): array {
		return [
			'off' => [['--no-notifications' => true], 1],
			'on' => [['--notifications' => true], 0],
			// the default's migration not done: on
			'the default' => [[], 0],
		];
	}

	/** @param array<string, true> $option */
	#[DataProvider('notifications')]
	public function testNotifications(array $option, ?int $disabled): void {
		$this->add(['-p' => 'secret'] + $option);
		$this->assertSame($disabled, $this->stored()['disable_notifications'] ?? null);
	}

	// no password asked for an LDAP user, nor stored
	public function testLdapUser(): void {
		[$status] = $this->add(['-l' => true]);
		$this->assertSame(Command::SUCCESS, $status);
		$user = $this->stored();
		$this->assertSame(['EXTERNAL_AUTH', 1, 0], [$user['password'] ?? null, $user['auth_provider'] ?? null, $user['is_admin'] ?? null]);
	}

	public function testLdapAdminRefused(): void {
		[$status, $display] = $this->add(['-l' => true, '-a' => true]);
		$this->assertSame([Command::FAILURE, "Cannot create admin LDAP users from cli.\nSee LDAP_ADMINS in .env\n"], [$status, $display]);
		$this->assertSame([], $this->db->rows('users'));
	}

	public function testUserExists(): void {
		$this->exists = true;
		[$status, $display] = $this->add(['-p' => 'secret']);
		$this->assertSame([Command::FAILURE, "Username 'bob' already exists.\n"], [$status, $display]);
		$this->assertSame([], $this->db->rows('users'));
	}

	// no password given (none, '' or '0'): asked for, after a summary, twice
	/** @return array<string, array{array<string, string>}> */
	public static function noPassword(): array {
		return ['none' => [[]], 'empty' => [['-p' => '']], "'0'" => [['-p' => '0']]];
	}

	/** @param array<string, string> $option */
	#[DataProvider('noPassword')]
	public function testPrompt(array $option): void {
		[$status, $display] = $this->add($option + ['-f' => 'Bob'], ['typed', 'typed']);
		$this->assertSame(Command::SUCCESS, $status);
		$this->assertSame("Creating user with:\n\nUsername: bob\nAuthentication provider: DB\nAdmin User: No\nFirst Name: Bob\nLast Name: \n\n"
			. "Please enter password for bob: \nPlease confirm the password: \nUser 'bob' created\n", $display);
		$hash = $this->stored()['password'] ?? null;
		$this->assertIsString($hash);
		$this->assertTrue(password_verify('typed', $hash));
	}

	/** @return array<string, array{list<string>, string}> answers, the error */
	public static function badAnswers(): array {
		return [
			'not the same' => [['typed', 'other'], 'Passwords do not match!'],
			'none' => [['', ''], 'Empty password given!'],
			"'0'" => [['0', '0'], 'Empty password given!'],
		];
	}

	/** @param list<string> $answers */
	#[DataProvider('badAnswers')]
	public function testPromptRefused(array $answers, string $error): void {
		[$status, $display] = $this->add([], $answers);
		$this->assertSame(Command::FAILURE, $status);
		$this->assertStringEndsWith("Please confirm the password: \n{$error}\n", $display);
		$this->assertSame([], $this->db->rows('users'));
	}

	// blanks only: trimmed to no password
	public function testBlankPassword(): void {
		[$status, $display] = $this->add(['-p' => '   ']);
		$this->assertSame([Command::FAILURE, "Empty password given!\n"], [$status, $display]);
	}

	public function testNotStored(): void {
		$this->db->failInsertInto['users'] = new RuntimeException('Duplicate entry');
		[$status, $display] = $this->add(['-p' => 'secret']);
		$this->assertSame([Command::FAILURE, "Error creating user 'bob'. Check logs\n"], [$status, $display]);
		$this->assertSame(["error userAdd error: Duplicate entry\n"], $this->log->lines);
	}

	public function testAlreadyRunning(): void {
		$lock = (new LockFactory(new FlockStore($this->dir)))->createLock('user:add');
		$this->assertTrue($lock->acquire());
		[$status, $display] = $this->add(['-p' => 'secret']);
		$this->assertSame([Command::FAILURE, "Already running in another process.\n"], [$status, $display]);
		$this->assertSame(['warning user:add Already running in another process'], $this->log->lines);
		$lock->release();
	}
}
