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
 The linkify filter (is_safe html): escapes everything and links only
 http(s) URLs. jsJson: JSON for a <script> block, no tag can close it.
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

	/** @return array<string, array{string, string}> */
	public static function linkifyCases(): array {
		$a = fn(string $url): string => '<a href="' . $url . '" target="_blank" rel="noopener noreferrer">' . $url . '</a>';
		return [
			'plain text escaped' => ['<b>"x" & \'y\'</b>', '&lt;b&gt;&quot;x&quot; &amp; &#039;y&#039;&lt;/b&gt;'],
			'URL with a query' => ['see https://example.org/a?b=1&c=2 now', 'see ' . $a('https://example.org/a?b=1&amp;c=2') . ' now'],
			'trailing punctuation kept out' => ['(https://example.org/x).', '(' . $a('https://example.org/x') . ').'],
			'quote ends the URL' => ['"https://example.org/x"onmouseover=alert(1)', '&quot;' . $a('https://example.org/x') . '&quot;onmouseover=alert(1)'],
			'tag ends the URL' => ['https://example.org/<script>', $a('https://example.org/') . '&lt;script&gt;'],
			'scheme case-insensitive' => ['HTTP://Example.org', $a('HTTP://Example.org')],
			'other schemes not linked' => ['javascript:alert(1) ftp://example.org', 'javascript:alert(1) ftp://example.org'],
			'empty' => ['', ''],
		];
	}

	#[DataProvider('linkifyCases')]
	public function testLinkify(string $text, string $expected): void {
		$this->assertSame($expected, RqwatchExtension::linkify($text));
	}

	public function testLinkifySameTab(): void {
		$this->assertSame('<a href="https://example.org">https://example.org</a>', RqwatchExtension::linkify('https://example.org', false));
	}

	// the filter is is_safe html: apart from its own <a> tags, nothing comes out unescaped
	#[DataProvider('escapeCases')]
	public function testLinkifyOnlyItsOwnMarkupIsRaw(string $text): void {
		$out = RqwatchExtension::linkify($text . ' https://example.org/"<x>\'');
		$rest = (string) preg_replace('#<a href="[^"<>]*" target="_blank" rel="noopener noreferrer">|</a>#', '', $out);
		$this->assertDoesNotMatchRegularExpression('/[<>"\']/', $rest, $out);
	}

	public function testJsJson(): void {
		$this->assertSame('"\\u003C\\/script\\u003E\\u003Cb\\u003E"', RqwatchExtension::jsJson('</script><b>'));
		$this->assertSame('{"a":[1,"x"]}', RqwatchExtension::jsJson(['a' => [1, 'x']]));
		$this->assertSame('null', RqwatchExtension::jsJson(null));
	}

	public function testTruncate(): void {
		$this->assertSame('abc...', RqwatchExtension::truncate('abcdefgh', 6));
		$this->assertSame('12345', RqwatchExtension::truncate(12345, 10));
		$this->assertSame('', RqwatchExtension::truncate(null, 10));
		$this->assertSame('text', RqwatchExtension::truncate(new class implements \Stringable {
			#[\Override]
			public function __toString(): string {
				return 'text';
			}
		}, 10));
	}

	// a value that is not text is a template bug: an error, not "Array" with a warning
	public function testTruncateNotTextFails(): void {
		$this->expectException(\LogicException::class);
		$this->expectExceptionMessage('truncate: array is not text');
		RqwatchExtension::truncate(['a'], 10);
	}
}
