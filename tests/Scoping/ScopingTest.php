<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis
 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Data scoping through the whole stack (middleware, controller, service)
 against a running instance seeded by qa/scoping-tests.sh with
 tests/Scoping/fixtures.sql: alice owns mails 101 and 103 (via her alias)
 and map entry 201, bob owns mail 102 and map entry 202.
 Each refusal has a matching positive check, so a broken login or fixture
 cannot make a refusal pass by accident.
*/

namespace Tests\Scoping;

use PDO;
use PHPUnit\Framework\TestCase;

final class ScopingTest extends TestCase
{
	private const MAP = '/user/map/mail_from_rcpt_to_whitelist';

	private static ?PDO $db = null;

	private static function client(string $user): WebClient {
		$client = new WebClient((string) getenv('SCOPING_BASE_URL'));
		$client->login($user, "pw-{$user}");
		return $client;
	}

	private static function db(): PDO {
		return self::$db ??= new PDO(
			(string) getenv('SCOPING_DB_DSN'),
			(string) getenv('SCOPING_DB_USER'),
			(string) getenv('SCOPING_DB_PASS'),
			[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
		);
	}

	private static function value(string $sql, int $id): mixed {
		$stmt = self::db()->prepare($sql);
		$stmt->execute([$id]);
		return $stmt->fetchColumn();
	}

	protected function setUp(): void {
		if (getenv('SCOPING_BASE_URL') === false) {
			$this->markTestSkipped('Run through qa/scoping-tests.sh');
		}
	}

	/** @param array{status: int, location: string, body: string} $response */
	private function assertRefused(array $response, string $what): void {
		$this->assertSame(302, $response['status'], "{$what}: expected a redirect");
		$this->assertStringNotContainsString('BOB-102', $response['body'], "{$what}: leaked bob's mail");
	}

	public function testOwnAndAliasMailIsVisible(): void {
		$alice = self::client('alice');
		foreach (['/mail/101' => 'SUBJ-ALICE-101', '/mail/103' => 'SUBJ-ALIAS-103'] as $path => $subject) {
			$response = $alice->get($path);
			$this->assertSame(200, $response['status'], $path);
			$this->assertStringContainsString($subject, $response['body'], $path);
		}
	}

	public function testOthersMailIsRefused(): void {
		$alice = self::client('alice');
		foreach (['/mail/102', '/detail/id/102', '/detail/qid/QIDBOB102', '/admin/mail/102'] as $path) {
			$this->assertRefused($alice->get($path), "alice {$path}");
		}

		$bob = self::client('bob');
		foreach (['/mail/101', '/mail/103'] as $path) {
			$response = $bob->get($path);
			$this->assertSame(302, $response['status'], "bob {$path}");
			$this->assertStringNotContainsString('SUBJ-ALI', $response['body'], "bob {$path}");
		}
	}

	public function testAdminSeesEveryMail(): void {
		$admin = self::client('admin');
		foreach (['/admin/mail/101' => 'SUBJ-ALICE-101', '/admin/mail/102' => 'SUBJ-BOB-102'] as $path => $subject) {
			$response = $admin->get($path);
			$this->assertSame(200, $response['status'], $path);
			$this->assertStringContainsString($subject, $response['body'], $path);
		}
	}

	public function testAttachmentsAreScoped(): void {
		$alice = self::client('alice');
		foreach (['save', 'open'] as $action) {
			$own = $alice->get("/attach/{$action}/101/0");
			$this->assertSame(200, $own['status'], "own attachment {$action}");
			$this->assertStringContainsString('ATTACHMENT-SUBJ-ALICE-101', $own['body']);

			$this->assertRefused($alice->get("/attach/{$action}/102/0"), "attachment {$action} 102");
		}
	}

	public function testListingsAreScoped(): void {
		$alice = self::client('alice');
		$today = date('Y-m-d');
		// a filter in the session, needed for /results and user reports
		$alice->get('/search/add_day');

		foreach (['/', '/day', "/day/{$today}", "/quarantine/{$today}", '/results'] as $path) {
			$response = $alice->get($path);
			$this->assertSame(200, $response['status'], $path);
			$this->assertStringContainsString('SUBJ-ALICE-101', $response['body'], "{$path}: own mail missing");
			$this->assertStringContainsString('SUBJ-ALIAS-103', $response['body'], "{$path}: alias mail missing");
			$this->assertStringNotContainsString('SUBJ-BOB-102', $response['body'], "{$path}: lists bob's mail");
		}
	}

	public function testReportsAreScoped(): void {
		$alice = self::client('alice');
		$alice->get('/search/add_day');

		$reports = [
			'ip' => ['192.0.2.11', '192.0.2.12'],
			'mail_from' => ['sender-alice@example.net', 'sender-bob@example.net'],
			'mail_recipients' => ['alice@example.org', 'bob@example.org'],
		];
		foreach ($reports as $field => [$own, $bobs]) {
			$response = $alice->get("/reports/{$field}/count");
			$this->assertSame(200, $response['status'], $field);
			$this->assertStringContainsString($own, $response['body'], "{$field}: own row missing");
			$this->assertStringNotContainsString($bobs, $response['body'], "{$field}: shows bob's row");
		}
	}

	public function testReleaseOfOthersMailIsRefused(): void {
		$alice = self::client('alice');
		$page = $alice->get('/mail/101')['body'];
		$fields = [
			'mail_release_form[_token]' => WebClient::match('/name="mail_release_form\[_token\]"[^>]*value="([^"]+)"/', $page),
			'mail_release_form[release]' => '1',
		];

		$this->assertRefused($alice->post('/release/102', $fields), 'release 102');
		$this->assertSame(0, (int) self::value('SELECT released FROM mail_logs WHERE id = ?', 102));

		// the same token and form release her own mail
		$alice->post('/release/101', $fields);
		$this->assertSame(1, (int) self::value('SELECT released FROM mail_logs WHERE id = ?', 101));
	}

	public function testMapEntriesAreScoped(): void {
		$alice = self::client('alice');
		$page = $alice->get(self::MAP);
		$this->assertSame(200, $page['status']);
		$this->assertStringContainsString('map-alice@example.net', $page['body']);
		$this->assertStringNotContainsString('map-bob@example.net', $page['body']);

		$edit = $alice->get(self::MAP . '/edit/202');
		$this->assertSame(302, $edit['status'], 'edit 202');
		$this->assertStringNotContainsString('map-bob@example.net', $edit['body']);

		// her own tokens, used on bob's entry
		$toggle = WebClient::match('#/toggle/201\?_token=([^"\']+)#', $page['body']);
		$del = WebClient::match('#/del/201\?_token=([^"\']+)#', $page['body']);
		$delall = WebClient::match('#/delall\?_token=([^"\']+)#', $page['body']);

		$alice->get(self::MAP . '/toggle/202?_token=' . urlencode($toggle));
		$alice->get(self::MAP . '/del/202?_token=' . urlencode($del));
		$this->assertSame(0, (int) self::value('SELECT disabled FROM maps_combined WHERE id = ?', 202), 'bob 202 toggled');
		$this->assertSame(1, (int) self::value('SELECT COUNT(*) FROM maps_combined WHERE id = ?', 202), 'bob 202 deleted');

		// the same tokens work on her own entry
		$alice->get(self::MAP . '/toggle/201?_token=' . urlencode($toggle));
		$this->assertSame(1, (int) self::value('SELECT disabled FROM maps_combined WHERE id = ?', 201), 'alice 201 not toggled');

		// delete-all removes only her entries
		$alice->get(self::MAP . '/delall?_token=' . urlencode($delall));
		$this->assertSame(0, (int) self::value('SELECT COUNT(*) FROM maps_combined WHERE id = ?', 201), 'alice 201 kept');
		$this->assertSame(1, (int) self::value('SELECT COUNT(*) FROM maps_combined WHERE id = ?', 202), 'bob 202 deleted by delall');
	}

	public function testAdminOnlyMapIsRefused(): void {
		$alice = self::client('alice');
		$response = $alice->get('/user/map/mail_from_whitelist');
		$this->assertSame(302, $response['status']);
	}
}
