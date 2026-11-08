<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Twig;

use App\Core\Routing\RouteName;
use App\Core\Routing\UrlBuilder;
use App\Services\FuzzyService;
use App\Utils\Helper;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

use Closure;
use RuntimeException;

/*
 rqwatch's Twig functions and filters. Each is a named method (as a
 first-class callable), never an anonymous closure, so the Twig taint
 analysis of psalm/plugin-symfony (see qa/PsalmPlugin/Plugin.php) follows
 it into its PHP code: a closure would leave it no way to tell that
 linkify and mark_homoglyphs, which are is_safe, escape what they output.
*/
final class RqwatchExtension extends AbstractExtension
{
	/** @var array<string, string>|null labels read once per request: the listing calls get_fuzzy_hits for every row */
	private ?array $fuzzyLabels = null;

	/**
	 * Called only when a template needs them: a cron renders mails with no
	 * URL generator set, and the CSRF manager starts the session.
	 *
	 * @param Closure(): UrlGeneratorInterface $urlGenerator
	 * @param Closure(): CsrfTokenManagerInterface $csrfManager
	 */
	public function __construct(
		private readonly Closure $urlGenerator,
		private readonly Closure $csrfManager,
	) {
	}

	#[\Override]
	public function getFunctions(): array {
		return [
			new TwigFunction('get_route', $this->getRoute(...)),
			new TwigFunction('route', $this->route(...)),
			// token for the logout link; checked by LoginController::logout()
			new TwigFunction('logout_token', $this->logoutToken(...)),
			new TwigFunction('get_action_details', Helper::get_action_details(...)),
			new TwigFunction('get_fuzzy_hits', $this->getFuzzyHits(...)),
			new TwigFunction('get_row_class', Helper::get_row_class(...)),
			new TwigFunction('get_symbol_class', Helper::get_symbol_class(...)),
			new TwigFunction('decodeEmail', Helper::decodeEmail(...)),
			new TwigFunction('formatSizeUnits', Helper::formatSizeUnits(...)),
			new TwigFunction('gethostbyaddr', Helper::my_gethostbyaddr(...)),
			new TwigFunction('truncate', self::truncate(...)),
			new TwigFunction('getDelivery', Helper::getDelivery(...)),
			new TwigFunction('getAuthProvider', Helper::getAuthProvider(...)),
		];
	}

	#[\Override]
	public function getFilters(): array {
		return [
			new TwigFilter('nf', self::nf(...)),
			new TwigFilter('linkify', self::linkify(...), ['is_safe' => ['html']]),
			new TwigFilter('mark_homoglyphs', self::markHomoglyphs(...), ['is_safe' => ['html']]),
			new TwigFilter('js_json', self::jsJson(...), ['is_safe' => ['html', 'js']]),
		];
	}

	/** @param array<string, mixed> $params */
	private function getRoute(string $name, array $params = []): string {
		return ($this->urlGenerator)()->generate($name, $params);
	}

	/** @param array<string, mixed> $params */
	private function route(string $caseName, array $params = []): string {
		$enumClass = RouteName::class;
		if (!defined("$enumClass::$caseName")) {
			throw new RuntimeException("Unknown RouteName enum case: $caseName");
		}

		$enumCase = constant("$enumClass::$caseName"); // gets the enum case object
		return UrlBuilder::generate(($this->urlGenerator)(), $enumCase, $params);
	}

	private function logoutToken(): string {
		return ($this->csrfManager)()->getToken('logout')->getValue();
	}

	/** @return list<array{label: string, class: string}> */
	private function getFuzzyHits(mixed $symbols): array {
		if (empty($symbols) || !is_array($symbols)) {
			return [];
		}
		$this->fuzzyLabels ??= FuzzyService::symbolLabels();
		return Helper::getFuzzyHits($symbols, $this->fuzzyLabels);
	}

	public static function truncate(mixed $str, int $len): string {
		return mb_strimwidth((string) $str, 0, $len, "...");
	}

	public static function nf(float $number, int $decimals = 0): string {
		return number_format($number, $decimals, ',', '.');
	}

	/**
	 * JSON for a <script> element: JSON_HEX_TAG encodes < and > as \u003C and
	 * \u003E, so no value can close the element ({{ chart.getData()|js_json }}).
	 * Not for an HTML attribute, as quotes are not encoded. As Twig's
	 * json_encode filter, a value JSON can't encode gives an empty string.
	 * @psalm-taint-escape html
	 */
	public static function jsJson(mixed $value): string {
		return (string) json_encode($value, JSON_HEX_TAG);
	}

	/**
	 * Escape text, turn http(s) URLs into links opening in a new tab.
	 * Output is marked safe; nl2br (pre_escape) won't re-escape it.
	 * {{ message|linkify|nl2br }} opens links in a new tab (the default).
	 * {{ message|linkify(false)|nl2br }} opens them in the same tab.
	 *
	 * Every part of $text goes through htmlspecialchars(ENT_QUOTES), and only
	 * http(s) URLs become links:
	 * @psalm-taint-escape html
	 * @psalm-taint-escape has_quotes
	 */
	public static function linkify(string $text, bool $newTab = true): string {
		$target = $newTab ? ' target="_blank" rel="noopener noreferrer"' : '';
		$parts = preg_split('~(https?://[^\s<>"\']+)~i', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
		// on a PCRE failure show the text without links; still escaped, the filter is is_safe
		if ($parts === false) {
			return htmlspecialchars($text, ENT_QUOTES);
		}
		$out = '';
		foreach ($parts as $i => $part) {
			// even indexes: plain text, odd indexes: captured URLs
			if ($i % 2 === 0) {
				$out .= htmlspecialchars($part, ENT_QUOTES);
				continue;
			}
			$url = rtrim($part, '.,;:!?)');
			$e = htmlspecialchars($url, ENT_QUOTES);
			$out .= '<a href="' . $e . '"' . $target . '>' . $e . '</a>'
				. htmlspecialchars(substr($part, strlen($url)), ENT_QUOTES);
		}
		return $out;
	}

	/**
	 * Escape text; in words mixing Latin with another script (homoglyph
	 * spoofing, e.g. Cyrillic о/а in "Eurоbаnk"), wrap non-Latin letters
	 * and invisible format chars (\p{Cf}) in <mark class="homoglyph">.
	 * Pure Greek / pure Latin words are untouched. Output is marked safe.
	 *
	 * Every character of $text goes through htmlspecialchars(ENT_QUOTES);
	 * the only other output is the markup and U+XXXX titles built here:
	 * @psalm-taint-escape html
	 * @psalm-taint-escape has_quotes
	 */
	public static function markHomoglyphs(?string $text): string {
		$parts = preg_split('/([\p{L}\p{M}\p{Cf}]+)/u', (string) $text, -1, PREG_SPLIT_DELIM_CAPTURE);
		if ($parts === false) {
			return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE);
		}
		$out = '';
		foreach ($parts as $i => $part) {
			// even indexes: separators, odd indexes: words
			// a format char is marked even with no letter next to it (U+202E before a space)
			$mixed = $i % 2 === 1
				&& (preg_match('/\p{Cf}/u', $part)
					|| (preg_match('/\p{Latin}/u', $part) && preg_match('/(?!\p{Latin})\p{L}/u', $part)));
			if (!$mixed) {
				$out .= htmlspecialchars($part, ENT_QUOTES | ENT_SUBSTITUTE);
				continue;
			}
			foreach (mb_str_split($part) as $ch) {
				$cp = sprintf('U+%04X', (int) mb_ord($ch));
				if (preg_match('/\p{Cf}/u', $ch)) {
					$out .= '<mark class="homoglyph" title="invisible ' . $cp . '">·</mark>';
				} elseif (preg_match('/(?!\p{Latin})\p{L}/u', $ch)) {
					$out .= '<mark class="homoglyph" title="' . $cp . '">'
						. htmlspecialchars($ch, ENT_QUOTES | ENT_SUBSTITUTE) . '</mark>';
				} else {
					$out .= htmlspecialchars($ch, ENT_QUOTES | ENT_SUBSTITUTE);
				}
			}
		}
		return $out;
	}
}
