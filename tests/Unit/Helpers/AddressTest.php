<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Address helpers: SRS/PRVS decoding for display,
 the release form's alternate recipients and the admin notification list.
*/

namespace Tests\Unit\Helpers;

use App\Configuration\Config;
use App\Utils\Helper;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Unit\UnitTestCase;

final class AddressTest extends UnitTestCase
{
	/** @return array<string, array{string, string}> */
	public static function decodeCases(): array {
		return [
			'plain, lowercased' => ['Alice@Example.ORG', 'alice@example.org'],
			'SRS0' => ['SRS0=HHH=TT=example.org=alice@fwd.example.net', 'alice@example.org'],
			'SRS0, = in local part' => ['srs0=HHH=TT=example.org=a=b@fwd.example.net', 'a=b@example.org'],
			'SRS1' => ['SRS1=HHH=fwd1.example==HHH=TT=example.org=alice@fwd2.example', 'alice@example.org'],
			'PRVS' => ['prvs=1234abcd=Alice@Example.org', 'alice@example.org'],
			'PRVS inside SRS0' => ['SRS0=HHH=TT=example.org=prvs=1234abcd=alice@fwd.example.net', 'alice@example.org'],
			'empty' => ['', ''],
		];
	}

	#[DataProvider('decodeCases')]
	public function testDecodeEmail(string $in, string $expected): void {
		$this->assertSame($expected, Helper::decodeEmail($in));
	}

	public function testPrvsKeepsCaseOnItsOwn(): void {
		$this->assertSame('Alice@Example.org', Helper::convertPRVSAddress('prvs=1234abcd=Alice@Example.org'));
		$this->assertSame('bob@example.org', Helper::convertPRVSAddress('bob@example.org'));
	}

	/** @return array<string, array{string, list<string>|null}> */
	public static function emailListCases(): array {
		return [
			'trimmed, lowercased, deduplicated' => [' A@x.org, b@x.org ,a@X.org,, ', ['a@x.org', 'b@x.org']],
			'single' => ['a@x.org', ['a@x.org']],
			'one invalid refuses all' => ['a@x.org, nope', null],
			'empty' => ['', null],
			'only separators' => [' , ,', null],
		];
	}

	/** @param list<string>|null $expected */
	#[DataProvider('emailListCases')]
	public function testParseEmailList(string $in, ?array $expected): void {
		$this->assertSame($expected, Helper::parseEmailList($in));
	}

	/** @return array<string, array{string, string}> */
	public static function mimeFromCases(): array {
		return [
			'display name' => ['Alice <alice@example.org>', 'alice@example.org'],
			'first of several' => ['a@x.org, b@x.org', 'a@x.org'],
			'empty' => ['', ''],
			'empty address' => ['<>', ''],
			// mailparse gives a group's members as one "address" (":;" when empty)
			'empty group' => ['undisclosed-recipients:;', ''],
			'group' => ['Team: a@x.org, b@x.org;', ''],
			'not an address' => ['not an address', ''],
			'first valid of several' => ['not an address, b@x.org', 'b@x.org'],
		];
	}

	// no address: '' without a warning
	#[DataProvider('mimeFromCases')]
	public function testGetMailFromMime(string $in, string $expected): void {
		$this->assertSame($expected, (new Helper())->getMailFromMime($in));
	}

	public function testAdminNotificationRcptDropsInvalidAndDuplicates(): void {
		Config::set('admin_notification_rcpt', ' a@x.org, bad, a@x.org ,B@x.org');
		$this->assertSame(['a@x.org', 'B@x.org'], Helper::adminNotificationRcpt());
	}

	public function testAdminNotificationRcptUnset(): void {
		// one variable per call: PHPStan would carry the first result over to the second
		$unset = Helper::adminNotificationRcpt();
		$this->assertSame([], $unset);
		Config::set('admin_notification_rcpt', '');
		$empty = Helper::adminNotificationRcpt();
		$this->assertSame([], $empty);
	}
}
