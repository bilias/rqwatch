<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The mark_homoglyphs filter (is_safe html, on the subject and From in the
 mail detail): escapes everything, marks non-Latin letters in words that
 mix scripts and every invisible format char (\p{Cf}), letter or not.
*/

namespace Tests\Unit\Twig;

use App\Twig\RqwatchExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Unit\UnitTestCase;

final class RqwatchExtensionTest extends UnitTestCase
{
	/** @return array<string, array{string|null, string}> */
	public static function homoglyphCases(): array {
		return [
			'plain Latin' => ['paypal', 'paypal'],
			'escaped' => ['<b>"x" & \'y\'</b>', '&lt;b&gt;&quot;x&quot; &amp; &#039;y&#039;&lt;/b&gt;'],
			'Cyrillic а in a Latin word' => ["p\u{0430}ypal", "p<mark class=\"homoglyph\" title=\"U+0430\">\u{0430}</mark>ypal"],
			'Cyrillic А first, then a tag' => ["\u{0410}pple <script>", "<mark class=\"homoglyph\" title=\"U+0410\">\u{0410}</mark>pple &lt;script&gt;"],
			'pure Cyrillic' => ['привет мир', 'привет мир'],
			'pure Greek' => ['αβγ', 'αβγ'],
			'Latin with an accent' => ['café', 'café'],
			'Latin with a combining mark' => ["cafe\u{0301}", "cafe\u{0301}"],
			'zero-width space in a word' => ["pay\u{200B}pal", 'pay<mark class="homoglyph" title="invisible U+200B">·</mark>pal'],
			'soft hyphen in a word' => ["a\u{00AD}b", 'a<mark class="homoglyph" title="invisible U+00AD">·</mark>b'],
			'RLO before letters' => ["\u{202E}gnp.exe", '<mark class="homoglyph" title="invisible U+202E">·</mark>gnp.exe'],
			// these three passed through unmarked before the fix
			'RLO on its own' => ["\u{202E} gnp.exe", '<mark class="homoglyph" title="invisible U+202E">·</mark> gnp.exe'],
			'zero-width space on its own' => ["a \u{200B} b", 'a <mark class="homoglyph" title="invisible U+200B">·</mark> b'],
			'isolate and RLO at the end' => ["invoice \u{2066}\u{202E}", 'invoice <mark class="homoglyph" title="invisible U+2066">·</mark><mark class="homoglyph" title="invisible U+202E">·</mark>'],
			'Cyrillic word with a format char' => ["привет\u{200B}", '<mark class="homoglyph" title="U+043F">п</mark><mark class="homoglyph" title="U+0440">р</mark><mark class="homoglyph" title="U+0438">и</mark><mark class="homoglyph" title="U+0432">в</mark><mark class="homoglyph" title="U+0435">е</mark><mark class="homoglyph" title="U+0442">т</mark><mark class="homoglyph" title="invisible U+200B">·</mark>'],
			'invalid UTF-8' => ["bad\xC3(", "bad\u{FFFD}("],
			'empty' => ['', ''],
			'null' => [null, ''],
		];
	}

	#[DataProvider('homoglyphCases')]
	public function testMarkHomoglyphs(?string $text, string $expected): void {
		$this->assertSame($expected, RqwatchExtension::markHomoglyphs($text));
	}

	/** @return array<string, array{string}> */
	public static function escapeCases(): array {
		return [
			'tags and quotes' => ['<img src=x onerror="alert(1)">\'&'],
			'markup around a homoglyph' => ["<a href='x'>p\u{0430}y</a>"],
			'markup around a format char' => ["\"\u{202E}\" <i>\u{200B}</i>"],
			'invalid UTF-8 and markup' => ["<\xC3>\"\xFF'"],
		];
	}

	// the filter is is_safe html: apart from its own <mark>, nothing comes out unescaped
	#[DataProvider('escapeCases')]
	public function testOnlyItsOwnMarkupIsRaw(string $text): void {
		$out = RqwatchExtension::markHomoglyphs($text);
		$rest = (string) preg_replace('#<mark class="homoglyph" title="(invisible )?U\+[0-9A-F]{4,6}">|</mark>#', '', $out);
		$this->assertDoesNotMatchRegularExpression('/[<>"\']/', $rest, $out);
	}
}
