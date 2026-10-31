<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Unencoded 8-bit text to UTF-8: which declared charsets are trusted,
 the configured fallback, and the HTML <meta> charset.
*/

namespace Tests\Unit\Helpers;

use App\Configuration\Config;
use App\Utils\Helper;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Unit\UnitTestCase;

final class CharsetTest extends UnitTestCase
{
	public static function charsetCases(): array {
		return [
			'KOI8-R' => ['koi8-r', 'koi8-r'],
			'Windows-1253' => ['windows-1253', 'windows-1253'],
			'alias cp1253' => ['cp1253', 'windows-1253'],
			'ISO-8859-7' => ['ISO-8859-7', 'iso-8859-7'],
			'alias latin1' => ['latin1', 'iso-8859-1'],
			'alias ks_c_5601-1987' => ['ks_c_5601-1987', 'euc-kr'],
			'padded' => [' koi8-r ', 'koi8-r'],
			'UTF-8 needs no decoding' => ['UTF-8', null],
			'US-ASCII needs no decoding' => ['us-ascii', null],
			'UTF-7' => ['utf-7', null],
			'UTF-7 alias' => ['unicode-1-1-utf-7', null],
			'IMAP UTF-7' => ['x-imap4-modified-utf7', null],
			'UTF-16' => ['utf-16', null],
			'UTF-16LE' => ['UTF-16LE', null],
			'ISO-2022-JP' => ['iso-2022-jp', null],
			'HZ' => ['hz-gb-2312', null],
			'unknown name' => ['bogus-charset', null],
			'empty' => ['', null],
		];
	}

	#[DataProvider('charsetCases')]
	public function testMailCharset(string $declared, ?string $expected): void {
		$this->assertSame($expected, Helper::mailCharset($declared));
	}

	public function testValidUtf8Unchanged(): void {
		$this->assertSame('Γειά σου', Helper::toUtf8('Γειά σου', ['koi8-r']));
		$this->assertSame('', Helper::toUtf8(''));
	}

	public function testDeclaredCharsetFirst(): void {
		$koi8 = iconv('UTF-8', 'KOI8-R', 'Привет');
		Config::set('mail_fallback_charset', 'Windows-1253');
		$this->assertSame('Привет', Helper::toUtf8($koi8, ['koi8-r']));
	}

	public function testConfiguredFallback(): void {
		$cp1253 = iconv('UTF-8', 'Windows-1253', 'Γειά');
		Config::set('mail_fallback_charset', 'Windows-1253');
		$this->assertSame('Γειά', Helper::toUtf8($cp1253));
	}

	public function testDefaultFallbackIsWindows1252(): void {
		$this->assertSame('Café', Helper::toUtf8(iconv('UTF-8', 'Windows-1252', 'Café')));
	}

	public function testUnusableCharsetsEndInScrub(): void {
		Config::set('mail_fallback_charset', 'no-such-charset');
		$this->assertSame('ab?', Helper::toUtf8("ab\xFF", ['also-no-such-charset']));
	}

	public function testUtf7DeclarationCannotInjectLineBreaks(): void {
		// as UTF-7, "+AA0ACg-" is CR LF; the 8-bit byte forces a decode
		$subject = "Hi \xE9 +AA0ACg-Bcc: x@example.org";
		$charsets = array_values(array_filter([Helper::mailCharset('utf-7')]));
		$out = Helper::toUtf8($subject, $charsets);
		$this->assertTrue(mb_check_encoding($out, 'UTF-8'));
		$this->assertStringNotContainsString("\n", $out);
		$this->assertStringNotContainsString("\r", $out);
		$this->assertStringContainsString('+AA0ACg-', $out);
	}

	public function testNormalizeNullAndEmpty(): void {
		$this->assertSame('', Helper::normalizeToUtf8(null));
		$this->assertSame('', Helper::normalizeToUtf8(''));
	}

	public function testNormalizeUsesMetaCharset(): void {
		$html = '<html><head><meta charset="koi8-r"></head><body>' . iconv('UTF-8', 'KOI8-R', 'Привет') . '</body></html>';
		$this->assertStringContainsString('<body>Привет</body>', Helper::normalizeToUtf8($html));
	}

	public function testNormalizeUsesHttpEquivCharset(): void {
		$html = '<meta http-equiv="Content-Type" content="text/html; charset=windows-1253">'
			. iconv('UTF-8', 'Windows-1253', 'Γειά');
		$this->assertStringEndsWith('Γειά', Helper::normalizeToUtf8($html));
	}

	public function testNormalizeIgnoresUtf7Meta(): void {
		$html = "<meta charset=\"utf-7\">\xE9 +AA0ACg-x";
		$this->assertSame('<meta charset="utf-7">é +AA0ACg-x', Helper::normalizeToUtf8($html));
	}
}
