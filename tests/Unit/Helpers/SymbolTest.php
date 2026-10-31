<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Symbol classification: the virus skip map patterns, the
 *_symbol_patterns config, and our fuzzy symbols.
*/

namespace Tests\Unit\Helpers;

use App\Configuration\Config;
use App\Utils\Helper;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Unit\UnitTestCase;

final class SymbolTest extends UnitTestCase
{
	private const array SYMBOLS = [
		['name' => 'BAYES_SPAM', 'group' => 'bayes', 'options' => ['99%']],
		['name' => 'CLAM_VIRUS', 'group' => 'antivirus', 'options' => ['Eicar-Signature']],
		['name' => 'SECURITE_SPAM', 'group' => 'antivirus', 'options' => ['SecuriteInfo.com.Spam-154042.UNOFFICIAL']],
	];

	public static function virusSkipCases(): array {
		return [
			'option, case-insensitive glob' => [['eicar-*'], 'eicar-*'],
			'symbol name' => [['securite_spam'], 'securite_spam'],
			'churning id' => [['*Spam-*'], '*Spam-*'],
			'earliest symbol decides' => [['nomatch', '*.UNOFFICIAL', 'CLAM_VIRUS'], 'CLAM_VIRUS'],
			'other groups ignored' => [['BAYES_*', '99%'], null],
			'no patterns' => [[], null],
			'display form is not a name' => [['[CLAM_VIRUS: Eicar-Signature]'], null],
		];
	}

	#[DataProvider('virusSkipCases')]
	public function testVirusSkipMatch(array $patterns, ?string $expected): void {
		$this->assertSame($expected, Helper::virusSkipMatch(self::SYMBOLS, $patterns));
	}

	public function testVirusSkipMalformedSymbols(): void {
		$symbols = [
			'not a record',
			['group' => 'antivirus', 'options' => ['nameless']],
			['name' => 'V', 'group' => 'antivirus', 'options' => [['nested']]],
		];
		$this->assertNull(Helper::virusSkipMatch($symbols, ['nameless', 'nested']));
		$this->assertSame('single.*', Helper::virusSkipMatch(
			[['name' => 'V', 'group' => 'antivirus', 'options' => 'Single.Opt']],
			['single.*']
		));
	}

	public function testSymbolPatternsFromConfig(): void {
		Config::set('blacklist_symbol_patterns', ['MY_*_DENY']);
		$this->assertTrue(Helper::symbolMatches('my_from_deny', 'blacklist_symbol_patterns'));
		$this->assertFalse(Helper::symbolMatches('RQWATCH_FROM_BL', 'blacklist_symbol_patterns'));
		$this->assertSame([], $this->log->lines);
	}

	public static function invalidPatternConfigs(): array {
		return [
			'unset' => [null],
			'empty list' => [[]],
			'string' => ['RQWATCH_*_BL'],
			'blank entry' => [['RQWATCH_*_BL', ' ']],
		];
	}

	#[DataProvider('invalidPatternConfigs')]
	public function testInvalidSymbolPatternsUseDefaults(mixed $value): void {
		Config::set('blacklist_symbol_patterns', $value);
		$this->assertTrue(Helper::symbolMatches('RQWATCH_FROM_BLACKLIST', 'blacklist_symbol_patterns'));
		$this->assertFalse(Helper::symbolMatches('BAYES_SPAM', 'blacklist_symbol_patterns'));
		$this->assertLogged('invalid blacklist_symbol_patterns in config');
	}

	public function testBlacklistAndWhitelistDefaults(): void {
		$this->assertTrue(Helper::checkForBlacklist([['name' => 'RQWATCH_MIME_FROM_BL']]));
		$this->assertFalse(Helper::checkForBlacklist([['name' => 'RQWATCH_MIME_FROM_WL'], ['score' => 1]]));
		$this->assertTrue(Helper::checkForWhitelist([['name' => 'rqwatch_ip_whitelist']]));
		$this->assertFalse(Helper::checkForWhitelist([]));
	}

	public static function fuzzyCases(): array {
		return [
			'spam list' => ['RQWATCH_FUZZY_DENIED', 'rqwatch_fuzzy'],
			'not spam list' => ['RQWATCH_FUZZY_WHITE', 'rqwatch_fuzzy_white'],
			'lowercase' => ['rqwatch_fuzzy_white', 'rqwatch_fuzzy_white'],
			'detail line' => ['RQWATCH_FUZZY_WHITE:5.0:abc', 'rqwatch_fuzzy_white'],
			"rspamd's own" => ['FUZZY_DENIED', null],
			'map symbol' => ['RQWATCH_FROM_BL', null],
			'empty' => ['', null],
		];
	}

	#[DataProvider('fuzzyCases')]
	public function testFuzzySymbolClass(string $symbol, ?string $expected): void {
		$this->assertSame($expected, Helper::fuzzySymbolClass($symbol));
		$this->assertSame($expected !== null, Helper::isFuzzySymbol($symbol));
	}
}
