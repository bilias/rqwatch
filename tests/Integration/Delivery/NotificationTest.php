<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis
 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 cron:notifications -m, user and admin passes, delivered to Mailpit. Each mail
 is written as the importer stores rspamd's verdict: action, score, symbols,
 and enrolled in the admin feed (admin_notified = 0). Each run also handles
 mail other classes left pending, so every test uses addresses of its own and
 finds admin notifications by qid.
*/

namespace Tests\Integration\Delivery;

use PDO;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Integration\IntegrationTestCase;

final class NotificationTest extends IntegrationTestCase
{
	private const BLACKLIST = ['RQWATCH_IP_BLACKLIST' => ['name' => 'RQWATCH_IP_BLACKLIST', 'score' => 1000, 'options' => ['198.51.100.7']]];

	#[TestDox('Quarantined discard and reject mail: each recipient notified once, the admin once per mail; a second run sends nothing')]
	public function testEachRecipientNotifiedOnce(): void {
		[$a, $b, $c] = [self::address('notify'), self::address('notify'), self::address('notify')];
		$this->pending(9301, [$a, $b], 'discard', 12.4);
		$this->pending(9302, [$c], 'reject', 49.9);

		self::cli('cron:notifications', '-m');

		$this->assertUserNotified(9301, [$a, $b]);
		$this->assertUserNotified(9302, [$c]);
		$this->assertAdminNotified(9301);
		$this->assertAdminNotified(9302);

		$count = self::mailCount();
		self::cli('cron:notifications', '-m');
		$this->assertSame($count, self::mailCount(), 'mail sent by a second run');
	}

	#[TestDox('Score above notification_score: skipped as notified = NULL, nobody notified')]
	public function testAboveScoreSkipped(): void {
		$rcpt = self::address('notify');
		$this->pending(9303, [$rcpt], 'reject', 59.9);

		self::cli('cron:notifications', '-m');

		$this->assertUserSkipped(9303, [$rcpt]);
		$this->assertAdminSkipped(9303);
	}

	#[TestDox('Recipient opted out: skipped as notified = NULL, nothing sent to them; the admin is notified')]
	public function testOptedOutRecipientSkipped(): void {
		$rcpt = self::address('optout');
		self::optOut($rcpt);
		$this->pending(9304, [$rcpt], 'discard', 12.4);

		self::cli('cron:notifications', '-m');

		$this->assertUserSkipped(9304, [$rcpt]);
		$this->assertAdminNotified(9304);
	}

	#[TestDox('One recipient opted out among others: only the others notified, the mail keeps every recipient')]
	public function testOptedOutRecipientAmongOthers(): void {
		$rcpt = self::address('notify');
		$optout = self::address('optout');
		self::optOut($optout);
		$this->pending(9305, [$rcpt, $optout], 'discard', 12.4);
		$rcptTo = self::valueNullableString('SELECT rcpt_to FROM mail_logs WHERE id = ?', 9305);
		$recipients = self::recipients(9305);
		$this->assertCount(2, $recipients, 'fixture: two recipients');

		self::cli('cron:notifications', '-m');

		$this->assertUserNotified(9305, [$rcpt]);
		$this->assertSame([], self::mailsFor($optout), "notifications to {$optout}");
		$this->assertSame($rcptTo, self::value('SELECT rcpt_to FROM mail_logs WHERE id = ?', 9305), 'rcpt_to');
		$this->assertSame($recipients, self::recipients(9305), 'recipient rows');
		$this->assertAdminNotified(9305);
	}

	#[TestDox('Blacklisted: skipped as notified = NULL without -b, and with -b (its score is above notification_score)')]
	public function testBlacklistedSkippedWithAndWithoutB(): void {
		foreach ([9306 => [], 9307 => ['-b']] as $id => $flags) {
			$rcpt = self::address('blacklisted');
			$this->pending($id, [$rcpt], 'reject', 999.9, self::BLACKLIST);

			self::cli('cron:notifications', '-m', ...$flags);

			$this->assertUserSkipped($id, [$rcpt]);
			$this->assertAdminSkipped($id);
		}
	}

	#[TestDox('Blacklisted with -B: the recipient and the admin are notified, at any score')]
	public function testBlacklistedSentWithBigB(): void {
		$rcpt = self::address('blacklisted');
		$this->pending(9308, [$rcpt], 'reject', 999.9, self::BLACKLIST);

		self::cli('cron:notifications', '-m', '-B');

		$this->assertUserNotified(9308, [$rcpt]);
		$this->assertAdminNotified(9308);
	}

	#[TestDox('Virus in the virus skip map: nobody notified, user or admin; a virus not in it is notified')]
	public function testVirusSkipMap(): void {
		self::db()->exec("INSERT INTO maps_custom (map_name, pattern) VALUES ('rqwatch_virus_no_notify', 'Integration-Skip-*')");
		try {
			$skipped = self::address('virus');
			$this->pending(9309, [$skipped], 'discard', -0.1, self::virus('Integration-Skip-Test'));
			$notified = self::address('virus');
			$this->pending(9310, [$notified], 'discard', -0.1, self::virus('Eicar-Test-Signature'));

			self::cli('cron:notifications', '-m');

			$this->assertUserSkipped(9309, [$skipped]);
			$this->assertAdminSkipped(9309);
			$this->assertUserNotified(9310, [$notified]);
			$this->assertAdminNotified(9310);
		} finally {
			self::db()->exec("DELETE FROM maps_custom WHERE map_name = 'rqwatch_virus_no_notify' AND pattern = 'Integration-Skip-*'");
		}
	}

	/**
	 * A quarantined mail waiting for notification, in the admin feed.
	 * @param list<string> $rcpts
	 * @param array<string, array<string, mixed>> $symbols
	 */
	private function pending(int $id, array $rcpts, string $action, float $score, array $symbols = []): void {
		$this->quarantined($id, 'mx1', $rcpts, ['action' => $action, 'score' => $score, 'admin_notified' => 0], $symbols);
	}

	/** @return array<string, array<string, mixed>> an antivirus hit, as rspamd reports it */
	private static function virus(string $name): array {
		return ['CLAM_VIRUS' => ['name' => 'CLAM_VIRUS', 'score' => 0, 'group' => 'antivirus', 'options' => [$name]]];
	}

	private static function optOut(string $address): void {
		self::cli('user:add', '--no-notifications', '-p', bin2hex(random_bytes(16)), (string) strstr($address, '@', true), $address);
	}

	/**
	 * Each recipient got exactly one notification, addressed to them alone, for this mail.
	 * @param list<string> $rcpts
	 */
	private function assertUserNotified(int $id, array $rcpts): void {
		$qid = self::valueString('SELECT qid FROM mail_logs WHERE id = ?', $id);
		foreach ($rcpts as $rcpt) {
			$mails = self::mailsFor($rcpt);
			$this->assertCount(1, $mails, "notifications to {$rcpt}");
			$this->assertSame([$rcpt], self::recipientsOf($mails[0]));
			$this->assertSame('New message stored in quarantine', $mails[0]['Subject']);
			$this->assertStringContainsString("Mail Queue ID: {$qid}",
				self::messageText($mails[0]['ID']));
		}
		$state = self::state($id);
		$this->assertSame(1, $state['notified'], 'notified');
		$this->assertNotNull($state['notify_date'], 'notify_date');
	}

	/** @param list<string> $rcpts */
	private function assertUserSkipped(int $id, array $rcpts): void {
		foreach ($rcpts as $rcpt) {
			$this->assertSame([], self::mailsFor($rcpt), "notifications to {$rcpt}");
		}
		$this->assertNull(self::state($id)['notified'], 'notified (NULL = skipped)');
	}

	private function assertAdminNotified(int $id): void {
		$admin = (string) getenv('INTEGRATION_ADMIN_RCPT');
		$qid = self::valueString('SELECT qid FROM mail_logs WHERE id = ?', $id);
		$mails = self::mailsFor($admin, $qid);
		$this->assertCount(1, $mails, "admin notifications for {$qid}");
		$this->assertSame([$admin], self::recipientsOf($mails[0]));
		$this->assertSame('[Admin] New message stored in quarantine', $mails[0]['Subject']);
		$state = self::state($id);
		$this->assertSame(1, $state['admin_notified'], 'admin_notified');
		$this->assertNotNull($state['admin_notify_date'], 'admin_notify_date');
	}

	/** Skipped by the admin pass: no admin mail, admin_notified NULL with a date. */
	private function assertAdminSkipped(int $id): void {
		$qid = self::valueString('SELECT qid FROM mail_logs WHERE id = ?', $id);
		$this->assertSame([], self::mailsFor((string) getenv('INTEGRATION_ADMIN_RCPT'), $qid), "admin notifications for {$qid}");
		$state = self::state($id);
		$this->assertNull($state['admin_notified'], 'admin_notified (NULL = skipped)');
		$this->assertNotNull($state['admin_notify_date'], 'admin_notify_date (dated = skipped by the cron)');
	}

	/** @return list<string> the mail's recipient rows */
	private static function recipients(int $id): array {
		$stmt = self::db()->prepare('SELECT recipient_email FROM mail_log_recipients WHERE mail_log_id = ? ORDER BY recipient_email');
		$stmt->execute([$id]);
		/** @var list<string> */
		return $stmt->fetchAll(PDO::FETCH_COLUMN);
	}

	/** @return array<string, mixed> */
	private static function state(int $id): array {
		$stmt = self::db()->prepare('SELECT notified, notify_date, admin_notified, admin_notify_date FROM mail_logs WHERE id = ?');
		$stmt->execute([$id]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		self::assertIsArray($row, "mail_logs row {$id} not found");
		return $row;
	}
}
