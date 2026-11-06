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
 (qa/e2e-tests.sh points mx2 at this copy's own API).
*/

namespace Tests\EndToEnd\Fuzzy;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use RuntimeException;
use Tests\EndToEnd\EndToEndTestCase;
use Tests\WebClient;

final class WebLearnTest extends EndToEndTestCase
{
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
		$admin = new WebClient((string) getenv('E2E_UI_URL'));
		$admin->login('admin', (string) getenv('E2E_ADMIN_PASS'));
		$detail = "/admin/detail/id/{$id}";

		// learn as Spam (flag 11) with weight 7
		[$action, $token] = self::form($admin->get($detail)['body'], '/fuzzy/learn/\d+/11');
		$page = self::follow($admin, $admin->get($action . '?' . http_build_query(['_token' => $token, 'weight' => '7'])), $detail);
		$this->assertStringContainsString("Mail {$qid} learned as fuzzy 'Spam' (weight 7)", $page);
		$record = self::query('SELECT id, flag, weight, api_server, learned_by FROM mail_log_fuzzy WHERE mail_log_id = ?', [$id]);
		$this->assertCount(1, $record, 'mail_log_fuzzy record');
		$this->assertSame([11, 7, $server, 'admin'],
			[(int) $record[0]['flag'], (int) $record[0]['weight'], $record[0]['api_server'], $record[0]['learned_by']]);
		self::waitForFuzzy($body, true);
		$before = self::fuzzyScore($body);
		$this->assertNotNull($before);

		// Add weight 20: rspamd adds it to the learned value, so the score rises
		[$action, $token] = self::form($page, '/fuzzy/boost/\d+');
		$page = self::follow($admin, $admin->get($action . '?' . http_build_query(['_token' => $token, 'weight' => '20'])), $detail);
		$this->assertStringContainsString("Mail {$qid}: weight +20 added to fuzzy 'Spam', now 27", $page);
		$this->assertSame(27, (int) self::query('SELECT weight FROM mail_log_fuzzy WHERE mail_log_id = ?', [$id])[0]['weight']);
		$this->assertGreaterThan($before, self::waitForScoreAbove($body, $before));

		// unlearn
		if (!preg_match('~href="([^"]*/fuzzy/unlearn/\d+\?_token=[^"]+)"~', $page, $m)) {
			throw new RuntimeException('Unlearn link not found on the detail page');
		}
		$page = self::follow($admin, $admin->get(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5)), $detail);
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

	// probes until a copy of $body scores above $score; returns the last score
	private static function waitForScoreAbove(string $body, float $score): float {
		$deadline = microtime(true) + (float) self::WAIT_SECONDS;
		do {
			$now = self::fuzzyScore($body) ?? 0.0;
			if ($now > $score) {
				return $now;
			}
			usleep(500000);
		} while (microtime(true) < $deadline);
		return $now;
	}
}
