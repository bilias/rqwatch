<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 What a mail's action and symbols show: getDelivery()'s delivery text,
 get_row_class()'s row colour (a whitelist wins unless rspamd forced a
 reject or discard), get_action_details()'s whitelist, blacklist or map
 label, get_symbol_class() for one symbol or detail line, the symbols
 sorted by score toward the mail's score, and the fuzzy hits with their
 labels. The map symbols are set directly, without a database.
*/

namespace Tests\Unit\Helpers;

use App\Services\MapService;
use App\Utils\Helper;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Tests\Unit\UnitTestCase;

final class ActionClassTest extends UnitTestCase
{
	private const array WL = [['name' => 'rqwatch_ip_whitelist', 'score' => -10.0]];
	private const array BL = [['name' => 'RQWATCH_MIME_FROM_BL', 'score' => 10.0]];
	private const array OTHER = [['name' => 'BAYES_SPAM', 'score' => 5.1]];

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		self::mappedSymbols(['CUSTOM_SENDERS' => true]);
	}

	#[\Override]
	protected function tearDown(): void {
		self::mappedSymbols(null);
		parent::tearDown();
	}

	/** @return array<string, array{string|null, string|null}> */
	public static function deliveryCases(): array {
		return [
			'no action' => ['no action', 'delivered'],
			'add header' => ['add header', 'delivered'],
			'rewrite subject' => ['rewrite subject', 'delivered'],
			'greylist' => ['greylist', 'greylisted'],
			'soft reject' => ['soft reject', 'temporarily rejected (greylisted or rate-limited)'],
			'reject' => ['reject', 'rejected and notified sender'],
			'discard' => ['discard', 'discarded without notifying sender'],
			'null' => [null, null],
			'empty' => ['', null],
			'zero' => ['0', null],
		];
	}

	#[DataProvider('deliveryCases')]
	public function testGetDelivery(?string $action, ?string $expected): void {
		$this->assertSame($expected, Helper::getDelivery($action));
	}

	/** @return array<string, array{string|null, list<array{name: string, score: float}>|null, string}> */
	public static function rowClassCases(): array {
		return [
			'no action' => ['no action', null, 'clean'],
			'add header' => ['add header', null, 'add_header'],
			'rewrite subject' => ['rewrite subject', null, 'subject'],
			'greylist' => ['greylist', null, 'greylist'],
			'soft reject' => ['soft reject', null, 'greylist'],
			'reject' => ['reject', null, 'reject'],
			'discard' => ['discard', null, 'discard'],
			'no action at all' => [null, null, 'clean'],
			'empty action' => ['', [], 'clean'],
			'other symbols' => ['reject', self::OTHER, 'reject'],
			'whitelist wins' => ['add header', self::WL, 'whitelist'],
			'forced reject keeps its colour' => ['reject', self::WL, 'reject'],
			'forced discard keeps its colour' => ['discard', self::WL, 'discard'],
			'blacklist' => ['no action', self::BL, 'blacklist'],
			'blacklist over reject' => ['reject', self::BL, 'blacklist'],
		];
	}

	/** @param list<array{name: string, score: float}>|null $symbols */
	#[DataProvider('rowClassCases')]
	public function testGetRowClass(?string $action, ?array $symbols, string $expected): void {
		$this->assertSame($expected, Helper::get_row_class($action, $symbols));
	}

	/** @return array<string, array{list<array{name: string, score: float}>|null, string}> */
	public static function actionDetailCases(): array {
		return [
			'none' => [null, ''],
			'empty' => [[], ''],
			'other symbols' => [self::OTHER, ''],
			'whitelist' => [self::WL, 'whitelist'],
			'blacklist' => [self::BL, 'blacklist'],
			'whitelist before blacklist' => [[...self::BL, ...self::WL], 'whitelist'],
			'map symbol' => [[['name' => 'CUSTOM_SENDERS', 'score' => 0.0]], 'rqwatch_map'],
			'local symbol pattern' => [[['name' => 'LOCAL_RULE', 'score' => 0.0]], 'rqwatch_map'],
		];
	}

	/** @param list<array{name: string, score: float}>|null $symbols */
	#[DataProvider('actionDetailCases')]
	public function testGetActionDetails(?array $symbols, string $expected): void {
		$this->assertSame($expected, Helper::get_action_details($symbols));
	}

	/** @return array<string, array{string|null, string}> */
	public static function symbolClassCases(): array {
		return [
			'fuzzy' => ['RQWATCH_FUZZY_DENIED', 'rqwatch_fuzzy'],
			'fuzzy detail line' => ['RQWATCH_FUZZY_WHITE:5.0:abc', 'rqwatch_fuzzy_white'],
			'whitelist' => ['rqwatch_ip_whitelist', 'whitelist'],
			'whitelist detail line' => ['rqwatch_ip_whitelist:-10.00:192.0.2.1', 'whitelist'],
			'blacklist' => ['RQWATCH_MIME_FROM_BL', 'blacklist'],
			'map symbol detail line' => ['CUSTOM_SENDERS:0.00:x', 'rqwatch_map'],
			'local symbol pattern' => ['LOCAL_RULE', 'rqwatch_map'],
			'other' => ['BAYES_SPAM:5.10:99%', ''],
			'empty' => ['', ''],
			'null' => [null, ''],
		];
	}

	#[DataProvider('symbolClassCases')]
	public function testGetSymbolClass(?string $symbol, string $expected): void {
		$this->assertSame($expected, Helper::get_symbol_class($symbol));
	}

	// the symbols that pushed the mail toward its score come first
	public function testGetScores(): void {
		$symbols = [
			['name' => 'A', 'score' => 1.0],
			['name' => 'B', 'score' => 5.0],
			['name' => 'C', 'score' => -2.0],
		];
		$this->assertSame(['B', 'A', 'C'], array_column(Helper::get_scores($symbols, 10.0), 'name'));
		$this->assertSame(['C', 'A', 'B'], array_column(Helper::get_scores($symbols, -3.0), 'name'));
		$this->assertSame(['C', 'A', 'B'], array_column(Helper::get_scores($symbols, 0.0), 'name'));
		$this->assertSame([], Helper::get_scores([], 1.0));
	}

	public function testGetFuzzyHits(): void {
		$symbols = [
			['name' => 'RQWATCH_FUZZY_DENIED'],
			['name' => 'BAYES_SPAM'],
			['name' => 'rqwatch_fuzzy_white'],
		];
		// labels are keyed by the upper-case name; an unlabelled hit shows its name
		$this->assertSame([
			['label' => 'RQWATCH_FUZZY_DENIED', 'class' => 'rqwatch_fuzzy'],
			['label' => 'Not spam', 'class' => 'rqwatch_fuzzy_white'],
		], Helper::getFuzzyHits($symbols, ['RQWATCH_FUZZY_WHITE' => 'Not spam']));
		$this->assertSame([], Helper::getFuzzyHits([], []));
	}

	/** @param array<string, true>|null $set null: read again on next use */
	private static function mappedSymbols(?array $set): void {
		(new ReflectionProperty(MapService::class, 'mappedSymbolSet'))->setValue(null, $set);
	}
}
