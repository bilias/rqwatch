<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The search page's statistics for a Date filter: the count, and the oldest
 and newest record (the created_at of the first and last mail matched).
 Mails on the day before and after are not counted.
*/

namespace Tests\Integration\Search;

use Tests\Integration\IntegrationTestCase;
use Tests\WebClient;

final class SearchStatsTest extends IntegrationTestCase
{
	// id => created_at
	private const MAILS = [
		9611 => '2023-05-09 23:59:59',
		9612 => '2023-05-10 08:00:00',
		9613 => '2023-05-10 12:15:00',
		9614 => '2023-05-10 17:30:00',
		9615 => '2023-05-11 00:00:00',
	];

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		foreach (self::MAILS as $id => $createdAt) {
			$this->quarantined($id, 'mx1', ['admin@example.org'], ['created_at' => $createdAt]);
		}
	}

	public function testDayStats(): void {
		$admin = self::client('admin');
		$token = WebClient::match('/name="search_form\[_token\]"[^>]*value="([^"]+)"/', $admin->get('/admin/search')['body']);
		$posted = $admin->post('/admin/search', [
			'search_form[filter]' => 'Date',
			'search_form[choice]' => 'is equal to',
			'search_form[value]' => '2023-05-10',
			'search_form[_token]' => $token,
		]);
		$this->assertSame(302, $posted['status']);

		$body = $admin->get('/admin/search')['body'];
		$this->assertStringContainsString('Total Records:</span> 3</li>', $body);
		$this->assertStringContainsString('Oldest Record:</span> 2023-05-10 08:00:00', $body);
		$this->assertStringContainsString('Newest Record:</span> 2023-05-10 17:30:00', $body);
	}
}
