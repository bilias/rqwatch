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
*/

namespace Tests\Unit\Inventory;

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

	/**
	 * The HTML body MailObject makes of a multipart/related mail with $lines
	 * as its HTML part and $parts (Content-ID, type, content) as base64 parts.
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
				. "Content-ID: <{$cid}>\r\nContent-Disposition: inline\r\n\r\n"
				. chunk_split(base64_encode($content)) . "\r\n";
		}
		$mail .= "--B--\r\n";

		$object = new MailObject(['log' => new MailLog(['mail_stored' => true]), 'symbols' => [], 'virus_found' => null, 'received' => null]);
		$object->setText($mail);
		$object->setMessageBody();
		return $object->getHtmlBody();
	}
}
