<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The fuzzy list's search (/admin/fuzzy/search?q=): QID, learned by and server
 match as substrings with LIKE's wildcards escaped, a hash prefix of 8-128 hex
 characters matches the start of a digest, and a QID match also lists the
 records sharing a digest with it. The form posts and redirects to the GET
 results; an invalid value goes back to the list.
*/

namespace Tests\Integration\Fuzzy;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Integration\IntegrationTestCase;
use Tests\Integration\WebClient;

final class SearchTest extends IntegrationTestCase
{
	private const FORM = 'fuzzy_search_form';

	// learned mail id => [learned by, server, digests]; 9901 and 9902 share digest 2
	private const LEARNED = [
		9901 => ['fz-alice', 'mx1', [1, 2]],
		9902 => ['fz-bob', 'mx2', [2]],
		9903 => ['fz_carol', 'mx1', [3]],
		9904 => ['fzxcarol', 'mx1', [4]],
		9905 => ['fz-dave', 'mx1', [5]],
	];

	/** @var array<int, int> mail_log_fuzzy id => learned mail id */
	private array $records = [];

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		foreach (self::LEARNED as $id => [$by, $server, $digests]) {
			$qid = $this->quarantined($id, $server);
			self::db()->prepare('INSERT INTO mail_log_fuzzy (mail_log_id, qid, api_server, flag, weight, hashes, learned_by)'
				. ' VALUES (?, ?, ?, ?, ?, ?, ?)')
				->execute([$id, $qid, $server, 11, 10, json_encode(array_map(self::hash(...), $digests)), $by]);
			$this->records[(int) self::db()->lastInsertId()] = $id;
		}
	}

	#[\Override]
	protected function tearDown(): void {
		self::db()->exec('DELETE FROM mail_log_fuzzy WHERE mail_log_id IN (' . implode(',', array_keys(self::LEARNED)) . ')');
		parent::tearDown();
	}

	/** @return array<string, array{string, list<int>}> search, learned mails listed */
	public static function searchCases(): array {
		return [
			'QID, with the record sharing its digest' => ['QIDQ9901', [9901, 9902]],
			'the other holder\'s QID' => ['QIDQ9902', [9901, 9902]],
			'QID without shared digests' => ['QIDQ9903', [9903]],
			'part of a QID' => ['Q990', [9901, 9902, 9903, 9904, 9905]],
			'learned by, not expanded' => ['fz-alice', [9901]],
			'server, not expanded' => ['mx2', [9902]],
			'_ is no wildcard' => ['fz_carol', [9903]],
			'hash prefix' => [substr(self::hash(4), 0, 10), [9904]],
			'a shared hash prefix' => [substr(self::hash(2), 0, 8), [9901, 9902]],
			'upper-case hash prefix' => [strtoupper(substr(self::hash(4), 0, 10)), [9904]],
			'a whole digest' => [self::hash(5), [9905]],
			'7 hex characters are no prefix' => [substr(self::hash(4), 0, 7), []],
			'the middle of a digest is no prefix' => [str_repeat('0', 8), []],
			'nothing' => ['no-such-record', []],
		];
	}

	/** @param list<int> $expected */
	#[DataProvider('searchCases')]
	public function testSearch(string $search, array $expected): void {
		$response = self::client('admin')->get('/admin/fuzzy/search?' . http_build_query(['q' => $search]));
		$this->assertSame(200, $response['status'], $search);
		$this->assertSame($expected, $this->listed($response['body']), $search);
	}

	/** @return array<string, array{string}> */
	public static function invalidCases(): array {
		return [
			'empty' => [''],
			'a space' => ['fz alice'],
			'a LIKE wildcard' => ['fz%'],
			'129 characters' => [str_repeat('a', 129)],
		];
	}

	#[DataProvider('invalidCases')]
	public function testInvalidSearchGoesToTheList(string $search): void {
		$response = self::client('admin')->get('/admin/fuzzy/search?' . http_build_query(['q' => $search]));
		$this->assertSame(302, $response['status'], $search);
		$this->assertStringEndsWith('/admin/fuzzy', $response['location']);
	}

	public function testFormRedirectsToResults(): void {
		$admin = self::client('admin');
		$token = WebClient::match('/name="' . self::FORM . '\[_token\]"[^>]*value="([^"]+)"/', $admin->get('/admin/fuzzy')['body']);
		$response = $admin->post('/admin/fuzzy/search', [self::FORM . '[search]' => 'fz-alice', self::FORM . '[_token]' => $token]);
		$this->assertSame(302, $response['status']);
		$this->assertStringEndsWith('/admin/fuzzy/search?q=fz-alice', $response['location']);

		$response = $admin->post('/admin/fuzzy/search', [self::FORM . '[search]' => 'fz alice', self::FORM . '[_token]' => $token]);
		$this->assertSame(302, $response['status']);
		$this->assertStringEndsWith('/admin/fuzzy', $response['location']);
		$this->assertStringContainsString('The value can only contain letters', $admin->get('/admin/fuzzy')['body']);
	}

	// a 128-hex-digit digest that starts with a letter pair distinct per $n
	private static function hash(int $n): string {
		return sprintf('%02x', 0xa0 + $n) . str_repeat('0', 126);
	}

	/** @return list<int> the learned mails of this test listed on the page, in id order */
	private function listed(string $body): array {
		preg_match_all('~/admin/fuzzy/unlearn/(\d+)~', $body, $m);
		$listed = [];
		foreach ($m[1] as $record) {
			if (isset($this->records[(int) $record])) {
				$listed[] = $this->records[(int) $record];
			}
		}
		sort($listed);
		return $listed;
	}
}
