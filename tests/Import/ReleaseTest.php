<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis
 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Release through release_mail.php, as a web node calls it, delivered to
 Mailpit. The recipient check of a web release is in the web controller
 (tests/Scoping); the API trusts its caller.
*/

namespace Tests\Import;

use PHPUnit\Framework\Attributes\TestDox;
use RuntimeException;

final class ReleaseTest extends ImportTestCase
{
	#[TestDox('Release to the original recipient: one delivery, the quarantined mail attached byte for byte, released set')]
	public function testReleaseToOriginalRecipient(): void {
		$rcpt = self::address('release');
		[, , $row] = $this->import('spam', rcpts: [$rcpt], to: [$rcpt]);
		$this->assertQuarantined($row);

		$this->assertSame([200, 'Message Released'], self::releaseApi((int) $row['id'], [$rcpt]));

		$mails = self::mailsFor($rcpt);
		$this->assertCount(1, $mails, 'deliveries');
		$this->assertSame([$rcpt], self::recipientsOf($mails[0]));
		$this->assertSame($this->sent['mail'], self::attachedMail((string) $mails[0]['ID']), 'attached mail differs from the quarantined one');
		$this->assertReleased((int) $row['id'], true);
	}

	#[TestDox('Release to the original and an alternate recipient: both receive it')]
	public function testReleaseToAlternateRecipientToo(): void {
		$rcpt = self::address('release');
		$alternate = self::address('alternate');
		[, , $row] = $this->import('spam', rcpts: [$rcpt], to: [$rcpt]);

		$this->assertSame([200, 'Message Released'], self::releaseApi((int) $row['id'], [$rcpt, $alternate]));

		$expected = [$alternate, $rcpt];
		sort($expected);
		$mails = self::mailsFor($alternate);
		$this->assertCount(1, $mails, 'deliveries to the alternate');
		$this->assertSame($expected, self::recipientsOf($mails[0]));
		$this->assertCount(1, self::mailsFor($rcpt), 'deliveries to the original recipient');
		$this->assertSame($this->sent['mail'], self::attachedMail((string) $mails[0]['ID']));
		$this->assertReleased((int) $row['id'], true);
	}

	#[TestDox('Release to an invalid address: refused (400), nothing sent')]
	public function testReleaseToInvalidAddressIsRefused(): void {
		$rcpt = self::address('release');
		[, , $row] = $this->import('spam', rcpts: [$rcpt], to: [$rcpt]);
		$count = self::mailCount();

		$this->assertSame([400, 'Invalid destination email'], self::releaseApi((int) $row['id'], [$rcpt, 'not-an-address']));
		$this->assertSame($count, self::mailCount(), 'mail sent');
		$this->assertReleased((int) $row['id'], false);
	}

	#[TestDox('Release of a mail that does not exist: refused (400), nothing sent')]
	public function testReleaseOfMissingMailIsRefused(): void {
		$count = self::mailCount();
		$id = (int) self::query('SELECT COALESCE(MAX(id), 0) + 1000 AS id FROM mail_logs', [])[0]['id'];

		$this->assertSame([400, 'Message not found'], self::releaseApi($id, [self::address('release')]));
		$this->assertSame($count, self::mailCount(), 'mail sent');
	}

	#[TestDox('Release of a mail not in quarantine: refused (500), nothing sent')]
	public function testReleaseOfUnstoredMailIsRefused(): void {
		$rcpt = self::address('release');
		[, , $row] = $this->import('clean', rcpts: [$rcpt], to: [$rcpt]);
		$this->assertNotQuarantined($row);
		$count = self::mailCount();

		$this->assertSame([500, 'Message Release Failed'], self::releaseApi((int) $row['id'], [$rcpt]));
		$this->assertSame($count, self::mailCount(), 'mail sent');
		$this->assertReleased((int) $row['id'], false);
	}

	private function assertReleased(int $id, bool $released): void {
		$row = self::query('SELECT released, release_date FROM mail_logs WHERE id = ?', [$id])[0];
		$this->assertSame($released ? 1 : 0, (int) $row['released'], 'released');
		if ($released) {
			$this->assertNotNull($row['release_date'], 'release_date');
		} else {
			$this->assertNull($row['release_date'], 'release_date');
		}
	}

	/**
	 * Calls this instance's release API as a web node would.
	 * @param list<string> $emails
	 * @return array{0: int, 1: string} status and body
	 */
	private static function releaseApi(int $id, array $emails): array {
		$ch = curl_init(rtrim((string) getenv('IMPORT_WEB_URL'), '/') . '/api/release_mail.php');
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POSTFIELDS => http_build_query(['remote_user' => 'import-test', 'id' => (string) $id, 'email' => $emails]),
			CURLOPT_USERPWD => getenv('IMPORT_MAIL_API_USER') . ':' . getenv('IMPORT_MAIL_API_PASS'),
			CURLOPT_TIMEOUT => 30,
		]);
		$body = curl_exec($ch);
		if (!is_string($body)) {
			throw new RuntimeException('release API: ' . curl_error($ch));
		}
		return [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $body];
	}

	/** The raw bytes of the one message/rfc822 attachment of a captured message. */
	private static function attachedMail(string $id): string {
		$parts = array_values(array_filter(
			self::mailpit("/api/v1/message/{$id}")['Attachments'] ?? [],
			fn($part) => $part['ContentType'] === 'message/rfc822'
		));
		if (count($parts) !== 1) {
			throw new RuntimeException("message {$id}: expected one message/rfc822 attachment, found " . count($parts));
		}
		$ch = curl_init(rtrim((string) getenv('IMPORT_MAILPIT_URL'), '/') . "/api/v1/message/{$id}/part/{$parts[0]['PartID']}");
		curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
		$body = curl_exec($ch);
		if (!is_string($body) || (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE) !== 200) {
			throw new RuntimeException("message {$id}: attachment not readable");
		}
		return $body;
	}
}
