<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The admin's mail alias pages, on RecordingDatabase. A user is refused
 each, logged. The list pages a user's aliases with the user's name and
 address. A search is a GET page of ?q= (2 to 128 of letters, digits and
 ._+-@, else back to the list): the search form posts there, refused when
 invalid. Adding an alias lowercases both addresses and needs the user,
 and the alias not already the user's; once saved it is logged, said and
 back to the list. Deleting needs its CSRF token and the alias; it is
 logged and said. The quarantine-id box on each page goes to the mail.
*/

namespace Tests\Unit\Controllers;

use App\Controllers\MailAliasController;
use App\Core\Routing\RouteName;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class MailAliasControllerTest extends ControllerTestCase
{
	private const array ALIAS = ['id' => 3, 'user_id' => 7, 'alias' => 'a.smith@example.org', 'username' => 'alice', 'email' => 'alice@example.org', 'created_at' => '2026-01-02 10:00:00', 'updated_at' => '2026-01-03 10:00:00'];

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
	}

	/** @param array<string, mixed> $parameters */
	private function url(RouteName $route, array $parameters = []): string {
		return $this->urls->generate($route->value, $parameters);
	}

	private function aliases(Request $request): MailAliasController {
		return $this->controller(new MailAliasController(), $request);
	}

	/** @return array<string, array{string}> */
	public static function actions(): array {
		return [
			'list' => ['showAll'],
			'search' => ['searchAlias'],
			'add' => ['add'],
		];
	}

	#[DataProvider('actions')]
	public function testUserRefused(string $action): void {
		$request = self::request('/', self::USER);
		$aliases = $this->aliases($request);
		$response = match ($action) {
			'showAll' => $aliases->showAll(),
			'searchAlias' => $aliases->searchAlias(),
			default => $aliases->add(),
		};
		$this->assertRedirect($this->url(RouteName::DAY_LOGS), $response);
		$this->assertSame(['error' => ['Permission denied']], self::flashes($request));
		$this->assertCount(1, $this->log->lines);
		$this->assertStringStartsWith("warning 'alice' tried to ", $this->log->lines[0]);
		$this->assertSame([], $this->db->selects);
	}

	public function testList(): void {
		$this->answers = [
			['count(*) as aggregate', [['aggregate' => 1]]],
			['from `mail_aliases` inner join `users`', [self::ALIAS]],
			['from `users` where `users`.`id` in', [['id' => 7, 'username' => 'alice', 'email' => 'alice@example.org']]],
		];
		$request = self::request('/admin/aliases', self::ADMIN);
		$response = $this->aliases($request)->showAll();
		$html = (string) $response->getContent();
		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());
		$this->assertStringContainsString('Total E-mail Aliases: 1', $html);
		$this->assertStringContainsString("<td class='center'>a.smith@example.org</td>", $html);
		$this->assertStringContainsString("<td class='center'>alice</td>", $html);
		$this->assertStringContainsString('select `mail_aliases`.*, `users`.`username` as `username`, `users`.`email` as `email` from `mail_aliases` inner join `users` on `mail_aliases`.`user_id` = `users`.`id` order by `mail_aliases`.`updated_at` desc, `users`.`username` asc, `mail_aliases`.`alias` asc limit 50 offset 0', $this->db->selects[1][0] ?? '');
	}

	public function testListFramed(): void {
		$this->answers = [
			['count(*) as aggregate', [['aggregate' => 60]]],
			['from `mail_aliases` inner join `users`', [self::ALIAS]],
		];
		$request = self::request($this->url(RouteName::ADMIN_ALIASES), self::ADMIN);
		$this->framed($request);
		$html = (string) $this->aliases($request)->showAll()->getContent();
		$this->assertFramed($html, 'Aliases');
		$this->assertStringContainsString('Total E-mail Aliases: 60', $html);
		$this->assertStringContainsString('| Displaying 50/page', $html);
		$this->assertStringContainsString('name="mail_alias_search_form[alias]"', $html);
		$this->assertStringNotContainsString('Show All Aliases', $html);
	}

	public function testListPage(): void {
		$this->answers = [['count(*) as aggregate', [['aggregate' => 200]]]];
		$request = self::request('/admin/aliases', self::ADMIN, ['page' => 3]);
		$this->aliases($request)->showAll();
		$this->assertStringEndsWith('limit 50 offset 100', $this->selected('limit 50 offset')[0] ?? '');
	}

	public function testEmptyList(): void {
		$request = self::request('/admin/aliases', self::ADMIN);
		$html = (string) $this->aliases($request)->showAll()->getContent();
		$this->assertStringContainsString('Total E-mail Aliases: 0', $html);
		$this->assertStringContainsString('No entries found', $html);
	}

	public function testQidBox(): void {
		$request = self::request('/admin/aliases', self::ADMIN);
		$request->request->replace(['qid_form' => ['qid' => 'ABC123', '_token' => self::csrf($request, 'qid_form')]]);
		$request->setMethod('POST');
		$response = $this->aliases($request)->showAll();
		$this->assertRedirect($this->url(RouteName::ADMIN_DETAIL, ['type' => 'qid', 'value' => 'ABC123']), $response);
	}

	/** @return array<string, array{\Closure(MailAliasController): Response}> */
	public static function pages(): array {
		return [
			'search' => [fn (MailAliasController $c): Response => $c->searchAlias()],
			'add' => [fn (MailAliasController $c): Response => $c->add()],
		];
	}

	/** @param \Closure(MailAliasController): Response $page */
	#[DataProvider('pages')]
	public function testFramedPages(\Closure $page): void {
		$request = self::request($this->url(RouteName::ADMIN_ALIASES), self::ADMIN, ['q' => 'smith']);
		$this->framed($request);
		$response = $page($this->aliases($request));
		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());
		$this->assertFramed((string) $response->getContent(), 'Aliases');
	}

	/** @param \Closure(MailAliasController): Response $page */
	#[DataProvider('pages')]
	public function testQidBoxElsewhere(\Closure $page): void {
		$this->assertToQid($page($this->aliases(self::qidPosted(self::ADMIN))));
	}

	public function testSearch(): void {
		$this->answers = [
			['count(*) as aggregate', [['aggregate' => 1]]],
			['from `mail_aliases` inner join `users`', [self::ALIAS]],
		];
		$request = self::request('/admin/aliases/search', self::ADMIN, ['q' => 'smith']);
		$html = (string) $this->aliases($request)->searchAlias()->getContent();
		$this->assertStringContainsString("<td class='center'>a.smith@example.org</td>", $html);
		$this->assertStringContainsString('value="smith"', $html);
		$this->assertSame(['%smith%', '%smith%', '%smith%'], $this->db->selects[0][1] ?? null);
	}

	public function testSearchFramed(): void {
		$this->answers = [['count(*) as aggregate', [['aggregate' => 200]]]];
		$request = self::request($this->url(RouteName::ADMIN_ALIASES_SEARCH), self::ADMIN, ['q' => 'smith', 'page' => 2]);
		$this->framed($request);
		$html = (string) $this->aliases($request)->searchAlias()->getContent();
		$this->assertFramed($html, null);
		$this->assertStringContainsString('Show All Aliases', $html);
		$this->assertStringContainsString('Total E-mail Aliases: 200', $html);
		$this->assertStringContainsString('| Displaying 50/page', $html);
		$this->assertStringEndsWith('limit 50 offset 50', $this->selected('limit 50 offset')[0] ?? '');
	}

	/** @return array<string, array{string, string}> q, what is searched */
	public static function searches(): array {
		return [
			'two characters' => ['ab', 'ab'],
			'128 characters' => [str_repeat('a', 128), str_repeat('a', 128)],
			'spaces around' => [' smith ', 'smith'],
			'every character allowed' => ['a.b_c+d-e@f', 'a.b_c+d-e@f'],
		];
	}

	#[DataProvider('searches')]
	public function testSearched(string $q, string $searched): void {
		$this->answers = [['count(*) as aggregate', [['aggregate' => 1]]]];
		$request = self::request('/admin/aliases/search', self::ADMIN, ['q' => $q]);
		$this->aliases($request)->searchAlias();
		$this->assertSame(["%{$searched}%", "%{$searched}%", "%{$searched}%"], $this->db->selects[0][1] ?? null);
		$this->assertStringEndsWith('limit 50 offset 0', $this->selected('limit 50 offset')[0] ?? '');
	}

	/** @return array<string, array{string}> */
	public static function badSearches(): array {
		return [
			'none' => [''],
			'one character' => ['a'],
			'too long' => [str_repeat('a', 129)],
			'a space inside' => ['a b'],
			'a quote' => ["a'b"],
		];
	}

	#[DataProvider('badSearches')]
	public function testBadSearchBackToList(string $q): void {
		$request = self::request('/admin/aliases/search', self::ADMIN, ['q' => $q]);
		$this->assertRedirect($this->url(RouteName::ADMIN_ALIASES), $this->aliases($request)->searchAlias());
		$this->assertSame([], $this->db->selects);
	}

	/**
	 * @param array<string, string> $fields
	 * @return array{Response, Request}
	 */
	private function postSearch(array $fields): array {
		$request = self::request('/admin/aliases/search', self::ADMIN);
		$request->setMethod('POST');
		$request->request->replace(['mail_alias_search_form' => $fields + ['_token' => self::csrf($request, 'mail_alias_search_form')]]);
		return [$this->aliases($request)->searchAlias(), $request];
	}

	public function testSearchFormGoesToPage(): void {
		[$response] = $this->postSearch(['alias' => 'smith@example.org']);
		$this->assertRedirect($this->url(RouteName::ADMIN_ALIASES_SEARCH) . '?q=smith%40example.org', $response);
	}

	public function testSearchFormInvalid(): void {
		[$response, $request] = $this->postSearch(['alias' => 'a b']);
		$this->assertRedirect($this->url(RouteName::ADMIN_ALIASES), $response);
		$this->assertSame(['error' => ['The value can only contain letters, numbers and ._+-@']], self::flashes($request));
	}

	public function testAddForm(): void {
		// as if added from the list's address: the menu marks it
		$request = self::request($this->url(RouteName::ADMIN_ALIASES), self::ADMIN);
		$this->framed($request);
		$response = $this->aliases($request)->add();
		$html = (string) $response->getContent();
		$this->assertFramed($html, 'Aliases');
		$this->assertStringContainsString('name="mail_alias_form[username]"', $html);
		$this->assertStringContainsString('name="mail_alias_form[alias]"', $html);
		$this->assertSame([], $this->db->selects);
	}

	/** @return array{Response, Request} */
	private function postAdd(string $username = ' Alice ', string $alias = 'A.Smith@Example.org'): array {
		$request = self::request('/admin/aliases/add', self::ADMIN);
		$request->setMethod('POST');
		$request->request->replace(['mail_alias_form' => ['username' => $username, 'alias' => $alias, '_token' => self::csrf($request, 'mail_alias_form')]]);
		return [$this->aliases($request)->add(), $request];
	}

	private function alice(): void {
		$this->answers[] = ['from `users` where `username` = ?', [['id' => 7, 'username' => 'alice', 'email' => 'alice@example.org']]];
	}

	public function testAdd(): void {
		$this->alice();
		[$response, $request] = $this->postAdd();
		$this->assertRedirect($this->url(RouteName::ADMIN_ALIASES), $response);
		$this->assertSame(['success' => ["Alias 'a.smith@example.org' created for 'alice'"]], self::flashes($request));
		$this->assertSame(["info Alias 'a.smith@example.org' created for 'alice' by 'root'"], $this->log->lines);
		$this->assertSame(['alice'], $this->db->selects[0][1] ?? null);
		$this->assertSame([7, 'a.smith@example.org'], $this->db->selects[1][1] ?? null);
		$rows = $this->db->rows('mail_aliases');
		$this->assertCount(1, $rows);
		$this->assertSame([7, 'a.smith@example.org'], [$rows[0]['user_id'] ?? null, $rows[0]['alias'] ?? null]);
	}

	public function testAddUnknownUser(): void {
		[$response, $request] = $this->postAdd('bob');
		$this->assertRedirect($this->url(RouteName::ADMIN_ALIASES_ADD), $response);
		$this->assertSame(['error' => ["Username 'bob' does not exist"]], self::flashes($request));
		$this->assertSame([], $this->db->rows('mail_aliases'));
	}

	public function testAddExisting(): void {
		$this->alice();
		$this->answers[] = ['select exists(', [['exists' => 1]]];
		[$response, $request] = $this->postAdd();
		$this->assertRedirect($this->url(RouteName::ADMIN_ALIASES_ADD), $response);
		$this->assertSame(['error' => ["Alias 'a.smith@example.org' already exists for user 'alice'"]], self::flashes($request));
		$this->assertSame([], $this->db->rows('mail_aliases'));
	}

	public function testAddFails(): void {
		$this->alice();
		$this->db->failInsertInto['mail_aliases'] = new RuntimeException('Duplicate entry');
		[$response, $request] = $this->postAdd();
		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());
		// said on the form page, which shows the messages: in its errors and as a message
		$this->assertSame([], self::flashes($request));
		$html = (string) $response->getContent();
		$this->assertMatchesRegularExpression("#<div class='user_errors'>\\s*Duplicate entry#", $html);
		$this->assertMatchesRegularExpression('#<div class="flash-error">\\s*Duplicate entry#', $html);
		$this->assertStringContainsString('name="mail_alias_form[alias]"', $html);
	}

	public function testAddInvalidShowsForm(): void {
		[$response] = $this->postAdd('alice', 'not an address');
		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());
		$this->assertSame([], $this->db->selects);
	}

	/**
	 * @param array<string, mixed> $session
	 * @return array{Response, Request}
	 */
	private function del(array $session = self::ADMIN, ?string $token = null): array {
		$request = self::request('/admin/aliases/del/3', $session);
		$request->query->set('_token', $token ?? self::csrf($request, 'alias_del'));
		return [$this->aliases($request)->del(3), $request];
	}

	public function testDel(): void {
		$this->answers = [['from `mail_aliases` where `mail_aliases`.`id` = ?', [self::ALIAS]]];
		[$response, $request] = $this->del();
		$this->assertRedirect($this->url(RouteName::ADMIN_ALIASES), $response);
		$this->assertSame(['success' => ["Alias 'a.smith@example.org' deleted"]], self::flashes($request));
		$this->assertContains("info Alias 'a.smith@example.org' deleted by 'root'", $this->log->lines);
		$this->assertContains(['delete from `mail_aliases` where `id` = ?', [3]], $this->db->changes);
	}

	public function testDelNotFound(): void {
		[$response, $request] = $this->del();
		$this->assertRedirect($this->url(RouteName::ADMIN_ALIASES), $response);
		$this->assertSame(['error' => ['Alias not found']], self::flashes($request));
	}

	public function testDelFails(): void {
		$this->answers = [['from `mail_aliases` where `mail_aliases`.`id` = ?', [self::ALIAS]]];
		$this->db->affect = function (): int {
			throw new RuntimeException('Lock wait timeout');
		};
		[$response, $request] = $this->del();
		$this->assertSame(['error' => ["Failed 'a.smith@example.org' delete"]], self::flashes($request));
	}

	public function testDelUserRefused(): void {
		[$response, $request] = $this->del(self::USER);
		$this->assertRedirect($this->url(RouteName::ADMIN_ALIASES), $response);
		$this->assertSame(['error' => ['Permission denied']], self::flashes($request));
		$this->assertSame(["warning 'alice' tried to delete alias without admin authorization"], $this->log->lines);
	}

	public function testDelBadToken(): void {
		[$response, $request] = $this->del(self::ADMIN, 'forged');
		$this->assertRedirect($this->url(RouteName::ADMIN_ALIASES), $response);
		$this->assertSame(['error' => ['Invalid or expired request. Please try again.']], self::flashes($request));
		$this->assertSame(['warning CSRF check failed on alias del from 192.0.2.7'], $this->log->lines);
		$this->assertSame([], $this->db->selects);
	}
}
