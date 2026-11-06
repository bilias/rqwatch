<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Release through the web UI, delivered to Mailpit: alice releases her own
 quarantined mail, and the admin releases it, with the release form, from a
 mail stored on this node (mx1, sent by releaseHtmlMail()) and on another node
 (mx2, whose API is this instance's own release_mail.php, so the web node goes
 through releaseMailViaApi()). Each test creates its own mail to alice and its
 file; deliveries are found by the mail's qid, which the release mail carries.
*/

namespace Tests\Integration\Delivery;

use PHPUnit\Framework\Attributes\TestDox;
use Tests\Integration\IntegrationTestCase;
use Tests\WebClient;

final class WebReleaseTest extends IntegrationTestCase
{
	private const ALICE = 'alice@example.org';

	#[TestDox('Local mail (mx1): one delivery to alice, the quarantined file attached byte for byte, released set')]
	public function testLocalRelease(): void {
		$qid = $this->quarantined(9101, 'mx1');
		$count = self::mailCount();

		$this->release(9101, ['release' => '1']);

		$mail = $this->assertReleaseDelivered($qid, [self::ALICE], 9101);
		$this->assertSame('Message released from quarantine', $mail['Subject']);
		$this->assertSame($count + 1, self::mailCount(), 'messages sent');
		$this->assertReleased(9101);
	}

	#[TestDox('Local mail (mx1) to alice and an alternate address: both receive it')]
	public function testLocalReleaseWithAlternate(): void {
		$qid = $this->quarantined(9102, 'mx1');
		$alternate = self::address('alternate');

		$this->release(9102, ['release' => '1', 'release_alt' => '1', 'email_alt' => $alternate]);

		$this->assertReleaseDelivered($qid, [self::ALICE, $alternate], 9102);
		$this->assertReleased(9102);
	}

	#[TestDox('The disabled email field is server-filled: a posted address is ignored, only the mail\'s recipient receives it')]
	public function testPostedRecipientIsIgnored(): void {
		$qid = $this->quarantined(9103, 'mx1');
		$intruder = self::address('intruder');
		$count = self::mailCount();

		$this->release(9103, ['release' => '1', 'email' => $intruder]);

		$this->assertReleaseDelivered($qid, [self::ALICE], 9103);
		$this->assertSame([], self::mailsFor($intruder), "mail to {$intruder}");
		$this->assertSame($count + 1, self::mailCount(), 'messages sent');
	}

	#[TestDox('Remote mail (mx2) to alice and an alternate address: sent through release_mail.php, both receive it')]
	public function testRemoteReleaseWithAlternate(): void {
		$qid = $this->quarantined(9104, 'mx2');
		$alternate = self::address('alternate');
		$count = self::mailCount();

		$this->release(9104, ['release' => '1', 'release_alt' => '1', 'email_alt' => $alternate]);

		$this->assertReleaseDelivered($qid, [self::ALICE, $alternate], 9104);
		$this->assertSame($count + 1, self::mailCount(), 'messages sent');
		$this->assertReleased(9104);
	}

	#[TestDox('Local mail (mx1) to an alternate address only: the alternate receives it, alice does not')]
	public function testLocalReleaseToAlternateOnly(): void {
		$qid = $this->quarantined(9108, 'mx1');
		$alternate = self::address('alternate');
		$count = self::mailCount();

		$this->release(9108, ['release_alt' => '1', 'email_alt' => $alternate]);

		$this->assertReleaseDelivered($qid, [$alternate], 9108);
		$this->assertSame([], self::mailsFor(self::ALICE, $qid), 'mail to the original recipient');
		$this->assertSame($count + 1, self::mailCount(), 'messages sent');
		$this->assertReleased(9108);
	}

	#[TestDox('Remote mail (mx2) to an alternate address only: through release_mail.php, alice does not receive it')]
	public function testRemoteReleaseToAlternateOnly(): void {
		$qid = $this->quarantined(9109, 'mx2');
		$alternate = self::address('alternate');
		$count = self::mailCount();

		$this->release(9109, ['release_alt' => '1', 'email_alt' => $alternate]);

		$this->assertReleaseDelivered($qid, [$alternate], 9109);
		$this->assertSame([], self::mailsFor(self::ALICE, $qid), 'mail to the original recipient');
		$this->assertSame($count + 1, self::mailCount(), 'messages sent');
		$this->assertReleased(9109);
	}

	#[TestDox('Admin, alice\'s local mail (mx1) to her and an alternate address: both receive it')]
	public function testAdminReleaseWithAlternate(): void {
		$qid = $this->quarantined(9105, 'mx1');
		$alternate = self::address('alternate');
		$count = self::mailCount();

		$this->release(9105, ['release' => '1', 'release_alt' => '1', 'email_alt' => $alternate], 'admin');

		$this->assertReleaseDelivered($qid, [self::ALICE, $alternate], 9105);
		$this->assertSame($count + 1, self::mailCount(), 'messages sent');
		$this->assertReleased(9105);
	}

	#[TestDox('Admin, alice\'s local mail (mx1) to an alternate address only: the alternate receives it, alice does not')]
	public function testAdminReleaseToAlternateOnly(): void {
		$qid = $this->quarantined(9106, 'mx1');
		$alternate = self::address('alternate');
		$count = self::mailCount();

		$this->release(9106, ['release_alt' => '1', 'email_alt' => $alternate], 'admin');

		$this->assertReleaseDelivered($qid, [$alternate], 9106);
		$this->assertSame([], self::mailsFor(self::ALICE, $qid), 'mail to the original recipient');
		$this->assertSame($count + 1, self::mailCount(), 'messages sent');
		$this->assertReleased(9106);
	}

	#[TestDox('Admin, alice\'s remote mail (mx2) to an alternate address only: through release_mail.php, alice does not receive it')]
	public function testAdminRemoteReleaseToAlternateOnly(): void {
		$qid = $this->quarantined(9107, 'mx2');
		$alternate = self::address('alternate');
		$count = self::mailCount();

		$this->release(9107, ['release_alt' => '1', 'email_alt' => $alternate], 'admin');

		$this->assertReleaseDelivered($qid, [$alternate], 9107);
		$this->assertSame([], self::mailsFor(self::ALICE, $qid), 'mail to the original recipient');
		$this->assertSame($count + 1, self::mailCount(), 'messages sent');
		$this->assertReleased(9107);
	}

	/**
	 * $user opens the mail page and submits its release form with $fields
	 * ("release" and "release_alt" are the two checkboxes).
	 * @param array<string, string> $fields field => value, without the form prefix
	 */
	private function release(int $id, array $fields, string $user = 'alice'): void {
		$client = self::client($user);
		$prefix = $user === 'admin' ? '/admin' : '';
		$page = $client->get("{$prefix}/mail/{$id}");
		$this->assertSame(200, $page['status'], "mail page {$id}");
		$post = [
			'mail_release_form[_token]' => WebClient::match('/name="mail_release_form\[_token\]"[^>]*value="([^"]+)"/', $page['body']),
		];
		foreach ($fields as $field => $value) {
			$post["mail_release_form[{$field}]"] = $value;
		}
		$response = $client->post("{$prefix}/release/{$id}", $post);
		$this->assertSame(302, $response['status'], "release {$id}");
	}
}
