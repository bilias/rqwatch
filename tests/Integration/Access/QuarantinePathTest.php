<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 A mail_location outside QUARANTINE_DIR is never read, whether the row names
 another file, climbs out with ../ or goes through a symlink inside the
 quarantine: get_mail.php refuses it and the mail page does not render it.
 The outside rows point at the copy's .env, so its MAIL_API_PASS must never
 appear. A row of the same shape with its file inside the quarantine is the
 positive control. Rows and files are created and removed per test.
*/

namespace Tests\Integration\Access;

use PHPUnit\Framework\Attributes\TestDox;
use RuntimeException;
use Tests\Integration\IntegrationTestCase;

final class QuarantinePathTest extends IntegrationTestCase
{
	private const INSIDE = 9001;
	private const OUTSIDE = [9002 => 'another file', 9003 => '../', 9004 => 'a symlink inside the quarantine'];
	private const INSIDE_BODY = 'Body of SUBJ-INSIDE-9001';

	protected function setUp(): void {
		parent::setUp();
		$root = (string) getenv('INTEGRATION_ROOT');
		file_put_contents("{$root}/q/9001.eml", "From: sender@example.net\r\nTo: admin@example.org\r\n"
			. "Subject: SUBJ-INSIDE-9001\r\nMessage-ID: <inside-9001@example.net>\r\n\r\n" . self::INSIDE_BODY . "\r\n");
		symlink("{$root}/.env", "{$root}/q/9004.eml");
		$locations = [
			9001 => "{$root}/q/9001.eml",
			9002 => "{$root}/.env",
			9003 => "{$root}/q/../.env",
			9004 => "{$root}/q/9004.eml",
		];
		$log = self::db()->prepare("INSERT INTO mail_logs (id, qid, server, subject, score, action, ip, mail_from, mime_from, rcpt_to, mime_to, size, mail_stored, mail_location)
			VALUES (?, ?, 'mx1', ?, 15, 'reject', '192.0.2.20', 'sender@example.net', 'sender@example.net', 'admin@example.org', 'admin@example.org', 1000, 1, ?)");
		$rcpt = self::db()->prepare("INSERT INTO mail_log_recipients (mail_log_id, recipient_email) VALUES (?, 'admin@example.org')");
		$data = self::db()->prepare("INSERT INTO mail_log_data (mail_log_id, headers, symbols) VALUES (?, ?, '{\"TEST_SYMBOL\": {\"name\": \"TEST_SYMBOL\", \"score\": 15}}')");
		foreach ($locations as $id => $location) {
			$log->execute([$id, "QIDPATH{$id}", "SUBJ-PATH-{$id}", $location]);
			$rcpt->execute([$id]);
			$data->execute([$id, "Subject: SUBJ-PATH-{$id}"]);
		}
	}

	protected function tearDown(): void {
		if (getenv('INTEGRATION_BASE_URL') !== false) {
			$ids = implode(',', [self::INSIDE, ...array_keys(self::OUTSIDE)]);
			self::db()->exec("DELETE FROM mail_log_data WHERE mail_log_id IN ({$ids})");
			self::db()->exec("DELETE FROM mail_log_recipients WHERE mail_log_id IN ({$ids})");
			self::db()->exec("DELETE FROM mail_logs WHERE id IN ({$ids})");
			$root = (string) getenv('INTEGRATION_ROOT');
			foreach (["{$root}/q/9001.eml", "{$root}/q/9004.eml"] as $file) {
				if (is_link($file) || is_file($file)) {
					unlink($file);
				}
			}
		}
		parent::tearDown();
	}

	#[TestDox('get_mail.php: a file under QUARANTINE_DIR is served byte for byte; outside it (another file, ../, a symlink): 400, nothing served')]
	public function testGetMail(): void {
		$root = (string) getenv('INTEGRATION_ROOT');
		$this->assertSame([200, (string) file_get_contents("{$root}/q/9001.eml")], self::getMail(self::INSIDE), 'positive control');

		foreach (self::OUTSIDE as $id => $how) {
			[$status, $body] = self::getMail($id);
			$this->assertSame([400, 'File not found'], [$status, $body], $how);
			$this->assertStringNotContainsString(self::secret(), $body, $how);
		}
	}

	#[TestDox('Mail page: a file under QUARANTINE_DIR renders; outside it (another file, ../, a symlink): not rendered, back to the homepage')]
	public function testMailPage(): void {
		$admin = self::client('admin');
		$inside = $admin->get('/admin/mail/' . self::INSIDE);
		$this->assertSame(200, $inside['status'], 'positive control');
		$this->assertStringContainsString(self::INSIDE_BODY, $inside['body'], 'positive control');

		foreach (self::OUTSIDE as $id => $how) {
			$page = $admin->get("/admin/mail/{$id}");
			$this->assertSame(302, $page['status'], $how);
			$this->assertStringNotContainsString(self::secret(), $page['body'], $how);
		}
	}

	private static function secret(): string {
		$secret = (string) getenv('INTEGRATION_MAIL_API_PASS');
		if ($secret === '') {
			throw new RuntimeException('INTEGRATION_MAIL_API_PASS is not set');
		}
		return $secret;
	}

	/** @return array{0: int, 1: string} status and body */
	private static function getMail(int $id): array {
		$ch = curl_init(rtrim((string) getenv('INTEGRATION_API_URL'), '/') . '/api/get_mail.php');
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POSTFIELDS => http_build_query(['id' => (string) $id, 'remote_user' => 'integration-test']),
			CURLOPT_USERPWD => getenv('INTEGRATION_MAIL_API_USER') . ':' . self::secret(),
			CURLOPT_TIMEOUT => 30,
		]);
		$body = curl_exec($ch);
		if (!is_string($body)) {
			throw new RuntimeException('get_mail.php: ' . curl_error($ch));
		}
		return [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $body];
	}
}
