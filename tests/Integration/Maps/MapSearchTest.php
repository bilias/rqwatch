<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The admin's map search over HTTP. The posted form redirects to the GET
 results page, which lists a matching entry. A search field that is not a
 scalar is a 400 whether posted or in the query string, never a search for
 "Array". A plain user gets no results page.
*/

namespace Tests\Integration\Maps;

use PHPUnit\Framework\Attributes\TestDox;
use Tests\Integration\IntegrationTestCase;

final class MapSearchTest extends IntegrationTestCase
{
	private const string SENDER = 'map-search-test@example.net';
	private const string PATH = '/admin/map/search';

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		self::db()->prepare("INSERT INTO maps_combined (map_name, user_id, mail_from, created_at, updated_at)"
			. " SELECT 'mail_from_whitelist', id, ?, NOW(), NOW() FROM users WHERE username = 'admin'")
			->execute([self::SENDER]);
	}

	#[\Override]
	protected function tearDown(): void {
		self::db()->prepare('DELETE FROM maps_combined WHERE mail_from = ?')->execute([self::SENDER]);
		parent::tearDown();
	}

	#[TestDox('A posted search redirects to the GET results, which list the matching entry')]
	public function testPostedSearchFindsEntry(): void {
		$admin = self::client('admin');
		$response = $admin->post(self::PATH, [
			'map_search_form[model]' => 'MapCombined',
			'map_search_form[field]' => ' map-search-test ',
			'map_search_form[map_name]' => 'mail_from_whitelist',
		]);
		$this->assertSame(302, $response['status']);
		$this->assertSame(self::PATH, self::target($response));
		$queryString = (string) parse_url($response['location'], PHP_URL_QUERY);
		parse_str($queryString, $query);
		$this->assertSame(['model' => 'MapCombined', 'q' => 'map-search-test', 'map' => 'mail_from_whitelist'], $query);

		$page = $admin->get(self::PATH . '?' . $queryString);
		$this->assertSame(200, $page['status']);
		$this->assertStringContainsString(self::SENDER, $page['body']);
	}

	#[TestDox('A search field that is not a scalar is a 400, posted or in the query string')]
	public function testFieldNotScalarRefused(): void {
		$admin = self::client('admin');
		$posted = $admin->post(self::PATH, [
			'map_search_form[model]' => 'MapCombined',
			'map_search_form[field][]' => 'map-search-test',
		]);
		$this->assertSame(400, $posted['status'], 'posted');

		$queried = $admin->get(self::PATH . '?' . http_build_query(['model' => 'MapCombined', 'q' => ['map-search-test']]));
		$this->assertSame(400, $queried['status'], 'query string');
	}

	#[TestDox('A plain user gets no results page')]
	public function testUserRefused(): void {
		$page = self::client('alice')->get(self::PATH . '?' . http_build_query(['model' => 'MapCombined', 'q' => 'map-search-test']));
		$this->assertNotSame(200, $page['status']);
		$this->assertStringNotContainsString(self::SENDER, $page['body']);
	}
}
