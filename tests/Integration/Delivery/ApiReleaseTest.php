<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis
 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Release through release_mail.php, called directly as a web node calls it,
 delivered to Mailpit: the storing node's side of a remote release, and its
 refusals. The recipient check of a web release is in the web controller
 (WebReleaseTest); the API trusts its caller.
*/

namespace Tests\Integration\Delivery;

use PHPUnit\Framework\Attributes\TestDox;
use Tests\Integration\IntegrationTestCase;

final class ApiReleaseTest extends IntegrationTestCase
{
	#[TestDox('Release to the original recipient: one delivery, the quarantined mail attached byte for byte, released set')]
	public function testReleaseToOriginalRecipient(): void {
		$rcpt = self::address('release');
		$qid = $this->quarantined(9401, 'mx1', [$rcpt]);

		$this->assertSame([200, 'Message Released'], self::release(9401, [$rcpt]));

		$this->assertReleaseDelivered($qid, [$rcpt], 9401);
		$this->assertReleased(9401);
	}

	#[TestDox('Release to the original and an alternate recipient: both receive it')]
	public function testReleaseToAlternateRecipientToo(): void {
		$rcpt = self::address('release');
		$alternate = self::address('alternate');
		$qid = $this->quarantined(9402, 'mx1', [$rcpt]);

		$this->assertSame([200, 'Message Released'], self::release(9402, [$rcpt, $alternate]));

		$this->assertReleaseDelivered($qid, [$rcpt, $alternate], 9402);
		$this->assertReleased(9402);
	}

	#[TestDox('Release to an invalid address: refused (400), nothing sent')]
	public function testReleaseToInvalidAddressIsRefused(): void {
		$rcpt = self::address('release');
		$this->quarantined(9403, 'mx1', [$rcpt]);
		$count = self::mailCount();

		$this->assertSame([400, 'Invalid destination email'], self::release(9403, [$rcpt, 'not-an-address']));
		$this->assertSame($count, self::mailCount(), 'mail sent');
		$this->assertNotReleased(9403);
	}

	#[TestDox('Release of a mail that does not exist: refused (400), nothing sent')]
	public function testReleaseOfMissingMailIsRefused(): void {
		$count = self::mailCount();
		$id = self::valueInt('SELECT COALESCE(MAX(id), 0) + 1000 FROM mail_logs');

		$this->assertSame([400, 'Message not found'], self::release($id, [self::address('release')]));
		$this->assertSame($count, self::mailCount(), 'mail sent');
	}

	#[TestDox('Release of a mail not in quarantine: refused (500), nothing sent')]
	public function testReleaseOfUnstoredMailIsRefused(): void {
		$rcpt = self::address('release');
		$this->quarantined(9404, 'mx1', [$rcpt], ['action' => 'no action', 'score' => -0.1, 'mail_stored' => 0]);
		$count = self::mailCount();

		$this->assertSame([500, 'Message Release Failed'], self::release(9404, [$rcpt]));
		$this->assertSame($count, self::mailCount(), 'mail sent');
		$this->assertNotReleased(9404);
	}

	private function assertNotReleased(int $id): void {
		$this->assertSame(0, self::valueInt('SELECT released FROM mail_logs WHERE id = ?', $id), 'released');
		$this->assertNull(self::value('SELECT release_date FROM mail_logs WHERE id = ?', $id), 'release_date');
	}

	/**
	 * @param list<string> $emails
	 * @return array{0: int, 1: string} status and body
	 */
	private static function release(int $id, array $emails): array {
		return self::mailApi('release_mail.php', ['remote_user' => 'integration-test', 'id' => (string) $id, 'email' => $emails]);
	}
}
