<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The Content-Type an attachment is served with. Its sender writes the
 part's own, and mailparse hands over more than a media type (markup,
 quoted text). Saved, an attachment keeps its type only when that is a
 plain type/subtype, and is bytes otherwise; opened in the browser it has
 one of the types the app lists as safe to show, or is bytes. Nothing of
 the part's header becomes a response header of its own. No text/* type
 is compared here: PHP adds a charset to those.
*/

namespace Tests\Integration\Access;

use PHPUnit\Framework\Attributes\TestDox;
use Tests\Integration\IntegrationTestCase;

final class AttachmentTypeTest extends IntegrationTestCase
{
	private const ID = 9382;

	private const BYTES = 'application/octet-stream';

	// each part's Content-Type, the type it is saved with, the type it is opened with
	private const PARTS = [
		['application/pdf', 'application/pdf', self::BYTES],
		['image/png', 'image/png', 'image/png'],
		['image/svg+xml', 'image/svg+xml', self::BYTES],
		['text/html <script>', self::BYTES, self::BYTES],
		// a quoted value, folded onto a second line
		["\"text/html\r\n X-Injected: 1\"", self::BYTES, self::BYTES],
	];

	#[TestDox('An attachment is saved with its own type only when that is a plain type/subtype, and opened only with a listed type')]
	public function testContentType(): void {
		$this->quarantined(self::ID, 'mx1');
		file_put_contents(self::quarantineFile(self::ID), self::mail());

		$alice = self::client('alice');
		foreach (self::PARTS as $n => [$given, $saved, $opened]) {
			foreach (['save' => $saved, 'open' => $opened] as $action => $type) {
				$what = "{$action} {$n} ({$given})";
				$response = $alice->get("/attach/{$action}/" . self::ID . "/{$n}");
				$this->assertSame(200, $response['status'], $what);
				$this->assertSame("ATTACHMENT-{$n}", trim($response['body']), $what);
				$this->assertSame($type, $response['headers']['content-type'] ?? '', "{$what}: Content-Type");
				$this->assertArrayNotHasKey('x-injected', $response['headers'], $what);
			}
		}
	}

	private static function mail(): string {
		$id = self::ID;
		$mail = "From: sender@example.net\r\nTo: alice@example.org\r\n"
			. "Subject: SUBJ-Q-{$id}\r\nMessage-ID: <q-{$id}@example.net>\r\n"
			. "MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"BOUNDARY\"\r\n\r\n"
			. "--BOUNDARY\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nBody of SUBJ-Q-{$id}\r\n";
		foreach (self::PARTS as $n => [$given]) {
			$mail .= "--BOUNDARY\r\nContent-Type: {$given}\r\n"
				. "Content-Disposition: attachment; filename=\"part-{$n}.bin\"\r\n\r\nATTACHMENT-{$n}\r\n";
		}
		return $mail . "--BOUNDARY--\r\n";
	}
}
