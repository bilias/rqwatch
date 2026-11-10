<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 A mail stored without an action (the importer stores NULL when the
 metadata has none) is listed and opens like one with an empty action:
 the admin's and the recipient's mail lists, and its detail page.
*/

namespace Tests\Integration\Smoke;

use PHPUnit\Framework\Attributes\TestDox;
use Tests\Integration\IntegrationTestCase;

final class MailWithoutActionTest extends IntegrationTestCase
{
	private const int ID = 9701;

	#[TestDox('Listed for the admin and the recipient, and its detail page opens')]
	public function testListedAndOpens(): void {
		$this->quarantined(self::ID, 'mx1', ['alice@example.org'], ['action' => null]);
		$subject = 'SUBJ-Q-' . self::ID;

		foreach (['admin' => '/admin', 'alice' => '/'] as $user => $list) {
			$page = self::client($user)->get($list);
			$this->assertSame(200, $page['status'], "{$user}: {$list}");
			$this->assertStringContainsString($subject, $page['body'], "{$user}: {$list}");
		}

		$detail = self::client('admin')->get('/admin/detail/id/' . self::ID);
		$this->assertSame(200, $detail['status']);
		$this->assertStringContainsString($subject, $detail['body']);
	}
}
