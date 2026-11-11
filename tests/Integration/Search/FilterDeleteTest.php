<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Deleting a search filter takes it off the active filters and puts it back
 in the search form, to edit and add again: filter, choice and value.
*/

namespace Tests\Integration\Search;

use PHPUnit\Framework\Attributes\TestDox;
use Tests\Integration\IntegrationTestCase;
use Tests\WebClient;

final class FilterDeleteTest extends IntegrationTestCase
{
	private const string FORM = 'search_form';

	#[TestDox('A deleted filter leaves the active filters and refills the search form')]
	public function testDeletedFilterRefillsTheForm(): void {
		$admin = self::client('admin');
		$admin->get('/admin/search/del');
		$token = WebClient::match('/name="' . self::FORM . '\[_token\]"[^>]*value="([^"]+)"/', $admin->get('/admin/search')['body']);
		$added = $admin->post('/admin/search', [
			self::FORM . '[filter]' => 'Subject',
			self::FORM . '[choice]' => 'contains',
			self::FORM . '[value]' => 'refill-test-value',
			self::FORM . '[search]' => '',
			self::FORM . '[_token]' => $token,
		]);
		$this->assertSame(302, $added['status']);
		$this->assertStringContainsString('refill-test-value', $admin->get('/admin/search')['body'], 'the filter is active');

		$deleted = $admin->get('/admin/search/del/0');
		$this->assertSame(302, $deleted['status']);
		$this->assertSame('/admin/search', self::target($deleted));

		$page = $admin->get('/admin/search')['body'];
		$this->assertMatchesRegularExpression('/name="' . self::FORM . '\[value\]"[^>]*value="refill-test-value"/', $page, 'the value is back in the form');
		$this->assertMatchesRegularExpression('/<option value="Subject" selected="selected">/', $page, 'and the filter');
		$this->assertMatchesRegularExpression('/<option value="contains" selected="selected">/', $page, 'and the choice');
		$this->assertStringNotContainsString('refill-test-value', (string) preg_replace('/<form.*?<\/form>/s', '', $page), 'and no longer active');

		$admin->get('/admin/search/del');
	}
}
