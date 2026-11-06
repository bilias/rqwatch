<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Malformed integer input is the client's error, not the server's: a
 non-integer or array ?page answers 400 (InputBag::getInt() throws
 BadRequestException, which the Router turns into 400 instead of a 500).
 A map add with a malformed ?return-mail, found by ZAP, saves the entry and
 falls back to the map page; a valid one returns to the mail's detail page.
*/

namespace Tests\Integration\Access;

use PHPUnit\Framework\Attributes\TestDox;
use Tests\Integration\IntegrationTestCase;
use Tests\WebClient;

final class BadRequestTest extends IntegrationTestCase
{
	private const MAP = 'mail_from_rcpt_to_whitelist';
	private const FORM = 'map_mail_from_rcpt_to_user_form';
	private const SENDERS = ['bad-return@example.net', 'good-return@example.net'];

	#[\Override]
	protected function tearDown(): void {
		self::db()->exec("DELETE FROM maps_combined WHERE mail_from IN ('" . implode("', '", self::SENDERS) . "')");
		parent::tearDown();
	}

	#[TestDox('A non-integer or array ?page answers 400, a valid one 200')]
	public function testMalformedPageIs400(): void {
		$alice = self::client('alice');
		$this->assertSame(200, $alice->get('/?page=1')['status'], 'valid page');
		foreach (['/?page=abc', '/?page%5B%5D=1'] as $path) {
			$response = $alice->get($path);
			$this->assertSame(400, $response['status'], $path);
			$this->assertSame('Bad Request', $response['body'], $path);
		}
	}

	#[TestDox('Map add: a malformed ?return-mail saves the entry and goes to the map page, a valid one to the mail')]
	public function testReturnMail(): void {
		$alice = self::client('alice');
		$cases = [
			self::SENDERS[0] => ['abc', '/user/map/' . self::MAP],
			self::SENDERS[1] => ['101', '/detail/id/101'],
		];
		foreach ($cases as $sender => [$returnMail, $target]) {
			$path = '/user/map/' . self::MAP . '/add?' . http_build_query(['return-mail' => $returnMail]);
			$token = WebClient::match('/name="' . preg_quote(self::FORM, '/') . '\[_token\]"[^>]*value="([^"]+)"/', $alice->get($path)['body']);
			$response = $alice->post($path, [
				self::FORM . '[mail_from]' => $sender,
				self::FORM . '[rcpt_to]' => 'alice@example.org',
				self::FORM . '[_token]' => $token,
			]);
			$this->assertSame(302, $response['status'], "return-mail={$returnMail}");
			$this->assertSame($target, self::target($response), "return-mail={$returnMail}");
			$this->assertSame(1, (int) self::value('SELECT COUNT(*) FROM maps_combined WHERE mail_from = ?', $sender), "{$sender} saved");
		}
	}
}
