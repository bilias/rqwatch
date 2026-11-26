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
 A format char's dot comes from CSS, so the copied text has no dot.
 The linkify filter (is_safe html): escapes everything and links only
 http(s) URLs, and only escapes when PCRE fails. jsJson: JSON for a
 <script> block, no tag can close it. Through Twig: the functions and
 filters registered, route() by RouteName case and get_route() by route
 name, the logout token, fuzzy hits labelled from fuzzy_learn_flags read
 once per extension, nf in the Greek format. The URL generator and CSRF
 manager are fetched only when a template uses them.
*/

namespace Tests\Unit\Twig;

use App\Configuration\Config;
use App\Core\Routing\Routes;
use App\Twig\RqwatchExtension;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Tests\Unit\UnitTestCase;
use Twig\Environment;
use Twig\Error\RuntimeError;
use Twig\Loader\ArrayLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

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
			'zero-width space in a word' => ["pay\u{200B}pal", 'pay<mark class="homoglyph invisible" title="invisible U+200B"></mark>pal'],
			'soft hyphen in a word' => ["a\u{00AD}b", 'a<mark class="homoglyph invisible" title="invisible U+00AD"></mark>b'],
			'RLO before letters' => ["\u{202E}gnp.exe", '<mark class="homoglyph invisible" title="invisible U+202E"></mark>gnp.exe'],
			// these three passed through unmarked before the fix
			'RLO on its own' => ["\u{202E} gnp.exe", '<mark class="homoglyph invisible" title="invisible U+202E"></mark> gnp.exe'],
			'zero-width space on its own' => ["a \u{200B} b", 'a <mark class="homoglyph invisible" title="invisible U+200B"></mark> b'],
			'isolate and RLO at the end' => ["invoice \u{2066}\u{202E}", 'invoice <mark class="homoglyph invisible" title="invisible U+2066"></mark><mark class="homoglyph invisible" title="invisible U+202E"></mark>'],
			'Cyrillic word with a format char' => ["привет\u{200B}", '<mark class="homoglyph" title="U+043F">п</mark><mark class="homoglyph" title="U+0440">р</mark><mark class="homoglyph" title="U+0438">и</mark><mark class="homoglyph" title="U+0432">в</mark><mark class="homoglyph" title="U+0435">е</mark><mark class="homoglyph" title="U+0442">т</mark><mark class="homoglyph invisible" title="invisible U+200B"></mark>'],
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
		$rest = (string) preg_replace('#<mark class="homoglyph( invisible)?" title="(invisible )?U\+[0-9A-F]{4,6}">|</mark>#', '', $out);
		$this->assertDoesNotMatchRegularExpression('/[<>"\']/', $rest, $out);
	}

	// what a copy from the page gives: the text nodes, without the CSS dots and the format chars
	public function testCopiedTextHasNoFormatChars(): void {
		$from = "C\u{200D}\u{2061}r\u{2062}e\u{2060}\u{2063}d\u{2064}\u{2062}i\u{200C}a\u{2063}\u{2064}B\u{2063}a\u{200D}\u{2062}n\u{2061}k\u{2063}\u{200C} "
			. "e\u{200C}\u{200C}-\u{200B}\u{200C}b\u{2063}\u{200B}a\u{200D}\u{2060}n\u{2063}k\u{200C}\u{200C}i\u{2064}\u{200C}n\u{2063}\u{FEFF}g\u{2064} <no-reply@example.org>";
		$out = RqwatchExtension::markHomoglyphs($from);
		$this->assertSame(32, substr_count($out, '<mark class="homoglyph invisible"'));
		$this->assertSame('CrediaBank e-banking <no-reply@example.org>', html_entity_decode(strip_tags($out), ENT_QUOTES));
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

	// still escaped, the filter is is_safe
	public function testLinkifyPcreFailure(): void {
		$limit = (string) ini_get('pcre.backtrack_limit');
		ini_set('pcre.backtrack_limit', '1');
		try {
			$this->assertSame('&lt;b&gt; https://example.org/page', RqwatchExtension::linkify('<b> https://example.org/page'));
		} finally {
			ini_set('pcre.backtrack_limit', $limit);
		}
	}

	public function testNf(): void {
		$this->assertSame('1.235', RqwatchExtension::nf(1234.5));
		$this->assertSame('1.234,50', RqwatchExtension::nf(1234.5, 2));
	}

	public function testRegistered(): void {
		$extension = self::extension();
		$this->assertSame([
			'get_route', 'route', 'logout_token', 'get_action_details', 'get_fuzzy_hits', 'get_row_class',
			'get_symbol_class', 'decodeEmail', 'formatSizeUnits', 'gethostbyaddr', 'truncate', 'getDelivery', 'getAuthProvider',
		], array_map(fn (TwigFunction $function): string => $function->getName(), $extension->getFunctions()));
		$this->assertSame(['nf', 'linkify', 'mark_homoglyphs', 'js_json'],
			array_map(fn (TwigFilter $filter): string => $filter->getName(), $extension->getFilters()));
	}

	/** @return array<string, array{string, string}> template, output */
	public static function templates(): array {
		return [
			'route by case' => ["{{ route('ADMIN_FUZZY_SEARCH') }}", '/admin/fuzzy/search'],
			'route by case, with parameters' => ["{{ route('ADMIN_DETAIL', {type: 'qid', value: 'ABC123'}) }}", '/admin/detail/qid/ABC123'],
			'route by name' => ["{{ get_route('admin_fuzzy_search') }}", '/admin/fuzzy/search'],
			'route by name, with parameters' => ["{{ get_route('admin_detail', {type: 'qid', value: 'ABC123'}) }}", '/admin/detail/qid/ABC123'],
			'logout token' => ['{{ logout_token() }}', 'token-for-logout'],
			'nf' => ['{{ 1234.5|nf }} {{ 1234.5|nf(1) }}', '1.235 1.234,5'],
			'linkify, not escaped again' => ["{{ '<b> https://example.org'|linkify }}",
				'&lt;b&gt; <a href="https://example.org" target="_blank" rel="noopener noreferrer">https://example.org</a>'],
			'mark_homoglyphs, not escaped again' => ["{{ 'p\u{0430}y'|mark_homoglyphs }}", "p<mark class=\"homoglyph\" title=\"U+0430\">\u{0430}</mark>y"],
			'js_json, not escaped again' => ["<script>{{ '</script>'|js_json }}</script>", '<script>"\\u003C\\/script\\u003E"</script>'],
			'a Helper function' => ['{{ truncate("abcdefgh", 6) }}', 'abc...'],
		];
	}

	#[DataProvider('templates')]
	public function testTemplate(string $template, string $output): void {
		$this->assertSame($output, self::render(self::extension(), $template));
	}

	public function testUnknownRouteFails(): void {
		$this->expectException(RuntimeError::class);
		$this->expectExceptionMessage('Unknown RouteName enum case: NOPE');
		self::render(self::extension(), "{{ route('NOPE') }}");
	}

	public function testServicesFetchedOnlyWhenUsed(): void {
		$extension = new RqwatchExtension(
			fn (): never => throw new LogicException('URL generator fetched'),
			fn (): never => throw new LogicException('CSRF manager fetched'),
		);
		$this->assertSame('1.235', self::render($extension, '{{ 1234.5|nf }}'));
	}

	// labelled from fuzzy_learn_flags, read once: the listing asks for every row
	public function testFuzzyHits(): void {
		$template = '{% for hit in get_fuzzy_hits(symbols) %}{{ hit.label }}/{{ hit.class }} {% endfor %}';
		$symbols = [['name' => 'RQWATCH_FUZZY_DENIED'], ['name' => 'BAYES_SPAM'], ['name' => 'RQWATCH_FUZZY_WHITE']];
		Config::set('fuzzy_learn_flags', [1 => ['label' => 'Spam', 'symbol' => 'RQWATCH_FUZZY_DENIED']]);
		$extension = self::extension();
		$this->assertSame('Spam/rqwatch_fuzzy RQWATCH_FUZZY_WHITE/rqwatch_fuzzy_white ', self::render($extension, $template, ['symbols' => $symbols]));
		Config::set('fuzzy_learn_flags', [1 => ['label' => 'Changed', 'symbol' => 'RQWATCH_FUZZY_DENIED']]);
		$this->assertSame('Spam/rqwatch_fuzzy RQWATCH_FUZZY_WHITE/rqwatch_fuzzy_white ', self::render($extension, $template, ['symbols' => $symbols]));
		$this->assertSame('', self::render($extension, $template, ['symbols' => []]));
		$this->assertSame('', self::render($extension, $template, ['symbols' => null]));
	}

	private static function extension(): RqwatchExtension {
		return new RqwatchExtension(
			fn (): UrlGenerator => new UrlGenerator(Routes::load()['routes'], new RequestContext()),
			fn (): CsrfTokenManagerInterface => new class implements CsrfTokenManagerInterface {
				#[\Override]
				public function getToken(string $tokenId): CsrfToken {
					return new CsrfToken($tokenId, "token-for-{$tokenId}");
				}

				#[\Override]
				public function refreshToken(string $tokenId): CsrfToken {
					return $this->getToken($tokenId);
				}

				#[\Override]
				public function removeToken(string $tokenId): ?string {
					return null;
				}

				#[\Override]
				public function isTokenValid(CsrfToken $token): bool {
					return false;
				}
			},
		);
	}

	/** @param array<string, mixed> $context */
	private static function render(RqwatchExtension $extension, string $template, array $context = []): string {
		$twig = new Environment(new ArrayLoader(['t' => $template]), ['autoescape' => 'html', 'strict_variables' => true]);
		$twig->addExtension($extension);
		return $twig->render('t', $context);
	}
}
