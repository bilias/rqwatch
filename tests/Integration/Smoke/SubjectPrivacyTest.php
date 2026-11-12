<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 $subject_privacy decides the Subject column the same way on every mail
 list: the mails page, a fuzzy record's hits and a map's hits. 0 and 1 are
 accepted as false and true, as for every flag.
*/

namespace Tests\Integration\Smoke;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Integration\IntegrationTestCase;

final class SubjectPrivacyTest extends IntegrationTestCase
{
	private const string HEADER = "<th class='subjwidth'>Subject</th>";

	private int $fuzzy;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		// a record whose learned mail is purged: no mail_logs row, so no
		// explicit id moves AUTO_INCREMENT into other tests' fixed ids
		self::db()->prepare('INSERT INTO mail_log_fuzzy (mail_log_id, qid, api_server, flag, weight, hashes, learned_by)'
			. ' VALUES (?, ?, ?, ?, ?, ?, ?)')
			->execute([null, 'QIDSUBJPRIV', 'mx1', 11, 10, '[]', 'admin']);
		$this->fuzzy = (int) self::db()->lastInsertId();
	}

	#[\Override]
	protected function tearDown(): void {
		self::db()->prepare('DELETE FROM mail_log_fuzzy WHERE id = ?')->execute([$this->fuzzy]);
		parent::tearDown();
	}

	/** @return array<string, array{string, bool}> setting => Subject shown */
	public static function settings(): array {
		return [
			'false' => ['false', true],
			'zero' => ['0', true],
			'true' => ['true', false],
			'one' => ['1', false],
		];
	}

	#[DataProvider('settings')]
	public function testEveryMailList(string $value, bool $shown): void {
		$pages = ['/admin', "/admin/fuzzy/hits/{$this->fuzzy}", '/admin/maps/hits/mails?map=mail_from_whitelist&days=31'];
		self::withEnv(['REDIS_CONFIG_KEY' => '"rqwatch_config_subject_privacy_' . bin2hex(random_bytes(4)) . '"'],
			function () use ($value, $shown, $pages): void {
				self::withLocalConfig("\$subject_privacy = {$value};", function () use ($shown, $pages): void {
					$admin = self::client('admin');
					foreach ($pages as $page) {
						$response = $admin->get($page);
						$this->assertSame(200, $response['status'], $page);
						if ($shown) {
							$this->assertStringContainsString(self::HEADER, $response['body'], $page);
						} else {
							$this->assertStringNotContainsString(self::HEADER, $response['body'], $page);
						}
					}
				});
			}
		);
	}
}
