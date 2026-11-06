<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Search results with ?month=Y-m (Reports -> Mails by Month): Date filters
 from the first to the last day of that month, replacing any other Date
 filter; an invalid month is ignored. Mails on both sides of each month
 boundary, February of a leap year.
*/

namespace Tests\Integration\Search;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Integration\IntegrationTestCase;
use Tests\Integration\WebClient;

final class MonthFilterTest extends IntegrationTestCase
{
	// id => created_at
	private const MAILS = [
		9601 => '2024-01-31 23:59:59',
		9602 => '2024-02-01 00:00:00',
		9603 => '2024-02-29 23:59:59',
		9604 => '2024-03-01 00:00:00',
	];

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		foreach (self::MAILS as $id => $createdAt) {
			$this->quarantined($id, 'mx1', ['admin@example.org'], ['created_at' => $createdAt]);
		}
	}

	/** @return array<string, array{string, string}> month, its last day */
	public static function monthCases(): array {
		return [
			'leap February' => ['2024-02', '2024-02-29'],
			'February' => ['2023-02', '2023-02-28'],
			'a 30-day month' => ['2024-04', '2024-04-30'],
			'December' => ['2024-12', '2024-12-31'],
		];
	}

	#[DataProvider('monthCases')]
	public function testMonthBounds(string $month, string $lastDay): void {
		$body = $this->results(self::client('admin'), "month={$month}");
		$this->assertSame(["is greater than or equal \"{$month}-01\"", "is less than or equal \"{$lastDay}\""],
			self::dateFilters($body));
	}

	public function testListsThatMonthOnly(): void {
		$this->assertListed($this->results(self::client('admin'), 'month=2024-02'), [9602, 9603]);
	}

	// a session Date filter, ?date= and ?since= all give way to the month
	public function testReplacesOtherDateFilters(): void {
		$admin = self::client('admin');
		$admin->get('/admin/search/add_day');
		$body = $this->results($admin, 'date=2024-03-01&since=2024-01-01&month=2024-02');
		$this->assertSame(['is greater than or equal "2024-02-01"', 'is less than or equal "2024-02-29"'],
			self::dateFilters($body));
		$this->assertListed($body, [9602, 9603]);
	}

	/** @return array<string, array{string}> */
	public static function invalidCases(): array {
		return [
			'month 13' => ['2024-13'],
			'month 00' => ['2024-00'],
			'one-digit month' => ['2024-2'],
			'two-digit year' => ['24-02'],
			'a day' => ['2024-02-01'],
			'not a date' => ['abc'],
			'empty' => [''],
			// '$' alone would match before it
			'trailing newline' => ["2024-02\n"],
		];
	}

	#[DataProvider('invalidCases')]
	public function testInvalidMonthIsIgnored(string $month): void {
		$body = $this->results(self::client('admin'), 'month=' . rawurlencode($month));
		$this->assertSame([], self::dateFilters($body));
		$this->assertListed($body, array_keys(self::MAILS));
	}

	private function results(WebClient $client, string $query): string {
		$response = $client->get("/admin/results?{$query}");
		$this->assertSame(200, $response['status'], $query);
		return $response['body'];
	}

	/** @return list<string> the Active Filters' Date entries, without the field name */
	private static function dateFilters(string $body): array {
		preg_match_all("~<li class='filtername'><span>Date ([^<]*)</span>~", $body, $m);
		return array_map('html_entity_decode', $m[1]);
	}

	/** @param list<int> $ids exactly these of this test's mails are listed */
	private function assertListed(string $body, array $ids): void {
		foreach (array_keys(self::MAILS) as $id) {
			if (in_array($id, $ids, true)) {
				$this->assertStringContainsString("SUBJ-Q-{$id}", $body, "mail {$id} missing");
			} else {
				$this->assertStringNotContainsString("SUBJ-Q-{$id}", $body, "mail {$id} listed");
			}
		}
	}
}
