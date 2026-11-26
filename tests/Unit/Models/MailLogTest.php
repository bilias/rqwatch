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
 mime_from, or no address in it), worked out once per model; a mime_from
 mailparse warns on is logged, its control characters dropped and cut to
 120. textAttribute() reads a text attribute, through its accessor, as
 text or null, and fails for any other type.
 With the relations loaded (the unit tests have no database): the
 recipients are the mail_log_recipients addresses, lower-cased and each
 once; rcpt_to reads the same, logged as the legacy name, except on a
 report row without an id, which has only its own column. headers,
 symbols and fuzzy_hashes come from mail_log_data, none without its row,
 none and logged when the row was loaded without that column. The virus
 is the first antivirus symbol's, for a mail with has_virus.
*/

namespace Tests\Unit\Models;

use App\Models\MailLog;
use App\Models\MailLogData;
use App\Models\MailLogRecipient;
use Illuminate\Database\Eloquent\Collection;
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
			'a second @: the next address' => ['<a@b@c>, real@example.net', 'real@example.net'],
			'invalid UTF-8: the next address' => ["\xFF@example.net, real@example.net", 'real@example.net'],
		];
	}

	#[DataProvider('mimeFroms')]
	public function testMimeFromDecoded(?string $mimeFrom, string $decoded): void {
		$log = (new MailLog())->setRawAttributes(['id' => 7, 'qid' => 'Q7', 'mail_from' => 'envelope@example.org', 'mime_from' => $mimeFrom]);
		$this->assertSame($decoded, $log->mime_from_decoded);
	}

	// no mime_from is not given to mailparse: nothing to warn about
	public function testNoMimeFromNothingLogged(): void {
		foreach ([null, ''] as $mimeFrom) {
			$log = (new MailLog())->setRawAttributes(['id' => 7, 'qid' => 'Q7', 'mail_from' => 'envelope@example.org', 'mime_from' => $mimeFrom]);
			$this->assertNull($log->mimeFromAddress());
		}
		$this->assertSame([], $this->log->lines);
	}

	public function testMimeFromAddressHasNoMailFromFallback(): void {
		$log = (new MailLog())->setRawAttributes(['id' => 7, 'qid' => 'Q7', 'mail_from' => 'envelope@example.org', 'mime_from' => '"Only a name" <>']);
		$this->assertNull($log->mimeFromAddress());
		$this->assertSame('envelope@example.org', $log->mime_from_decoded);
	}

	public function testMimeFromDecodedOnce(): void {
		$log = (new MailLog())->setRawAttributes(['id' => 7, 'qid' => 'Q7', 'mail_from' => 'envelope@example.org', 'mime_from' => 'first@example.net']);
		$this->assertSame('first@example.net', $log->mime_from_decoded);
		$log->setRawAttributes(['id' => 7, 'qid' => 'Q7', 'mail_from' => 'envelope@example.org', 'mime_from' => 'second@example.net']);
		$this->assertSame('first@example.net', $log->getAttribute('mime_from_decoded'));
	}

	// trapped: not left for PHP's own handler, which would log it unattributed
	public function testMailparseWarningLogged(): void {
		error_clear_last();
		$log = (new MailLog())->setRawAttributes(['id' => 7, 'qid' => 'Q7', 'mail_from' => 'envelope@example.org', 'mime_from' => "\"unterminated\x01\x02 <a@b.example>"]);
		$this->assertSame('envelope@example.org', $log->mime_from_decoded);
		$this->assertSame(["debug MailLog 7 (qid Q7): mailparse warned on mime_from (input is not rfc822 compliant: unexpected end of header), value: '\"unterminated  <a@b.example>'"],
			$this->log->lines);
		$this->assertNull(error_get_last());
	}

	// 120 characters, not bytes
	public function testLoggedValueCut(): void {
		$long = '(' . str_repeat('ü', 200);
		$log = (new MailLog())->setRawAttributes(['id' => 7, 'qid' => 'Q7', 'mail_from' => 'envelope@example.org', 'mime_from' => $long]);
		$this->assertNull($log->mimeFromAddress());
		$this->assertCount(1, $this->log->lines);
		$this->assertStringEndsWith("value: '" . mb_substr($long, 0, 120) . "'", implode('', $this->log->lines));
	}

	public function testRecipients(): void {
		$log = (new MailLog())->setRawAttributes(['id' => 7, 'qid' => 'Q7', 'rcpt_to' => 'legacy@example.org']);
		$log->setRelation('recipients', new Collection([
			new MailLogRecipient(['recipient_email' => 'Bob@Example.org ']),
			new MailLogRecipient(['recipient_email' => 'alice@example.org']),
			new MailLogRecipient(['recipient_email' => 'bob@example.org']),
		]));
		$this->assertSame('bob@example.org, alice@example.org', $log->mail_recipients);
		$this->assertSame([], $this->log->lines);
	}

	public function testLegacyRcptToLogged(): void {
		$log = (new MailLog())->setRawAttributes(['id' => 7, 'qid' => 'Q7', 'rcpt_to' => 'legacy@example.org']);
		$log->setRelation('recipients', new Collection([new MailLogRecipient(['recipient_email' => 'bob@example.org'])]));
		$this->assertSame('bob@example.org', $log->rcpt_to);
		$this->assertSame(['error MailLog 7 (qid Q7): read rcpt_to on a keyed model; use mail_recipients'], $this->log->lines);
	}

	// a getReports() row: an alias and no id, so no mail to ask
	public function testReportRowRecipients(): void {
		$log = (new MailLog())->setRawAttributes(['rcpt_to' => 'a@example.org, b@example.org', 'mail_recipients' => 'c@example.org']);
		$this->assertSame('a@example.org, b@example.org', $log->rcpt_to);
		$this->assertSame('c@example.org', $log->mail_recipients);
		$this->assertSame('', (new MailLog())->setRawAttributes(['total' => 3])->rcpt_to);
		$this->assertSame([], $this->log->lines);
	}

	public function testMailLogData(): void {
		$log = (new MailLog())->setRawAttributes(['id' => 7, 'qid' => 'Q7']);
		$log->setRelation('mailLogData', (new MailLogData())->setRawAttributes([
			'mail_log_id' => 7,
			'headers' => "Subject: x\r\n",
			'symbols' => '[{"name":"BAYES_SPAM","score":5.1}]',
			'fuzzy_hashes' => '["abc"]',
		]));
		$this->assertSame("Subject: x\r\n", $log->headers);
		$this->assertSame([['name' => 'BAYES_SPAM', 'score' => 5.1]], $log->symbols);
		$this->assertSame(['abc'], $log->fuzzy_hashes);
		$this->assertSame([], $this->log->lines);
	}

	public function testNoMailLogDataRow(): void {
		$log = (new MailLog())->setRawAttributes(['id' => 7, 'qid' => 'Q7']);
		$log->setRelation('mailLogData', null);
		$this->assertNull($log->headers);
		$this->assertNull($log->symbols);
		$this->assertNull($log->fuzzy_hashes);
		$this->assertSame([], $this->log->lines);
	}

	// as a constrained eager load of the wrong tier would leave it
	public function testColumnNotLoadedLogged(): void {
		$log = (new MailLog())->setRawAttributes(['id' => 7, 'qid' => 'Q7']);
		$log->setRelation('mailLogData', (new MailLogData())->setRawAttributes(['mail_log_id' => 7, 'symbols' => '[]']));
		$this->assertNull($log->headers);
		$this->assertSame(["error MailLog 7 (qid Q7): 'mailLogData' was not eager-loaded, lazy-loading it to read 'headers'"], $this->log->lines);
	}

	/** @return array<string, array{bool, string, string|null}> has_virus, symbols, the virus */
	public static function viruses(): array {
		$clam = '{"name":"CLAM_VIRUS","group":"antivirus","options":["Eicar-Signature"]}';
		return [
			'has a virus: the first antivirus symbol' => [true, '[{"name":"BAYES_SPAM"},' . $clam . ',{"name":"SOPHOS","group":"antivirus","options":["Other"]}]', '[CLAM_VIRUS: Eicar-Signature]'],
			'has a virus, no antivirus symbol' => [true, '[{"name":"BAYES_SPAM"}]', null],
			'no virus: not looked for' => [false, '[' . $clam . ']', null],
		];
	}

	#[DataProvider('viruses')]
	public function testVirusFromSymbol(bool $hasVirus, string $symbols, ?string $virus): void {
		$log = (new MailLog())->setRawAttributes(['id' => 7, 'qid' => 'Q7', 'has_virus' => (int) $hasVirus]);
		$log->setRelation('mailLogData', (new MailLogData())->setRawAttributes(['mail_log_id' => 7, 'symbols' => $symbols]));
		$this->assertSame($virus, $log->virus_from_symbol);
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
