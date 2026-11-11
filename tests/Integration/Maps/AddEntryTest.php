<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis
 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 MapController::addMapEntry() prefills the add form from the query string:
 the "add to map" links of a mail's detail page carry the map's fields
 (?mail_from=…&rcpt_to=…). Users from tests/Integration/fixtures.sql: alice
 has the alias alias-alice@example.org, which her rcpt_to choices include.
 Each test also opens the form without a query, so an assertion that could
 not fail (a value rendered regardless) would show. A query value that is
 not text (?mail_from[]=…) prefills nothing, instead of showing as Array.
 A mail's detail page links each map's add form with the mail's values,
 the MIME From reduced to its address; the link prefills the form.
*/

namespace Tests\Integration\Maps;

use Tests\Integration\IntegrationTestCase;
use Tests\WebClient;

final class AddEntryTest extends IntegrationTestCase
{
	public function testAdminFormIsPrefilledFromTheQuery(): void {
		$admin = self::client('admin');
		$path = '/admin/map/mail_from_whitelist/add';
		$field = 'map_mail_from_form[mail_from]';

		$empty = $admin->get($path);
		$this->assertSame(200, $empty['status']);
		$this->assertSame('', self::inputValue($empty['body'], $field), 'no query, no value');

		$page = $admin->get($path . '?' . http_build_query(['mail_from' => 'prefill-admin@example.net']));
		$this->assertSame(200, $page['status']);
		$this->assertSame('prefill-admin@example.net', self::inputValue($page['body'], $field));
	}

	public function testUserFormIsPrefilledFromTheQuery(): void {
		$alice = self::client('alice');
		$path = '/user/map/mail_from_rcpt_to_whitelist/add';
		$form = 'map_mail_from_rcpt_to_user_form';

		$empty = $alice->get($path);
		$this->assertSame(200, $empty['status']);
		$this->assertSame('', self::inputValue($empty['body'], "{$form}[mail_from]"), 'no query, no value');
		$this->assertNotSame('alias-alice@example.org', self::selected($empty['body'], "{$form}[rcpt_to]"));

		$page = $alice->get($path . '?' . http_build_query([
			'mail_from' => 'prefill-alice@example.net',
			'rcpt_to' => 'alias-alice@example.org',
		]));
		$this->assertSame(200, $page['status']);
		$this->assertSame('prefill-alice@example.net', self::inputValue($page['body'], "{$form}[mail_from]"));
		$this->assertSame('alias-alice@example.org', self::selected($page['body'], "{$form}[rcpt_to]"));
	}

	public function testPrefillThatIsNotTextIgnored(): void {
		$admin = self::client('admin');
		$form = 'map_mail_from_rcpt_to_form';
		$page = $admin->get('/admin/map/mail_from_rcpt_to_whitelist/add?' . http_build_query([
			'mail_from' => ['nested@example.net'],
			'rcpt_to' => 'prefill-rcpt@example.net',
		]));
		$this->assertSame(200, $page['status']);
		$this->assertSame('', self::inputValue($page['body'], "{$form}[mail_from]"), 'not text: ignored');
		$this->assertSame('prefill-rcpt@example.net', self::inputValue($page['body'], "{$form}[rcpt_to]"), 'the text one still prefills');
	}

	public function testDetailAddLinkCarriesTheMailValues(): void {
		$this->quarantined(9601, 'mx1', ['alice@example.org'], ['mime_from' => '"A Sender" <mime-sender@example.net>']);
		$admin = self::client('admin');
		$detail = $admin->get('/admin/detail/id/9601');
		$this->assertSame(200, $detail['status']);
		$link = html_entity_decode(WebClient::match(
			"#href='(/admin/map/mail_from_rcpt_to_whitelist/add\?[^']+)'#", $detail['body']
		), ENT_QUOTES | ENT_HTML5);
		parse_str((string) parse_url($link, PHP_URL_QUERY), $query);
		$this->assertSame('sender@example.net', $query['mail_from'] ?? null);
		$this->assertSame('alice@example.org', $query['rcpt_to'] ?? null);
		$this->assertSame('9601', $query['return-mail'] ?? null);

		$mime = WebClient::match("#href='(/admin/map/mime_from_rcpt_to_whitelist/add\?[^']+)'#", $detail['body']);
		parse_str((string) parse_url(html_entity_decode($mime, ENT_QUOTES | ENT_HTML5), PHP_URL_QUERY), $mimeQuery);
		$this->assertSame('mime-sender@example.net', $mimeQuery['mime_from'] ?? null, 'the address, without the display name');

		$form = $admin->get($link);
		$this->assertSame('sender@example.net', self::inputValue($form['body'], 'map_mail_from_rcpt_to_form[mail_from]'));
		$this->assertSame('alice@example.org', self::inputValue($form['body'], 'map_mail_from_rcpt_to_form[rcpt_to]'));
	}

	// the value of the <input> named $name: '' without one, null if there is no such input
	private static function inputValue(string $body, string $name): ?string {
		if (!preg_match('/<input\b[^>]*\bname="' . preg_quote($name, '/') . '"[^>]*>/', $body, $input)) {
			return null;
		}
		return preg_match('/\bvalue="([^"]*)"/', $input[0], $value)
			? html_entity_decode($value[1], ENT_QUOTES | ENT_HTML5)
			: '';
	}

	// the selected option's value of the <select> named $name, null if none is selected
	private static function selected(string $body, string $name): ?string {
		if (!preg_match('/<select\b[^>]*\bname="' . preg_quote($name, '/') . '"[^>]*>(.*?)<\/select>/s', $body, $select)) {
			return null;
		}
		return preg_match('/<option\b[^>]*\bvalue="([^"]*)"[^>]*\bselected\b/', $select[1], $option)
			? html_entity_decode($option[1], ENT_QUOTES | ENT_HTML5)
			: null;
	}
}
