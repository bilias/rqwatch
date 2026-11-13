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
 reject or discard) and get_action_details()'s whitelist, blacklist or
 map label. The map symbols are set directly, without a database.
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
		self::mappedSymbols(['RQWATCH_CUSTOM_MAP' => true]);
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
			'map symbol' => [[['name' => 'RQWATCH_CUSTOM_MAP', 'score' => 0.0]], 'rqwatch_map'],
			'local symbol pattern' => [[['name' => 'LOCAL_RULE', 'score' => 0.0]], 'rqwatch_map'],
		];
	}

	/** @param list<array{name: string, score: float}>|null $symbols */
	#[DataProvider('actionDetailCases')]
	public function testGetActionDetails(?array $symbols, string $expected): void {
		$this->assertSame($expected, Helper::get_action_details($symbols));
	}

	/** @param array<string, true>|null $set null: read again on next use */
	private static function mappedSymbols(?array $set): void {
		(new ReflectionProperty(MapService::class, 'mappedSymbolSet'))->setValue(null, $set);
	}
}
