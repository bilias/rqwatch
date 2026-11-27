<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The user pages, on RecordingDatabase. Admin only, but the profile; a
 user is refused each, logged. The users list and a search of it (a GET
 page of ?q=, posted to by the search form). One user, with the user's
 aliases. Adding a user needs a new username and a password, stored
 hashed. Editing one: a username another has is refused; a new password
 is hashed, none leaves it; a change of the fields sessions keep ends the
 user's sessions. The delete button is on others' pages, never on the
 local admin's or one's own, and deletes on a valid post. Logging in as a
 user (with its CSRF token) takes the user's identity, aliases and
 admin status, keeping the admin's to return to and dropping the admin's
 filters. The profile: the names, and for a database user a new password
 with the current one, which keeps this session. The login throttles
 need Redis: without it the page says so and nothing is cleared.
*/

namespace Tests\Unit\Controllers;

use App\Controllers\UserController;
use App\Core\Routing\RouteName;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class UserControllerTest extends ControllerTestCase
{
	/** @var array<string, mixed> */
	private array $bob = ['id' => 9, 'username' => 'bob', 'email' => 'bob@example.org', 'firstname' => 'Bob', 'lastname' => 'Builder', 'is_admin' => 0,
		'auth_provider' => 0, 'disable_notifications' => null, 'session_version' => 4, 'last_login' => null, 'created_at' => '2026-01-01 10:00:00', 'updated_at' => '2026-01-01 10:00:00'];

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->bob['password'] = password_hash('old-pass', PASSWORD_BCRYPT, ['cost' => 4]);
		$this->answers = [
			['select exists(', fn (array $bindings): array => [['exists' => in_array($bindings[0] ?? null, ['bob', 'carol'], true) ? 1 : 0]]],
			['from `mail_aliases`', [['alias' => 'B.Builder@example.org'], ['alias' => ' ']]],
			// one column, as the database gives it (value() takes the first)
			['select `password` from `users`', fn (): array => [['password' => $this->bob['password']]]],
			['select `session_version` from `users`', fn (): array => [['session_version' => 5]]],
			['from `users` where `users`.`id` = ?', fn (array $bindings): array => ($bindings[0] ?? null) === 9 ? [$this->bob] : []],
			['from `users` where `id` = ?', fn (array $bindings): array => ($bindings[0] ?? null) === 9 ? [$this->bob] : []],
			['from `users` where `username` = ?', fn (array $bindings): array => in_array($bindings[0] ?? null, ['bob', 'carol'], true) ? [$this->bob] : []],
			['count(*) as aggregate', [['aggregate' => 1]]],
			['from `users`', [$this->bob]],
		];
		$this->db->affect = fn (): int => 1;
	}

	/** @param array<string, mixed> $parameters */
	private function url(RouteName $route, array $parameters = []): string {
		return $this->urls->generate($route->value, $parameters);
	}

	private function users(Request $request): UserController {
		return $this->controller(new UserController(), $request);
	}

	private function html(Response $response): string {
		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());
		return (string) $response->getContent();
	}

	/** @return array<string, array{string, string}> action, what it is said to be */
	public static function adminActions(): array {
		return [
			'throttles' => ['showThrottles', 'access login throttles'],
			'clear a throttle' => ['clearThrottle', 'clear a login throttle'],
			'search' => ['searchUser', 'search user'],
			'list' => ['showAll', 'show all users'],
			'one' => ['showOne', 'show one user'],
			'add' => ['add', 'add user'],
			'edit' => ['edit', 'edit user'],
			'log in as' => ['loginAs', 'use loginAs'],
		];
	}

	#[DataProvider('adminActions')]
	public function testUserRefused(string $action, string $what): void {
		$request = self::request('/', self::USER);
		$users = $this->users($request);
		$response = match ($action) {
			'showThrottles' => $users->showThrottles(),
			'clearThrottle' => $users->clearThrottle('192.0.2.9'),
			'searchUser' => $users->searchUser(),
			'showAll' => $users->showAll(),
			'showOne' => $users->showOne(9),
			'add' => $users->add(),
			'edit' => $users->edit(9),
			default => $users->loginAs(9),
		};
		$this->assertRedirect($this->url(RouteName::DAY_LOGS), $response);
		$this->assertSame(['error' => ['Permission denied']], self::flashes($request));
		$this->assertSame(["warning 'alice' tried to {$what} without admin authorization"], $this->log->lines);
		$this->assertSame([], $this->db->selects);
	}

	/** @return array<string, array{\Closure(UserController): Response}> */
	public static function pages(): array {
		return [
			'throttles' => [fn (UserController $c): Response => $c->showThrottles()],
			'search' => [fn (UserController $c): Response => $c->searchUser()],
			'list' => [fn (UserController $c): Response => $c->showAll()],
			'one' => [fn (UserController $c): Response => $c->showOne(9)],
			'profile' => [fn (UserController $c): Response => $c->profile()],
			'add' => [fn (UserController $c): Response => $c->add()],
			'edit' => [fn (UserController $c): Response => $c->edit(9)],
		];
	}

	/** @param \Closure(UserController): Response $page */
	#[DataProvider('pages')]
	public function testFramedPages(\Closure $page): void {
		$request = self::request($this->url(RouteName::ADMIN_USERS), ['user_id' => 9, 'username' => 'bob'] + self::ADMIN, ['q' => 'bob']);
		$this->framed($request);
		$this->assertFramed($this->html($page($this->users($request))), 'Users');
	}

	/** @param \Closure(UserController): Response $page */
	#[DataProvider('pages')]
	public function testQidBox(\Closure $page): void {
		$this->assertToQid($page($this->users(self::qidPosted(self::ADMIN))));
		$this->assertSame([], $this->db->selects);
	}

	public function testList(): void {
		$request = self::request($this->url(RouteName::ADMIN_USERS), self::ADMIN);
		$this->framed($request);
		$html = $this->html($this->users($request)->showAll());
		$this->assertFramed($html, 'Users');
		$this->assertStringContainsString('Total Users: 1', $html);
		$this->assertStringContainsString("<td class='center'>bob@example.org</td>", $html);
		$this->assertStringContainsString('Default (Yes)', $html);
		$this->assertStringContainsString('name="user_search_form[user]"', $html);
	}

	public function testSearch(): void {
		$request = self::request('/admin/users/search', self::ADMIN, ['q' => ' bob ']);
		$html = $this->html($this->users($request)->searchUser());
		$this->assertStringContainsString("<td class='center'>bob@example.org</td>", $html);
		$this->assertStringContainsString('value="bob"', $html);
		$this->assertContains('%bob%', $this->selected('count(*) as aggregate')[1] ?? []);
	}

	/** @return array<string, array{string}> */
	public static function badSearches(): array {
		return ['none' => [''], 'too long' => [str_repeat('a', 129)], 'a space inside' => ['a b']];
	}

	#[DataProvider('badSearches')]
	public function testBadSearch(string $q): void {
		$response = $this->users(self::request('/admin/users/search', self::ADMIN, ['q' => $q]))->searchUser();
		$this->assertRedirect($this->url(RouteName::ADMIN_USERS), $response);
		$this->assertSame([], $this->db->selects);
	}

	/** @return array{Response, Request} */
	private function postSearch(string $user): array {
		$request = self::request('/admin/users/search', self::ADMIN);
		$request->setMethod('POST');
		$request->request->replace(['user_search_form' => ['user' => $user, '_token' => self::csrf($request, 'user_search_form')]]);
		return [$this->users($request)->searchUser(), $request];
	}

	public function testSearchForm(): void {
		[$response] = $this->postSearch('bob@example.org');
		$this->assertRedirect($this->url(RouteName::ADMIN_USERSEARCH) . '?q=bob%40example.org', $response);
	}

	public function testSearchFormInvalid(): void {
		[$response, $request] = $this->postSearch('a b');
		$this->assertRedirect($this->url(RouteName::ADMIN_USERS), $response);
		$this->assertSame(['error' => ['The value can only contain letters, numbers and ._+-@']], self::flashes($request));
	}

	public function testOne(): void {
		$html = $this->html($this->users(self::request('/admin/user/9', self::ADMIN))->showOne(9));
		$this->assertStringContainsString("<div class='detail'>bob</div>", $html);
		$this->assertStringContainsString('b.builder@example.org', $html);
	}

	public function testOneNotFound(): void {
		$request = self::request('/admin/user/8', self::ADMIN);
		$this->assertRedirect($this->url(RouteName::ADMIN_USERS), $this->users($request)->showOne(8));
		$this->assertSame(['error' => ['User not found']], self::flashes($request));
	}

	public function testAddForm(): void {
		$html = $this->html($this->users(self::request('/admin/users/add', self::ADMIN))->add());
		$this->assertStringContainsString('name="user_form[username]"', $html);
		$this->assertStringContainsString('name="user_form[password][first]"', $html);
	}

	/**
	 * @param array<string, mixed> $fields
	 * @return array{Response, Request}
	 */
	private function postUser(string $action, array $fields): array {
		$request = self::request('/admin/users', self::ADMIN);
		$request->setMethod('POST');
		$request->request->replace(['user_form' => $fields + ['_token' => self::csrf($request, 'user_form')]]);
		$users = $this->users($request);
		return [$action === 'add' ? $users->add() : $users->edit(9), $request];
	}

	public function testAdd(): void {
		[$response, $request] = $this->postUser('add', ['username' => 'dave', 'email' => 'dave@example.org', 'password' => ['first' => 'n3w-pass', 'second' => 'n3w-pass'], 'firstname' => 'Dave']);
		$this->assertRedirect($this->url(RouteName::ADMIN_USERS), $response);
		$this->assertSame(['success' => ["User 'dave' created"]], self::flashes($request));
		$this->assertContains("info User 'dave' created by 'root'", $this->log->lines);
		$row = $this->db->rows('users')[0] ?? [];
		$this->assertSame(['dave', 'dave@example.org', 'Dave'], [$row['username'] ?? null, $row['email'] ?? null, $row['firstname'] ?? null]);
		/** @var mixed $hash */
		$hash = $row['password'] ?? null;
		$this->assertIsString($hash);
		$this->assertTrue(password_verify('n3w-pass', $hash));
	}

	public function testAddExisting(): void {
		[$response, $request] = $this->postUser('add', ['username' => 'bob', 'email' => 'bob@example.org', 'password' => ['first' => 'n3w-pass', 'second' => 'n3w-pass']]);
		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());
		$this->assertStringContainsString("User &#039;bob&#039; already exists", (string) $response->getContent());
		$this->assertSame([], $this->db->rows('users'));
	}

	public function testAddBlankPassword(): void {
		[$response, $request] = $this->postUser('add', ['username' => 'dave', 'email' => 'dave@example.org', 'password' => ['first' => '        ', 'second' => '        ']]);
		$this->assertRedirect($this->url(RouteName::ADMIN_USERS), $response);
		$this->assertSame(['error' => ['Empty password not allowed.']], self::flashes($request));
		$this->assertSame([], $this->db->rows('users'));
	}

	public function testAddFails(): void {
		$this->db->failInsertInto['users'] = new RuntimeException('gone away');
		[$response, $request] = $this->postUser('add', ['username' => 'dave', 'email' => 'dave@example.org', 'password' => ['first' => 'n3w-pass', 'second' => 'n3w-pass']]);
		$this->assertRedirect($this->url(RouteName::ADMIN_USERS), $response);
		$this->assertSame(['error' => ['User creation failed']], self::flashes($request));
	}

	public function testEditForm(): void {
		$request = self::request('/admin/user/9/edit', self::ADMIN);
		$html = $this->html($this->users($request)->edit(9));
		$this->assertStringContainsString('value="bob"', $html);
		$this->assertStringContainsString('name="user_delete_form[delete]"', $html);
	}

	/** @return array<string, array{array<string, mixed>, string}> the user edited, the admin */
	public static function notDeletable(): array {
		return [
			'the local admin' => [['username' => 'admin'], 'root'],
			'oneself' => [[], 'self'],
		];
	}

	/** @param array<string, mixed> $user */
	#[DataProvider('notDeletable')]
	public function testNoDeleteButton(array $user, string $admin): void {
		$this->bob = $user + $this->bob;
		$session = $admin === 'self' ? ['user_id' => 9] + self::ADMIN : self::ADMIN;
		$html = $this->html($this->users(self::request('/admin/user/9/edit', $session))->edit(9));
		$this->assertStringNotContainsString('user_delete_form', $html);
	}

	public function testEditNotFound(): void {
		$request = self::request('/admin/user/8/edit', self::ADMIN);
		$this->assertRedirect($this->url(RouteName::ADMIN_USERS), $this->users($request)->edit(8));
		$this->assertSame(['error' => ['User not found.']], self::flashes($request));
	}

	public function testEdit(): void {
		[$response, $request] = $this->postUser('edit', ['username' => ' bob ', 'email' => 'robert@example.org', 'firstname' => 'Robert']);
		$this->assertRedirect($this->url(RouteName::ADMIN_USERS), $response);
		$this->assertSame(['success' => ["User 'bob' updated"]], self::flashes($request));
		$update = $this->db->changes[0] ?? null;
		$this->assertNotNull($update);
		$this->assertStringStartsWith('update `users` set `email` = ?, `firstname` = ?', $update[0]);
		$this->assertStringNotContainsString('`password`', $update[0]);
		// the address is kept in the user's sessions: they end
		$this->assertStringContainsString('`session_version` = `session_version` + 1', $this->db->changes[1][0] ?? '');
	}

	public function testEditPassword(): void {
		[$response] = $this->postUser('edit', ['username' => 'bob', 'email' => 'bob@example.org', 'password' => ['first' => 'n3w-pass', 'second' => 'n3w-pass']]);
		$this->assertRedirect($this->url(RouteName::ADMIN_USERS), $response);
		$update = $this->db->changes[0] ?? null;
		$this->assertNotNull($update);
		$this->assertStringContainsString('`password` = ?', $update[0]);
		$this->assertCount(2, $this->db->changes);
	}

	public function testEditNothingKeptChanged(): void {
		$this->postUser('edit', ['username' => 'bob', 'email' => 'bob@example.org', 'firstname' => 'Robert']);
		$this->assertCount(1, $this->db->changes);
	}

	public function testEditToTakenUsername(): void {
		[$response] = $this->postUser('edit', ['username' => 'carol', 'email' => 'bob@example.org']);
		$this->assertStringContainsString("Username &#039;carol&#039; already exists.", $this->html($response));
		$this->assertSame([], $this->db->changes);
	}

	public function testEditFails(): void {
		$this->db->affect = function (): int {
			throw new RuntimeException('gone away');
		};
		[$response] = $this->postUser('edit', ['username' => 'bob', 'email' => 'robert@example.org']);
		$this->assertStringContainsString('gone away', $this->html($response));
	}

	public function testDelete(): void {
		$request = self::request('/admin/user/9/edit', self::ADMIN);
		$request->setMethod('POST');
		$request->request->replace(['user_delete_form' => ['id' => '9', 'delete' => '', '_token' => self::csrf($request, 'user_delete_form')]]);
		$response = $this->users($request)->edit(9);
		$this->assertRedirect($this->url(RouteName::ADMIN_USERS), $response);
		$this->assertSame(['success' => ["User 'bob' deleted"]], self::flashes($request));
		$this->assertContains("info User 'bob' deleted by 'root'", $this->log->lines);
	}

	/** @return array{Response, Request} */
	private function loginAs(?string $token = null, int $id = 9): array {
		$request = self::request('/admin/loginas/9', ['filters' => ['x'], 'user_aliases' => ['root2@example.org']] + self::ADMIN);
		$request->query->set('_token', $token ?? self::csrf($request, 'user_loginas'));
		return [$this->users($request)->loginAs($id), $request];
	}

	public function testLoginAs(): void {
		[$response, $request] = $this->loginAs();
		$this->assertRedirect($this->url(RouteName::DAY_LOGS), $response);
		$session = $request->getSession();
		$this->assertSame(
			['bob', 9, 4, 'bob@example.org', false, 'DB', ['b.builder@example.org'], 'root', 'DB'],
			[$session->get('username'), $session->get('user_id'), $session->get('session_version'), $session->get('email'), $session->get('is_admin'), $session->get('auth_provider'), $session->get('user_aliases'), $session->get('old_username'), $session->get('old_auth_provider')]
		);
		$this->assertFalse($session->has('filters'));
		$this->assertSame(['success' => ['You are now logged in as bob']], self::flashes($request));
		$this->assertSame(["info 'root' logged in as 'bob'"], $this->log->lines);
	}

	public function testLoginAsNotFound(): void {
		[$response, $request] = $this->loginAs(null, 8);
		$this->assertRedirect($this->url(RouteName::ADMIN_USERS), $response);
		$this->assertSame(['error' => ['User not found']], self::flashes($request));
	}

	public function testLoginAsBadToken(): void {
		[$response, $request] = $this->loginAs('forged');
		$this->assertRedirect($this->url(RouteName::ADMIN_DAY_LOGS), $response);
		$this->assertSame(['warning CSRF check failed on loginAs from 192.0.2.7'], $this->log->lines);
		$this->assertSame('root', $request->getSession()->get('username'));
	}

	/** @return array<string, mixed> */
	private function bobSession(): array {
		return ['username' => 'bob', 'user_id' => 9, 'is_admin' => false, 'email' => 'bob@example.org', 'auth_provider' => 'DB', 'user_aliases' => ['bob@idp.example.org']];
	}

	public function testProfile(): void {
		$request = self::request($this->url(RouteName::PROFILE), $this->bobSession());
		$html = $this->html($this->users($request)->profile());
		$this->assertStringContainsString('name="profile_form[current_password]"', $html);
		$this->assertStringContainsString('b.builder@example.org, bob@idp.example.org', $html);
	}

	public function testProfileNotFound(): void {
		$request = self::request('/profile', ['username' => 'nobody'] + $this->bobSession());
		$this->assertRedirect($this->url(RouteName::DAY_LOGS), $this->users($request)->profile());
		$this->assertSame(['error' => ['User not found']], self::flashes($request));
	}

	/**
	 * @param array<string, mixed> $fields
	 * @return array{Response, Request}
	 */
	private function postProfile(array $fields): array {
		$request = self::request('/profile', $this->bobSession());
		$request->setMethod('POST');
		$request->request->replace(['profile_form' => $fields + ['_token' => self::csrf($request, 'profile_form')]]);
		return [$this->users($request)->profile(), $request];
	}

	public function testProfileNames(): void {
		[$response, $request] = $this->postProfile(['firstname' => 'Robert', 'lastname' => 'Builder']);
		$this->assertRedirect($this->url(RouteName::DAY_LOGS), $response);
		$this->assertSame(['success' => ['Profile updated']], self::flashes($request));
		$this->assertCount(1, $this->db->changes);
		$this->assertStringNotContainsString('password', $this->db->changes[0][0] ?? '');
	}

	public function testProfilePassword(): void {
		[$response, $request] = $this->postProfile(['current_password' => 'old-pass', 'password' => ['first' => 'n3w-pass', 'second' => 'n3w-pass']]);
		$this->assertRedirect($this->url(RouteName::DAY_LOGS), $response);
		$this->assertSame(['success' => ['Profile updated']], self::flashes($request));
		$this->assertStringContainsString('`password` = ?', $this->db->changes[0][0] ?? '');
		$this->assertStringContainsString('`session_version` = `session_version` + 1', $this->db->changes[1][0] ?? '');
		// this session is kept: the version read back
		$this->assertSame(5, $request->getSession()->get('session_version'));
	}

	public function testProfileWrongPassword(): void {
		[$response, $request] = $this->postProfile(['current_password' => 'guess', 'password' => ['first' => 'n3w-pass', 'second' => 'n3w-pass']]);
		$this->assertRedirect($this->url(RouteName::PROFILE), $response);
		$this->assertSame(['error' => ['Current password is wrong, password not changed']], self::flashes($request));
		$this->assertSame(["warning 'bob' failed password change: wrong current password from 192.0.2.7"], $this->log->lines);
		$this->assertSame([], $this->db->changes);
	}

	// an external user's password is the provider's
	public function testProfileExternalPasswordIgnored(): void {
		$this->bob['auth_provider'] = 1;
		$this->postProfile(['current_password' => 'old-pass', 'password' => ['first' => 'n3w-pass', 'second' => 'n3w-pass'], 'firstname' => 'Robert']);
		$this->assertStringNotContainsString('password', $this->db->changes[0][0] ?? '');
	}

	public function testProfileFails(): void {
		$this->db->affect = function (): int {
			throw new RuntimeException('gone away');
		};
		[$response] = $this->postProfile(['firstname' => 'Robert']);
		$this->assertStringContainsString('Profile update failed', $this->html($response));
		$this->assertSame(["error Profile update of 'bob' failed: gone away"], $this->log->lines);
	}

	public function testThrottlesWithoutRedis(): void {
		$request = self::request($this->url(RouteName::ADMIN_THROTTLES), self::ADMIN);
		$html = $this->html($this->users($request)->showThrottles());
		$this->assertStringContainsString('Redis is not available, so login throttling is disabled.', $html);
	}

	public function testClearThrottleWithoutRedis(): void {
		$request = self::request('/admin/throttles/clear', self::ADMIN);
		$request->query->set('_token', self::csrf($request, 'throttle_clear'));
		$this->assertRedirect($this->url(RouteName::ADMIN_THROTTLES), $this->users($request)->clearThrottle('192.0.2.9'));
		$this->assertSame(['error' => ['Failed to clear login throttle for 192.0.2.9']], self::flashes($request));
	}

	public function testClearThrottleNotAnAddress(): void {
		$request = self::request('/admin/throttles/clear', self::ADMIN);
		$request->query->set('_token', self::csrf($request, 'throttle_clear'));
		$this->users($request)->clearThrottle('nonsense');
		$this->assertSame(["warning [clearLoginThrottle] invalid IP: 'nonsense'"], $this->log->lines);
	}

	public function testClearThrottleBadToken(): void {
		$request = self::request('/admin/throttles/clear', self::ADMIN, ['_token' => 'forged']);
		$this->assertRedirect($this->url(RouteName::ADMIN_THROTTLES), $this->users($request)->clearThrottle('192.0.2.9'));
		$this->assertSame(['warning CSRF check failed on clearThrottle from 192.0.2.7'], $this->log->lines);
	}
}
