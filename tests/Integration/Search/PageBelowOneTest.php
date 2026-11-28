<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 A ?page below 1 reads as page 1: on the mails list (Eloquent's paginate())
 and on the quarantine month, a page MailLogService slices itself, where
 array_slice() would count a negative offset from the end and list other
 mails. With one mail per page, ?page=0 and ?page=-1 list the mail ?page=1
 does, and ?page=2 another. The fixture's three stored mails are this
 month's.
*/

namespace Tests\Integration\Search;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Integration\IntegrationTestCase;

final class PageBelowOneTest extends IntegrationTestCase
{
	/** @return array<string, array{string}> */
	public static function lists(): array {
		return [
			'the mails' => ['/admin'],
			'the quarantine month' => ['/admin/quarantine/month/' . date('Y-m')],
		];
	}

	/**
	 * The mails a page lists, by id.
	 * @param array{status: int, location: string, body: string, headers: array<string, string>} $response
	 * @return list<string>
	 */
	private function ids(array $response, string $what): array {
		$this->assertSame(200, $response['status'], $what);
		preg_match_all('#/admin/detail/id/(\d+)#', $response['body'], $m);
		return array_values(array_unique($m[1]));
	}

	#[DataProvider('lists')]
	public function testPageBelowOne(string $path): void {
		self::withEnv(['REDIS_CONFIG_KEY' => '"rqwatch_config_page_' . bin2hex(random_bytes(4)) . '"'], function () use ($path): void {
			self::withLocalConfig('$items_per_page = 1;', function () use ($path): void {
				$admin = self::client('admin');
				$first = $this->ids($admin->get("{$path}?page=1"), 'page 1');
				$this->assertCount(1, $first);
				foreach (['0', '-1'] as $page) {
					$this->assertSame($first, $this->ids($admin->get("{$path}?page={$page}"), "page {$page}"), "page {$page}");
				}
				$second = $this->ids($admin->get("{$path}?page=2"), 'page 2');
				$this->assertCount(1, $second);
				$this->assertNotSame($first, $second);
			});
		});
	}
}
