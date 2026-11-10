<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Password-less view and release from notification links
 (NOTIFICATION_RELEASE_LINKS), without logging in. Each test switches links
 on through .env (both PHP-FPM and bin/cli.php reread it), creates its own
 mail, runs cron:notifications -m and takes the token from that recipient's
 notification in Mailpit. An anonymous client then does what a browser does:
 the landing page's script posts the token to /notify/redeem, which answers
 with a session-bound handle; confirm, view and release follow. Each cron run
 also notifies the fixture mails still pending.
*/

namespace Tests\Integration\Delivery;

use PHPUnit\Framework\Attributes\TestDox;
use RuntimeException;
use Tests\Integration\IntegrationTestCase;
use Tests\WebClient;

final class NotificationLinkTest extends IntegrationTestCase
{
	private const ALICE = 'alice@example.org';
	private const BOB = 'bob@example.org';
	private const INVALID = 'This link is not valid, has already been used, or has expired.';
	private const RELEASED = 'The message has been released and should arrive in your mailbox shortly.';
	private const DISABLED = 'Links in notification mails are disabled.';

	#[TestDox('Link: landing and confirm show nothing of the mail, view shows it, release delivers it to the recipient; token pages are no-store, no-referrer')]
	public function testViewAndRelease(): void {
		self::linksOn(function (): void {
			$qid = $this->quarantined(9201, 'mx1');
			self::cli('cron:notifications', '-m');
			$token = self::linkToken(self::ALICE, $qid);
			$browser = self::anonymous();

			$landing = $browser->get('/notify');
			$this->assertSame(200, $landing['status'], 'landing');
			$this->assertTokenPage($landing, 'landing');
			$this->assertStringNotContainsString('SUBJ-Q-9201', $landing['body'], 'landing');

			$handle = $this->redeem($browser, $token);
			$confirm = $browser->get("/notify/confirm/{$handle}");
			$this->assertSame(200, $confirm['status'], 'confirm');
			$this->assertTokenPage($confirm, 'confirm');
			$this->assertStringNotContainsString('SUBJ-Q-9201', $confirm['body'], 'confirm');

			$view = $this->view($browser, $handle);
			$this->assertSame(200, $view['status'], 'view');
			$this->assertTokenPage($view, 'view');
			$this->assertNoRemoteLoads($view, 'view');
			// the mail's inline styles are allowed on the preview page
			$this->assertStringContainsString("style-src 'self' 'unsafe-inline';", $view['headers']['content-security-policy'] ?? '', 'view: CSP style-src');
			$this->assertStringContainsString('SUBJ-Q-9201', $view['body'], 'view');
			$this->assertStringContainsString('Body of SUBJ-Q-9201', $view['body'], 'view');

			$count = self::mailCount();
			$this->assertStringContainsString(self::RELEASED, $this->release($browser, $handle, $view)['body']);
			$this->assertReleaseDelivered($qid, [self::ALICE], 9201);
			$this->assertSame($count + 1, self::mailCount(), 'messages sent');
			$this->assertReleased(9201);
			$this->assertSame(0, self::tokens(9201), 'tokens left for the mail');
		});
	}

	#[TestDox('Link: once viewed in one browser it stops working in another; after release it is gone')]
	public function testOneTimeAndSingleUse(): void {
		self::linksOn(function (): void {
			$qid = $this->quarantined(9202, 'mx1');
			self::cli('cron:notifications', '-m');
			$token = self::linkToken(self::ALICE, $qid);

			$first = self::anonymous();
			$handle = $this->redeem($first, $token);
			$view = $this->view($first, $handle);
			$this->assertStringContainsString('SUBJ-Q-9202', $view['body']);

			$this->assertInvalid(self::redeemResponse(self::anonymous(), $token), 'second browser after view');

			$this->assertStringContainsString(self::RELEASED, $this->release($first, $handle, $view)['body']);
			$this->assertReleaseDelivered($qid, [self::ALICE], 9202);
			$this->assertInvalid(self::redeemResponse(self::anonymous(), $token), 'after release');
		});
	}

	#[TestDox('Link: the handle is bound to its session; another browser gets nothing')]
	public function testHandleNeedsItsSession(): void {
		self::linksOn(function (): void {
			$qid = $this->quarantined(9203, 'mx1');
			self::cli('cron:notifications', '-m');
			$handle = $this->redeem(self::anonymous(), self::linkToken(self::ALICE, $qid));

			$other = self::anonymous()->get("/notify/confirm/{$handle}");
			$this->assertInvalid($other, 'confirm in another session');
		});
	}

	#[TestDox('Link: release goes only to the link\'s recipient, whatever is posted; the other recipient\'s link still works')]
	public function testReleaseGoesToTheLinkRecipientOnly(): void {
		self::linksOn(function (): void {
			$qid = $this->quarantined(9204, 'mx1', [self::ALICE, self::BOB]);
			self::cli('cron:notifications', '-m');
			$intruder = self::address('intruder');

			$alice = self::anonymous();
			$handle = $this->redeem($alice, self::linkToken(self::ALICE, $qid));
			$view = $this->view($alice, $handle);
			$count = self::mailCount();
			$released = $this->release($alice, $handle, $view, ['email' => $intruder, 'recipient' => $intruder, 'release_to' => $intruder]);
			$this->assertStringContainsString(self::RELEASED, $released['body']);
			$this->assertReleaseDelivered($qid, [self::ALICE], 9204);
			$this->assertSame($count + 1, self::mailCount(), 'messages sent');
			$this->assertSame([], self::mailsFor($intruder), "mail to {$intruder}");

			$bob = self::anonymous();
			$handle = $this->redeem($bob, self::linkToken(self::BOB, $qid));
			$this->assertStringContainsString(self::RELEASED, $this->release($bob, $handle, $this->view($bob, $handle))['body']);
			$this->assertReleaseDelivered($qid, [self::BOB], 9204);
		});
	}

	#[TestDox('Link to a mail on another node (mx2): released through release_mail.php')]
	public function testRemoteMail(): void {
		self::linksOn(function (): void {
			$qid = $this->quarantined(9205, 'mx2');
			self::cli('cron:notifications', '-m');
			$browser = self::anonymous();
			$handle = $this->redeem($browser, self::linkToken(self::ALICE, $qid));
			$view = $this->view($browser, $handle);
			$this->assertStringContainsString('Body of SUBJ-Q-9205', $view['body'], 'view through get_mail.php');

			$this->assertStringContainsString(self::RELEASED, $this->release($browser, $handle, $view)['body']);
			$this->assertReleaseDelivered($qid, [self::ALICE], 9205);
			$this->assertReleased(9205);
		});
	}

	#[TestDox('Refused, nothing sent: unknown or malformed token, no CSRF token, view without CSRF, mail no longer stored')]
	public function testRefusals(): void {
		self::linksOn(function (): void {
			$qid = $this->quarantined(9206, 'mx1');
			self::cli('cron:notifications', '-m');
			$token = self::linkToken(self::ALICE, $qid);
			$count = self::mailCount();

			$this->assertInvalid(self::redeemResponse(self::anonymous(), bin2hex(random_bytes(32))), 'unknown token');
			$this->assertInvalid(self::redeemResponse(self::anonymous(), 'not-a-token'), 'malformed token');
			$this->assertInvalid(self::anonymous()->post('/notify/redeem', ['token' => $token]), 'redeem without CSRF token');

			$browser = self::anonymous();
			$handle = $this->redeem($browser, $token);
			$this->assertInvalid($browser->post("/notify/view/{$handle}", []), 'view without CSRF token');

			self::db()->prepare('UPDATE mail_logs SET mail_stored = 0 WHERE id = ?')->execute([9206]);
			$this->assertInvalid(self::redeemResponse(self::anonymous(), $token), 'mail no longer stored');
			$this->assertInvalid($browser->get("/notify/confirm/{$handle}"), 'handle of a mail no longer stored');

			$this->assertSame($count, self::mailCount(), 'mail sent');
			$this->assertSame(0, self::valueInt('SELECT released FROM mail_logs WHERE id = ?', 9206), 'released');
		});
	}

	#[TestDox('Links off: the notification has no link, no token is issued, and the link pages only say links are disabled')]
	public function testDisabled(): void {
		$qid = $this->quarantined(9207, 'mx1');
		self::cli('cron:notifications', '-m');

		$notice = self::notification(self::ALICE, $qid);
		$this->assertDoesNotMatchRegularExpression('/\/notify#/', $notice, 'link in the notification');
		$this->assertSame(0, self::tokens(9207), 'tokens issued');

		$browser = self::anonymous();
		$this->assertStringContainsString(self::DISABLED, $browser->get('/notify')['body'], 'landing');
		$this->assertStringContainsString(self::DISABLED, $browser->post('/notify/redeem', ['token' => bin2hex(random_bytes(32))])['body'], 'redeem');
	}

	/** Runs $test with notification links switched on. */
	private static function linksOn(callable $test): void {
		self::withEnv(['NOTIFICATION_RELEASE_LINKS' => 'true'], $test);
	}

	/** Text of the one notification $recipient got for the mail $qid. */
	private static function notification(string $recipient, string $qid): string {
		$mails = array_values(array_filter(self::mailsFor($recipient, $qid),
			fn($mail) => $mail['Subject'] === 'New message stored in quarantine'));
		if (count($mails) !== 1) {
			throw new RuntimeException("expected one notification to {$recipient} for {$qid}, found " . count($mails));
		}
		return self::messageText($mails[0]['ID']);
	}

	/** The token in $recipient's notification link for the mail $qid. */
	private static function linkToken(string $recipient, string $qid): string {
		if (!preg_match('/\/notify#([a-f0-9]{64})\b/', self::notification($recipient, $qid), $m)) {
			throw new RuntimeException("no link in the notification to {$recipient} for {$qid}");
		}
		return $m[1];
	}

	/**
	 * What the landing page's script does: post the token to the form's action.
	 * @return array{status: int, location: string, body: string, headers: array<string, string>}
	 */
	private static function redeemResponse(WebClient $browser, string $token): array {
		$landing = $browser->get('/notify');
		return $browser->post(WebClient::match("/<form id='redeem' method='post' action='([^']+)'/", $landing['body']), ['token' => $token]);
	}

	/** Redeems $token in $browser's session and returns the handle it gets. */
	private function redeem(WebClient $browser, string $token): string {
		$response = self::redeemResponse($browser, $token);
		$this->assertSame(303, $response['status'], 'redeem');
		$this->assertMatchesRegularExpression('#/notify/confirm/[a-f0-9]{32}$#', $response['location'], 'redeem');
		return substr($response['location'], -32);
	}

	/**
	 * Confirm page, then its button.
	 * @return array{status: int, location: string, body: string, headers: array<string, string>}
	 */
	private function view(WebClient $browser, string $handle): array {
		$confirm = $browser->get("/notify/confirm/{$handle}");
		return $browser->post(WebClient::match("/<form method='post' action='([^']+)'/", $confirm['body']), []);
	}

	/**
	 * The view page's release button, plus any $extra fields a client might add.
	 * @param array{status: int, location: string, body: string, headers: array<string, string>} $view
	 * @param array<string, string> $extra
	 * @return array{status: int, location: string, body: string, headers: array<string, string>}
	 */
	private function release(WebClient $browser, string $handle, array $view, array $extra = []): array {
		$action = WebClient::match("/<form method='post' action='([^']*\\/notify\\/release\\/{$handle}[^']*)'/", $view['body']);
		return $browser->post($action, $extra);
	}

	/** @param array{status: int, location: string, body: string, headers: array<string, string>} $page */
	private function assertTokenPage(array $page, string $what): void {
		$this->assertStringContainsString('no-store', $page['headers']['cache-control'] ?? '', "{$what}: Cache-Control");
		$this->assertSame('no-referrer', $page['headers']['referrer-policy'] ?? '', "{$what}: Referrer-Policy");
	}

	/** @param array{status: int, location: string, body: string, headers: array<string, string>} $page */
	private function assertInvalid(array $page, string $what): void {
		$this->assertSame(200, $page['status'], $what);
		$this->assertStringContainsString(self::INVALID, $page['body'], $what);
	}

	private static function tokens(int $id): int {
		return self::valueInt('SELECT COUNT(*) FROM mail_log_tokens WHERE mail_log_id = ?', $id);
	}
}
