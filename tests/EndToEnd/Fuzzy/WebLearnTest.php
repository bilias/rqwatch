<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis
 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Fuzzy learning from the admin's mail detail page: learn with a weight, Add
 weight, unlearn, each checked in rspamd by scanning a copy. A mail stored on
 this node is learned here; one stored on mx2 goes through mx2's fuzzy API
 (qa/e2e-tests.sh points mx2 at this copy's own API). Identical content
 learned from two mails shares its digests: unlearn keeps them in rspamd
 until the last holder is unlearned. A mail that only resembles a learned one
 matched its digest on arrival and is learned with digests of its own.
*/

namespace Tests\EndToEnd\Fuzzy;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use RuntimeException;
use Tests\EndToEnd\EndToEndTestCase;
use Tests\WebClient;

final class WebLearnTest extends EndToEndTestCase
{
	// the flags of config.php
	private const SPAM = 11;
	private const NOT_SPAM = 13;
	// fuzzy_storage applies changes every second (sync = 1s in the test)
	private const SYNC_WAIT = 3;

	/** @return array<string, array{string}> */
	public static function serverCases(): array {
		return [
			'mail on this node' => ['mx1'],
			'mail on mx2, through its fuzzy API' => ['mx2'],
		];
	}

	#[DataProvider('serverCases')]
	#[TestDox('Fuzzy from the detail page: learn with a weight, Add weight raises the score, unlearn ($server)')]
	public function testLearnBoostUnlearn(string $server): void {
		$body = self::fuzzyBody("web-{$server}");
		[$qid, , $row] = $this->import('spam', body: $body);
		$id = (int) $row['id'];
		if ($server !== self::SERVER) {
			self::db()->prepare('UPDATE mail_logs SET server = ? WHERE id = ?')->execute([$server, $id]);
		}
		$admin = self::admin();

		$page = self::learn($admin, $id, self::SPAM, 7);
		$this->assertStringContainsString("Mail {$qid} learned as fuzzy 'Spam' (weight 7)", $page);
		$record = self::query('SELECT id, flag, weight, api_server, learned_by FROM mail_log_fuzzy WHERE mail_log_id = ?', [$id]);
		$this->assertCount(1, $record, 'mail_log_fuzzy record');
		$this->assertSame([self::SPAM, 7, $server, 'admin'],
			[(int) $record[0]['flag'], (int) $record[0]['weight'], $record[0]['api_server'], $record[0]['learned_by']]);
		self::waitForFuzzy($body, true);
		$before = self::fuzzyScore($body);
		$this->assertNotNull($before);

		// Add weight 20: rspamd adds it to the learned value, so the score rises
		[$action, $token] = self::form($page, '/fuzzy/boost/\d+');
		$page = self::follow($admin, $admin->get($action . '?' . http_build_query(['_token' => $token, 'weight' => '20'])), self::detail($id));
		$this->assertStringContainsString("Mail {$qid}: weight +20 added to fuzzy 'Spam', now 27", $page);
		$this->assertSame(27, (int) self::query('SELECT weight FROM mail_log_fuzzy WHERE mail_log_id = ?', [$id])[0]['weight']);
		self::waitUntil('the score rises', fn(): bool => (self::fuzzyScore($body) ?? 0.0) > $before);

		$page = self::unlearn($admin, $id);
		$this->assertStringContainsString("Mail {$qid} unlearned from fuzzy 'Spam'", $page);
		$this->assertSame([], self::query('SELECT id FROM mail_log_fuzzy WHERE mail_log_id = ?', [$id]), 'record deleted');
		self::waitForFuzzy($body, false);

		// a mail on mx2 is learned, boosted and unlearned by mx2's fuzzy API, one on this node is not
		$log = (string) file_get_contents((string) getenv('E2E_ROOT') . '/logs/rqwatch.log');
		foreach (['learn', 'boost', 'unlearn'] as $verb) {
			$line = "[FuzzyMailApi] 'admin' via '127.0.0.1' requested fuzzy {$verb} of mail {$qid} on 'mx1'";
			if ($server === self::SERVER) {
				$this->assertStringNotContainsString($line, $log);
			} else {
				$this->assertStringContainsString($line, $log);
			}
		}
	}

	#[TestDox('Fuzzy, two mails with the same content in one list: the first unlearn keeps the shared digests, the last removes them')]
	public function testSharedDigestsInOneList(): void {
		$body = self::fuzzyBody('shared-one-list');
		[$qidA, , $rowA] = $this->import('spam', body: $body);
		[$qidB, , $rowB] = $this->import('spam', body: $body);
		[$a, $b] = [(int) $rowA['id'], (int) $rowB['id']];
		$admin = self::admin();

		self::learn($admin, $a, self::SPAM, 10);
		$page = self::learn($admin, $b, self::SPAM, 10);
		$this->assertStringContainsString("Mail {$qidB} learned as fuzzy 'Spam' (weight 10)", $page);
		$this->assertStringContainsString("Its content was already learned via {$qidA}: this learn added its weight to the existing entry", $page);
		$hashes = self::hashes($a);
		$this->assertSame($hashes, self::hashes($b), 'the same digests');
		self::waitForFuzzy($body, true);

		$page = self::unlearn($admin, $a);
		$this->assertStringContainsString("Mail {$qidA} unlearned from fuzzy 'Spam' (" . count($hashes) . ' hash(es) kept, shared with other learned mails)', $page);
		$this->assertStringNotContainsString('stays active in rspamd', $page);
		sleep(self::SYNC_WAIT);
		$this->assertNotNull(self::fuzzyScore($body), 'kept digests still hit');

		$page = self::unlearn($admin, $b);
		$this->assertStringContainsString("Mail {$qidB} unlearned from fuzzy 'Spam'", $page);
		$this->assertStringNotContainsString('kept', $page);
		self::waitForFuzzy($body, false);
	}

	#[TestDox('Fuzzy, the same content in two lists: unlearn warns that the list\'s slot stays in rspamd until the other mail is unlearned')]
	public function testSharedDigestsAcrossLists(): void {
		$body = self::fuzzyBody('shared-two-lists');
		[$qidA, , $rowA] = $this->import('spam', body: $body);
		[$qidB, , $rowB] = $this->import('spam', body: $body);
		[$a, $b] = [(int) $rowA['id'], (int) $rowB['id']];
		$admin = self::admin();

		self::learn($admin, $a, self::SPAM, 10);
		$page = self::learn($admin, $b, self::NOT_SPAM, 10);
		$this->assertStringContainsString("Mail {$qidB} learned as fuzzy 'Not spam' (weight 10)", $page);
		$this->assertStringContainsString("The same content is also learned as 'Spam' via {$qidA}", $page);
		self::waitUntil('both lists hit', fn(): bool =>
			array_keys(self::fuzzyScores($body)) === ['RQWATCH_FUZZY_DENIED', 'RQWATCH_FUZZY_WHITE']);
		// contrib's fuzzy_group.conf: Spam scores up, Not spam down
		$scores = self::fuzzyScores($body);
		$this->assertGreaterThan(0.0, $scores['RQWATCH_FUZZY_DENIED'] ?? null, 'Spam list score');
		$this->assertLessThan(0.0, $scores['RQWATCH_FUZZY_WHITE'] ?? null, 'Not spam list score');

		$page = self::unlearn($admin, $a);
		$this->assertStringContainsString("Mail {$qidA} unlearned from fuzzy 'Spam'", $page);
		$this->assertStringContainsString("The 'Spam' entry stays active in rspamd: the same content is also learned via {$qidB} ('Not spam')", $page);
		sleep(self::SYNC_WAIT);
		$this->assertSame(['RQWATCH_FUZZY_DENIED', 'RQWATCH_FUZZY_WHITE'], array_keys(self::fuzzyScores($body)),
			'the Spam slot stays with the shared digest');

		$page = self::unlearn($admin, $b);
		$this->assertStringContainsString("Mail {$qidB} unlearned from fuzzy 'Not spam'", $page);
		$this->assertStringNotContainsString('stays active in rspamd', $page);
		self::waitUntil('no list hits', fn(): bool => self::fuzzyScores($body) === []);
	}

	#[TestDox('Fuzzy, near match: a mail that matched a learned one on arrival is learned with its own hashes, and the page says so')]
	public function testNearMatchLearnsItsOwnHashes(): void {
		$body = self::fuzzyBody('near-match');
		[$qidA, , $rowA] = $this->import('spam', body: $body);
		$a = (int) $rowA['id'];
		$admin = self::admin();
		self::learn($admin, $a, self::SPAM, 10);
		$hashesA = self::hashes($a);
		self::waitForFuzzy($body, true);

		// three words changed: another digest, nearly the same shingles
		$words = explode(' ', str_replace("\r\n", ' ', $body));
		foreach ([50, 150, 250] as $i) {
			$words[$i] = 'near' . $i;
		}
		[$qidB, $reply, $rowB] = $this->import('spam', body: wordwrap(implode(' ', $words), 72, "\r\n"));
		$b = (int) $rowB['id'];
		$this->assertArrayHasKey('RQWATCH_FUZZY_DENIED', $reply['symbols'], 'the changed copy matches the learned mail');
		$matched = json_decode((string) self::query('SELECT fuzzy_hashes FROM mail_log_data WHERE mail_log_id = ?', [$b])[0]['fuzzy_hashes'], true);
		$this->assertNotEmpty(array_intersect($hashesA, is_array($matched) ? $matched : []),
			'stored with the learned digest it matched; options ' . json_encode($reply['symbols']['RQWATCH_FUZZY_DENIED']['options'] ?? null, JSON_THROW_ON_ERROR)
			. ', stored ' . json_encode($matched, JSON_THROW_ON_ERROR) . ', learned ' . json_encode($hashesA, JSON_THROW_ON_ERROR));
		$this->assertSame(1, (int) self::query('SELECT hits FROM mail_log_fuzzy WHERE mail_log_id = ?', [$a])[0]['hits'], 'hit on the learned mail');

		$page = self::learn($admin, $b, self::SPAM, 10);
		$this->assertStringContainsString("Mail {$qidB} learned as fuzzy 'Spam' (weight 10)", $page);
		$this->assertStringContainsString("This mail had already matched learned mail(s) {$qidA}: its own hashes were added as new entries", $page);
		$this->assertStringNotContainsString('already learned via', $page);
		$this->assertSame([], array_values(array_intersect(self::hashes($b), $hashesA)), 'its own digests, none shared');
		$this->assertStringContainsString("Matched learned: {$qidA} (Spam)", strip_tags($page));
	}

	private static function admin(): WebClient {
		$admin = new WebClient((string) getenv('E2E_UI_URL'));
		$admin->login('admin', (string) getenv('E2E_ADMIN_PASS'));
		return $admin;
	}

	private static function detail(int $id): string {
		return "/admin/detail/id/{$id}";
	}

	// learns mail $id into list $flag from its detail page; returns the page it redirects to
	private static function learn(WebClient $admin, int $id, int $flag, int $weight): string {
		[$action, $token] = self::form($admin->get(self::detail($id))['body'], "/fuzzy/learn/\\d+/{$flag}");
		return self::follow($admin, $admin->get($action . '?' . http_build_query(['_token' => $token, 'weight' => (string) $weight])), self::detail($id));
	}

	// unlearns mail $id from its detail page; returns the page it redirects to
	private static function unlearn(WebClient $admin, int $id): string {
		$page = html_entity_decode($admin->get(self::detail($id))['body'], ENT_QUOTES | ENT_HTML5);
		if (!preg_match('~href="([^"]*/fuzzy/unlearn/\d+\?_token=[^"]+)"~', $page, $m)) {
			throw new RuntimeException('Unlearn link not found on the detail page');
		}
		return self::follow($admin, $admin->get($m[1]), self::detail($id));
	}

	/** @return list<string> the learned digests of mail $id, sorted */
	private static function hashes(int $id): array {
		$hashes = json_decode((string) self::query('SELECT hashes FROM mail_log_fuzzy WHERE mail_log_id = ?', [$id])[0]['hashes'], true);
		$hashes = is_array($hashes) ? array_map('strval', array_values($hashes)) : [];
		sort($hashes);
		return $hashes;
	}

	/**
	 * The action and CSRF token of the fuzzy form whose action ends in $path.
	 * @param non-empty-string $path
	 * @return array{string, string}
	 */
	private static function form(string $page, string $path): array {
		if (!preg_match('~action="([^"]*' . $path . ')"[^>]*>\s*<input type="hidden" name="_token" value="([^"]+)"~', $page, $m)) {
			throw new RuntimeException("Form {$path} not found on the detail page");
		}
		return [html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5), html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5)];
	}

	/**
	 * The page an action redirects to, which must be $path, with its flashes decoded.
	 * @param array{status: int, location: string, body: string, headers: array<string, string>} $response
	 */
	private static function follow(WebClient $client, array $response, string $path): string {
		if ($response['status'] !== 302 || !str_ends_with($response['location'], $path)) {
			throw new RuntimeException("Expected a redirect to {$path}, got {$response['status']} {$response['location']}");
		}
		return html_entity_decode($client->get($path)['body'], ENT_QUOTES | ENT_HTML5);
	}

	// probes until $check holds, as the fuzzy storage applies its updates
	private static function waitUntil(string $what, callable $check): void {
		$deadline = microtime(true) + (float) self::WAIT_SECONDS;
		do {
			if ($check()) {
				return;
			}
			usleep(500000);
		} while (microtime(true) < $deadline);
		throw new RuntimeException("Fuzzy storage: not within " . self::WAIT_SECONDS . "s: {$what}");
	}
}
