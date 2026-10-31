<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis
 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Unencoded 8-bit headers: declared charset, fallback, refused UTF-7.
*/

namespace Tests\EndToEnd\Import;

use PHPUnit\Framework\Attributes\TestDox;
use Tests\EndToEnd\EndToEndTestCase;

final class EncodingTest extends EndToEndTestCase
{
	#[TestDox('Unencoded 8-bit KOI8-R Subject and From name, charset declared: decoded with it')]
	public function testEightBitHeadersDeclared(): void {
		$this->assertEightBitHeaders('KOI8-R', 'koi8-r', 'Привет мир', 'Иван <sender@sender.test>');
	}

	#[TestDox('Unencoded 8-bit Windows-1253 Subject and From name, no charset: decoded with $mail_fallback_charset')]
	public function testEightBitHeadersFallback(): void {
		$this->assertEightBitHeaders('CP1253', '', 'Καλημέρα κόσμε', 'Γιάννης <sender@sender.test>');
	}

	#[TestDox('Declared UTF-7 charset: not used to decode 8-bit headers, no control characters injected')]
	public function testEightBitHeadersDeclaredUtf7(): void {
		// one 8-bit byte forces decoding; in UTF-7, +AA0ACg- is CR LF
		$subject = "Caf\xE9 +AA0ACg-Bcc: victim@rqwatch.test";
		[, , $row] = $this->import('clean', $subject, charset: 'utf-7');

		$this->assertSame((string) iconv('Windows-1253', 'UTF-8', $subject), $row['subject'],
			'decoded with the harness fallback (Windows-1253), not UTF-7');
	}

	/**
	 * Subject and the From display name sent unencoded in $encoding, with
	 * $charset declared on the body ('' = none; the harness sets
	 * $mail_fallback_charset to Windows-1253).
	 */
	private function assertEightBitHeaders(string $encoding, string $charset, string $subject, string $from): void {
		[, , $row] = $this->import('clean', (string) iconv('UTF-8', $encoding, $subject), charset: $charset,
			mimeFrom: (string) iconv('UTF-8', $encoding, $from), storedMimeFrom: $from);

		$this->assertSame($subject, $row['subject']);
		$headers = (string) self::query('SELECT headers FROM mail_log_data WHERE mail_log_id = ?', [(int) $row['id']])[0]['headers'];
		$this->assertStringContainsString("Subject: {$subject}", $headers);
		$this->assertStringContainsString("From: {$from}", $headers);
	}
}
