<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis
 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 cron:notifications -m, user and admin passes, delivered to Mailpit. Each run
 also handles mail other classes left pending, so every test uses addresses
 of its own and finds admin notifications by qid.
*/

namespace Tests\Import;

use PHPUnit\Framework\Attributes\TestDox;

final class NotificationTest extends ImportTestCase
{
	private const BLACKLISTED_IP = '198.51.100.7';  // tests/Import/fixtures.sql

	#[TestDox('Quarantined discard and reject mail: each recipient notified once, the admin once per mail; a second run sends nothing')]
	public function testEachRecipientNotifiedOnce(): void {
		[$a, $b, $c] = [self::address('notify'), self::address('notify'), self::address('notify')];
		[, $reply, $discard] = $this->import('discard', rcpts: [$a, $b], to: [$a, $b]);
		$this->assertSame('discard', $reply['action'], 'fixture: rspamd verdict');
		[, $reply, $reject] = $this->import('spam', rcpts: [$c], to: [$c]);
		$this->assertSame('reject', $reply['action'], 'fixture: rspamd verdict');

		self::cli('cron:notifications', '-m');

		$this->assertUserNotified($discard, [$a, $b]);
		$this->assertUserNotified($reject, [$c]);
		$this->assertAdminNotified($discard);
		$this->assertAdminNotified($reject);

		$count = self::mailCount();
		self::cli('cron:notifications', '-m');
		$this->assertSame($count, self::mailCount(), 'mail sent by a second run');
	}

	#[TestDox('Score above notification_score: skipped as notified = NULL, nobody notified')]
	public function testAboveScoreSkipped(): void {
		$rcpt = self::address('notify');
		[, $reply, $row] = $this->import('high', rcpts: [$rcpt], to: [$rcpt]);
		$this->assertGreaterThan(50.1, (float) $reply['score'], 'fixture: above notification_score');

		self::cli('cron:notifications', '-m');

		$this->assertUserSkipped($row, [$rcpt]);
		$this->assertAdminSkipped($row);
	}

	#[TestDox('Recipient opted out: skipped as notified = NULL, nothing sent to them; the admin is notified')]
	public function testOptedOutRecipientSkipped(): void {
		$rcpt = self::address('optout');
		self::cli('user:add', '--no-notifications', '-p', bin2hex(random_bytes(16)), strstr($rcpt, '@', true), $rcpt);
		[, , $row] = $this->import('discard', rcpts: [$rcpt], to: [$rcpt]);

		self::cli('cron:notifications', '-m');

		$this->assertUserSkipped($row, [$rcpt]);
		$this->assertAdminNotified($row);
	}

	#[TestDox('Blacklisted: skipped as notified = NULL without -b, and with -b (its score is above notification_score)')]
	public function testBlacklistedSkippedWithAndWithoutB(): void {
		foreach ([[], ['-b']] as $flags) {
			$rcpt = self::address('blacklisted');
			[, $reply, $row] = $this->import('clean', rcpts: [$rcpt], to: [$rcpt], ip: self::BLACKLISTED_IP);
			$this->assertArrayHasKey('RQWATCH_IP_BLACKLIST', $reply['symbols'], 'fixture: IP blacklist');

			self::cli('cron:notifications', '-m', ...$flags);

			$this->assertUserSkipped($row, [$rcpt]);
			$this->assertAdminSkipped($row);
		}
	}

	#[TestDox('Blacklisted with -B: the recipient and the admin are notified, at any score')]
	public function testBlacklistedSentWithBigB(): void {
		$rcpt = self::address('blacklisted');
		[, $reply, $row] = $this->import('clean', rcpts: [$rcpt], to: [$rcpt], ip: self::BLACKLISTED_IP);
		$this->assertGreaterThan(50.1, (float) $reply['score'], 'fixture: above notification_score');

		self::cli('cron:notifications', '-m', '-B');

		$this->assertUserNotified($row, [$rcpt]);
		$this->assertAdminNotified($row);
	}

	#[TestDox('Virus in the virus skip map: nobody notified, user or admin; a virus not in it is notified')]
	public function testVirusSkipMap(): void {
		$skipped = self::address('virus');
		[, $reply, $skippedRow] = $this->import('virus', rcpts: [$skipped], to: [$skipped],
			headers: ['X-Import-Virus: Import-Skip-Test']);
		$this->assertSame('discard', $reply['action'], 'fixture: contrib force_actions.conf');
		$this->assertSame(['Import-Skip-Test'], $reply['symbols']['CLAM_VIRUS']['options'] ?? null, 'fixture: virus name');
		$notified = self::address('virus');
		[, , $notifiedRow] = $this->import('virus', rcpts: [$notified], to: [$notified]);

		self::cli('cron:notifications', '-m');

		$this->assertUserSkipped($skippedRow, [$skipped]);
		$this->assertAdminSkipped($skippedRow);
		$this->assertUserNotified($notifiedRow, [$notified]);
		$this->assertAdminNotified($notifiedRow);
	}

	/**
	 * Each recipient got exactly one notification, addressed to them alone, for this mail.
	 * @param array<string, mixed> $row
	 * @param list<string> $rcpts
	 */
	private function assertUserNotified(array $row, array $rcpts): void {
		foreach ($rcpts as $rcpt) {
			$mails = self::mailsFor($rcpt);
			$this->assertCount(1, $mails, "notifications to {$rcpt}");
			$this->assertSame([$rcpt], self::recipientsOf($mails[0]));
			$this->assertSame('New message stored in quarantine', $mails[0]['Subject']);
			$this->assertStringContainsString("Mail Queue ID: {$row['qid']}",
				(string) self::mailpit("/api/v1/message/{$mails[0]['ID']}")['Text']);
		}
		$state = self::state($row);
		$this->assertSame(1, (int) $state['notified'], 'notified');
		$this->assertNotNull($state['notify_date'], 'notify_date');
	}

	/**
	 * @param array<string, mixed> $row
	 * @param list<string> $rcpts
	 */
	private function assertUserSkipped(array $row, array $rcpts): void {
		foreach ($rcpts as $rcpt) {
			$this->assertSame([], self::mailsFor($rcpt), "notifications to {$rcpt}");
		}
		$this->assertNull(self::state($row)['notified'], 'notified (NULL = skipped)');
	}

	/** @param array<string, mixed> $row */
	private function assertAdminNotified(array $row): void {
		$admin = (string) getenv('IMPORT_ADMIN_RCPT');
		$mails = self::mailsFor($admin, (string) $row['qid']);
		$this->assertCount(1, $mails, "admin notifications for {$row['qid']}");
		$this->assertSame([$admin], self::recipientsOf($mails[0]));
		$this->assertSame('[Admin] New message stored in quarantine', $mails[0]['Subject']);
		$state = self::state($row);
		$this->assertSame(1, (int) $state['admin_notified'], 'admin_notified');
		$this->assertNotNull($state['admin_notify_date'], 'admin_notify_date');
	}

	/**
	 * Skipped by the admin pass: no admin mail, admin_notified NULL with a date.
	 * @param array<string, mixed> $row
	 */
	private function assertAdminSkipped(array $row): void {
		$this->assertSame([], self::mailsFor((string) getenv('IMPORT_ADMIN_RCPT'), (string) $row['qid']),
			"admin notifications for {$row['qid']}");
		$state = self::state($row);
		$this->assertNull($state['admin_notified'], 'admin_notified (NULL = skipped)');
		$this->assertNotNull($state['admin_notify_date'], 'admin_notify_date (dated = skipped by the cron)');
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private static function state(array $row): array {
		return self::query(
			'SELECT notified, notify_date, admin_notified, admin_notify_date FROM mail_logs WHERE id = ?',
			[(int) $row['id']]
		)[0];
	}
}
