<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 MailObject's HTML body: cid: references to the mail's own image parts
 become data: URIs, whether quoted, unquoted, URL-encoded or in CSS, so
 embedded images show in the preview. Unknown references, non-image parts,
 SVG and images past the per-mail budget are left as cid:. <link> and
 <meta> are renamed to inert elements (resource hints escape the CSP).
 The text body is escaped with its line breaks kept; the attachments are
 listed by name and type, in order, and each becomes a MailAttachment with
 its content and size. The rest is what the detail page passed in, and the
 mail is read from a file as from text.
*/

namespace Tests\Unit\Inventory;

use App\Inventory\MailAttachment;
use App\Inventory\MailObject;
use App\Models\MailLog;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Unit\UnitTestCase;

final class MailObjectTest extends UnitTestCase
{
	// a 1x1 PNG header is enough: the content is only base64-encoded
	private const PNG = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR";

	#[TestDox('cid: references to image parts become data: URIs, quoted, unquoted, URL-encoded or in CSS')]
	public function testEmbedsImageParts(): void {
		$html = self::html([
			'<img src="cid:a@x">',
			"<img src='cid:b%40x'>",
			'<img src=cid:a@x alt="">',
			'<td style="background: url(cid:b@x)">',
		], [
			['a@x', 'image/png', self::PNG],
			['b@x', 'image/jpeg', self::PNG],
		]);
		$png = 'data:image/png;base64,' . base64_encode(self::PNG);
		$jpeg = 'data:image/jpeg;base64,' . base64_encode(self::PNG);
		$this->assertStringContainsString("<img src=\"{$png}\">", $html);
		$this->assertStringContainsString("<img src='{$jpeg}'>", $html);
		$this->assertStringContainsString("<img src={$png} alt=\"\">", $html);
		$this->assertStringContainsString("url({$jpeg})", $html);
		$this->assertStringNotContainsString('cid:', $html);
	}

	#[TestDox('Unknown references, non-image parts and SVG stay cid:')]
	public function testLeavesOthers(): void {
		$html = self::html([
			'<img src="cid:missing@x">',
			'<img src="cid:page@x">',
			'<img src="cid:svg@x">',
		], [
			['page@x', 'text/html', '<script>alert(1)</script>'],
			['svg@x', 'image/svg+xml', '<svg xmlns="http://www.w3.org/2000/svg"/>'],
		]);
		$this->assertStringContainsString('cid:missing@x', $html);
		$this->assertStringContainsString('cid:page@x', $html);
		$this->assertStringContainsString('cid:svg@x', $html);
		$this->assertStringNotContainsString('data:', $html);
	}

	#[TestDox('Images past the per-mail budget stay cid:, the ones before it are embedded')]
	public function testBudget(): void {
		$html = self::html([
			'<img src="cid:small@x">',
			'<img src="cid:big@x">',
		], [
			['small@x', 'image/png', self::PNG],
			['big@x', 'image/png', str_repeat('x', 5 * 1024 * 1024)],
		]);
		$this->assertStringContainsString('data:image/png;base64,' . base64_encode(self::PNG), $html);
		$this->assertStringContainsString('cid:big@x', $html);
	}

	#[TestDox('An image past the budget does not stop the ones after it')]
	public function testBudgetSkipsOnlyThePastOne(): void {
		$html = self::html(['<img src="cid:big@x">', '<img src="cid:small@x">'], [
			['big@x', 'image/png', str_repeat('x', 5 * 1024 * 1024 + 1)],
			['small@x', 'image/png', self::PNG],
		]);
		$this->assertStringContainsString('cid:big@x', $html);
		$this->assertStringContainsString('data:image/png;base64,' . base64_encode(self::PNG), $html);
	}

	#[TestDox('The budget is what is left after each embedded image; an image of exactly the budget fits')]
	public function testBudgetSpentPerImage(): void {
		$second = str_repeat('x', 100);
		$html = self::html(['<img src="cid:a@x">', '<img src="cid:b@x">'], [
			['a@x', 'image/png', self::PNG],
			['b@x', 'image/png', $second],
		]);
		$this->assertStringContainsString('data:image/png;base64,' . base64_encode($second), $html);

		$html = self::html(['<img src="cid:full@x">'], [['full@x', 'image/png', str_repeat('x', 5 * 1024 * 1024)]]);
		$this->assertStringNotContainsString('cid:full@x', $html);
	}

	#[TestDox('Of two parts with one Content-ID the first is embedded')]
	public function testRepeatedContentId(): void {
		$html = self::html(['<img src="cid:a@x">'], [
			['a@x', 'image/png', self::PNG],
			['a@x', 'image/png', 'second'],
		]);
		$this->assertStringContainsString('data:image/png;base64,' . base64_encode(self::PNG), $html);
		$this->assertStringNotContainsString(base64_encode('second'), $html);
	}

	#[TestDox('An image part without Content-ID is not embedded, not even for cid:0')]
	public function testNoContentId(): void {
		$html = self::html([
			'<img src="cid:0">',
			'<img src="cid:a@x">',
		], [
			['', 'image/png', self::PNG],
			['a@x', 'image/png', self::PNG],
		]);
		$this->assertStringContainsString('<img src="cid:0">', $html);
		$this->assertStringContainsString('data:image/png;base64,' . base64_encode(self::PNG), $html);
	}

	#[TestDox('<link> and <meta> become inert elements in any case and layout; look-alikes and text stay')]
	public function testInertLinkMeta(): void {
		$cases = [
			'<link rel="preconnect" href="https://t.example">' => '<x-rqwatch-link rel="preconnect" href="https://t.example">',
			"<LINK\nrel=dns-prefetch href=//t.example>" => "<x-rqwatch-LINK\nrel=dns-prefetch href=//t.example>",
			"<link\trel=prefetch href=//t.example>" => "<x-rqwatch-link\trel=prefetch href=//t.example>",
			'<link/>' => '<x-rqwatch-link/>',
			'<link>' => '<x-rqwatch-link>',
			'<Meta http-equiv="x-dns-prefetch-control" content="on">' => '<x-rqwatch-Meta http-equiv="x-dns-prefetch-control" content="on">',
			'<links><linker><metadata>' => '<links><linker><metadata>',
			'< link> and 1<link' => '< link> and 1<link',
		];
		foreach ($cases as $in => $out) {
			$this->assertSame($out, MailObject::inertLinkMeta($in), $in);
		}

		// and on the parsed mail's HTML body
		$html = self::html(['<link rel="preconnect" href="https://t.example">'], []);
		$this->assertStringNotContainsString('<link', $html);
		$this->assertStringContainsString('<x-rqwatch-link rel="preconnect"', $html);
	}

	public function testWhatTheDetailPagePassed(): void {
		$log = (new MailLog())->setRawAttributes(['mail_stored' => 1, 'mail_location' => '/q/2026-11-26/ABC123/mail.eml']);
		$object = new MailObject(['log' => $log, 'symbols' => ['CLAM_VIRUS', 'BAYES_SPAM'], 'virus_found' => 'Eicar-Signature', 'received' => null]);
		$this->assertTrue($object->isMailStored());
		$this->assertSame('/q/2026-11-26/ABC123/mail.eml', $object->getMailLocation());
		$this->assertSame($log, $object->getMailLog());
		$this->assertSame(['CLAM_VIRUS', 'BAYES_SPAM'], $object->getSymbols());
		$this->assertSame('Eicar-Signature', $object->getVirusFound());

		$object = new MailObject(['log' => (new MailLog())->setRawAttributes(['mail_stored' => 0]), 'symbols' => [], 'virus_found' => null, 'received' => null]);
		$this->assertFalse($object->isMailStored());
		$this->assertNull($object->getMailLocation());
		$this->assertNull($object->getVirusFound());
	}

	public function testTextBodyAndAttachments(): void {
		$object = self::object();
		$object->setText(self::mixedMail());
		$object->setMessageBody();
		$this->assertSame("&lt;b&gt;&amp; hi&lt;/b&gt;<br />\r\nsecond line", rtrim($object->getTextBody()));

		$this->assertSame([], $object->getAttached(), 'listed only once asked');
		$object->setAttached();
		$this->assertSame([
			['filename' => 'report.pdf', 'filetype' => 'application/pdf'],
			['filename' => 'notes.txt', 'filetype' => 'text/plain'],
		], $object->getAttached());

		$attachments = $object->getAttachments();
		$this->assertCount(2, $attachments);
		$notes = new MailAttachment($attachments[1]);
		$this->assertSame('notes.txt', $notes->getFilename());
		$this->assertSame('text/plain', $notes->getFileType());
		$this->assertSame('remember the milk', $notes->getContent());
		$this->assertSame(17, $notes->getSize());
	}

	public function testOneAttachment(): void {
		$object = self::object();
		$object->setText("From: a@example.net\r\nSubject: x\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"M\"\r\n\r\n"
			. "--M\r\nContent-Type: text/plain\r\n\r\nsee attached\r\n"
			. "--M\r\nContent-Type: text/csv\r\nContent-Disposition: attachment; filename=\"list.csv\"\r\n\r\na,b\r\n--M--\r\n");
		$object->setAttached();
		$this->assertSame([['filename' => 'list.csv', 'filetype' => 'text/csv']], $object->getAttached());
	}

	public function testNoAttachments(): void {
		$object = self::object();
		$object->setText("From: a@example.net\r\nSubject: x\r\n\r\nplain\r\n");
		$object->setAttached();
		$this->assertSame([], $object->getAttached());
		$this->assertSame([], $object->getAttachments());
	}

	public function testFromFile(): void {
		$file = tempnam(sys_get_temp_dir(), 'rqwatch-mail-');
		$this->assertIsString($file);
		try {
			file_put_contents($file, self::mixedMail());
			$object = self::object();
			$object->setPath($file);
			$object->setAttached();
			$this->assertSame(['report.pdf', 'notes.txt'], array_column($object->getAttached(), 'filename'));
		} finally {
			unlink($file);
		}
	}

	private static function object(): MailObject {
		return new MailObject(['log' => new MailLog(['mail_stored' => true]), 'symbols' => [], 'virus_found' => null, 'received' => null]);
	}

	// a text part and two attachments
	private static function mixedMail(): string {
		return "From: sender@example.net\r\nTo: alice@example.org\r\nSubject: files\r\n"
			. "MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"M\"\r\n\r\n"
			. "--M\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n<b>& hi</b>\r\nsecond line\r\n"
			. "--M\r\nContent-Type: application/pdf\r\nContent-Disposition: attachment; filename=\"report.pdf\"\r\n"
			. "Content-Transfer-Encoding: base64\r\n\r\n" . base64_encode('%PDF-1.4') . "\r\n"
			. "--M\r\nContent-Type: text/plain\r\nContent-Disposition: attachment; filename=\"notes.txt\"\r\n\r\n"
			. "remember the milk\r\n--M--\r\n";
	}

	/**
	 * The HTML body MailObject makes of a multipart/related mail with $lines
	 * as its HTML part and $parts (Content-ID, type, content) as base64 parts;
	 * an empty Content-ID leaves the header out.
	 * @param list<string> $lines
	 * @param list<array{0: string, 1: string, 2: string}> $parts
	 */
	private static function html(array $lines, array $parts): string {
		$mail = "From: sender@example.net\r\nTo: alice@example.org\r\nSubject: cid\r\n"
			. "MIME-Version: 1.0\r\nContent-Type: multipart/related; boundary=\"B\"\r\n\r\n"
			. "--B\r\nContent-Type: text/html; charset=utf-8\r\n\r\n"
			. '<html><body>' . implode("\r\n", $lines) . "</body></html>\r\n";
		foreach ($parts as [$cid, $type, $content]) {
			$mail .= "--B\r\nContent-Type: {$type}\r\nContent-Transfer-Encoding: base64\r\n"
				. ($cid !== '' ? "Content-ID: <{$cid}>\r\n" : '') . "Content-Disposition: inline\r\n\r\n"
				. chunk_split(base64_encode($content)) . "\r\n";
		}
		$mail .= "--B--\r\n";

		$object = new MailObject(['log' => new MailLog(['mail_stored' => true]), 'symbols' => [], 'virus_found' => null, 'received' => null]);
		$object->setText($mail);
		$object->setMessageBody();
		return $object->getHtmlBody();
	}
}
