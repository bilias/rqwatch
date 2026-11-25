<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Attachment names ending in a line break, as a sender can encode them
 (RFC 2231 and an encoded-word): saved and opened, each attachment comes
 with its Content-Disposition on one line. A line break taken into the
 ASCII fallback's extension makes HeaderUtils::makeDisposition() throw
 (Symfony 7.4.23; older versions put it in the header): no download.
*/

namespace Tests\Integration\Access;

use PHPUnit\Framework\Attributes\TestDox;
use Tests\Integration\IntegrationTestCase;

final class AttachmentNameTest extends IntegrationTestCase
{
	private const ID = 9381;

	#[TestDox('Attachment names ending in a line break: saved and opened with a one-line Content-Disposition')]
	public function testNameEndingInLineBreak(): void {
		$this->quarantined(self::ID, 'mx1');
		file_put_contents(self::quarantineFile(self::ID), self::mail());

		$alice = self::client('alice');
		foreach (['save' => 'attachment', 'open' => 'inline'] as $action => $disposition) {
			foreach ([0, 1] as $n) {
				$what = "{$action} {$n}";
				$response = $alice->get("/attach/{$action}/" . self::ID . "/{$n}");
				$this->assertSame(200, $response['status'], $what);
				$this->assertSame("ATTACHMENT-{$n}", trim($response['body']), $what);
				$this->assertMatchesRegularExpression(
					"/^{$disposition}; filename=[0-9a-f]{16}(\\.[A-Za-z0-9]{1,10})?; filename\\*=utf-8''\\S+\$/D",
					$response['headers']['content-disposition'] ?? '',
					"{$what}: Content-Disposition"
				);
			}
		}
	}

	private static function mail(): string {
		$id = self::ID;
		return "From: sender@example.net\r\nTo: alice@example.org\r\n"
			. "Subject: SUBJ-Q-{$id}\r\nMessage-ID: <q-{$id}@example.net>\r\n"
			. "MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"BOUNDARY\"\r\n\r\n"
			. "--BOUNDARY\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nBody of SUBJ-Q-{$id}\r\n"
			// RFC 2231: x.pdf and a line feed
			. "--BOUNDARY\r\nContent-Type: application/pdf\r\n"
			. "Content-Disposition: attachment; filename*=utf-8''x.pdf%0A\r\n\r\nATTACHMENT-0\r\n"
			// encoded-word: the same
			. "--BOUNDARY\r\nContent-Type: application/pdf\r\n"
			. "Content-Disposition: attachment; filename=\"=?utf-8?Q?x.pdf=0A?=\"\r\n\r\nATTACHMENT-1\r\n"
			. "--BOUNDARY--\r\n";
	}
}
