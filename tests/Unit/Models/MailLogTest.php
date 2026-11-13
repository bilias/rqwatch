<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 MailLog: mime_from_decoded is the first address mailparse finds in
 mime_from, falling back to mail_from when there is none (an empty
 mime_from, or no address in it); textAttribute() reads a text attribute,
 through its accessor, as text or null, and fails for any other type.
*/

namespace Tests\Unit\Models;

use App\Models\MailLog;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Unit\UnitTestCase;

final class MailLogTest extends UnitTestCase
{
	/** @return array<string, array{string|null, string}> */
	public static function mimeFroms(): array {
		return [
			'display name and address' => ['"Sender Name" <sender@example.net>', 'sender@example.net'],
			'bare address' => ['plain@example.net', 'plain@example.net'],
			'two addresses: the first' => ['a@example.net, b@example.net', 'a@example.net'],
			'none: mail_from' => [null, 'envelope@example.org'],
			'empty: mail_from' => ['', 'envelope@example.org'],
			'no address in it: mail_from' => ['"Only a name" <>', 'envelope@example.org'],
			'quoted-pair comma in the name: the address' => ['"\\"Last, First\\"" (via List) <list@example.net>', 'list@example.net'],
		];
	}

	#[DataProvider('mimeFroms')]
	public function testMimeFromDecoded(?string $mimeFrom, string $decoded): void {
		$log = (new MailLog())->setRawAttributes(['id' => 7, 'qid' => 'Q7', 'mail_from' => 'envelope@example.org', 'mime_from' => $mimeFrom]);
		$this->assertSame($decoded, $log->mime_from_decoded);
	}

	public function testTextAttribute(): void {
		$log = (new MailLog())->setRawAttributes(['id' => 7, 'mail_from' => 'envelope@example.org', 'ip' => null, 'size' => 1200]);
		$this->assertSame('envelope@example.org', $log->textAttribute('mail_from'));
		$this->assertNull($log->textAttribute('ip'));
		$this->assertNull($log->textAttribute('mime_to'), 'not loaded: none');
	}

	public function testTextAttributeOtherTypeFails(): void {
		$log = (new MailLog())->setRawAttributes(['id' => 7, 'size' => 1200]);
		$this->expectException(LogicException::class);
		$this->expectExceptionMessage("MailLog attribute 'size' must be text, not int");
		$log->textAttribute('size');
	}
}
