<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The fuzzy API doing the work, on RecordingDatabase, rspamd's controller a
 MockHttpClient through ApiClient's seam. Learn takes the stored mail by
 id, unlearn and boost the learned record by id; each request is logged
 (to file and syslog) before the work, then answered "Message learned",
 "unlearned" or "boosted". A mail or record not found is a 422 "Message
 not found"; the service's refusals map to their status: a conflict 409,
 unavailable 501, rspamd's failure 502, an internal one 500, each with the
 service's message.
*/

namespace Tests\Unit\Api;

use App\Api\FuzzyMailApi;
use App\Configuration\Config;
use App\Core\App;
use App\Core\AppContainer;
use App\Core\Database\MigrationStatus;
use App\Inventory\Migrations;
use App\Services\ApiClient;
use Illuminate\Database\Eloquent\Model;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Tests\Unit\RecordingDatabase;

final class FuzzyMailApiStoreTest extends ApiTestCase
{
	private const string A = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';

	private RecordingDatabase $db;

	private string $dir = '';

	/** @var list<array<string, mixed>> the mail_logs rows found */
	private array $mails = [];

	/** @var list<array<string, mixed>> the learned records found */
	private array $learned = [];

	/** @var list<MockResponse> rspamd's replies, in order */
	private array $replies = [];

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->db = new RecordingDatabase($this->createStub(PDO::class));
		$this->db->capsule->bootEloquent();
		$this->db->affect = fn (): int => 1;
		$this->db->answer = fn (string $sql): array => match (true) {
			str_contains($sql, 'from `mail_logs`') => $this->mails,
			str_contains($sql, 'from `mail_log_fuzzy` where `mail_log_fuzzy`.`id` = ?') => $this->learned,
			default => [],
		};
		$status = new MigrationStatus(null, $this->log);
		$status->setMigrationState(Migrations::MAIL_LOG_FUZZY, Migrations::STATUS_COMPLETED);
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, $this->db->capsule, $status, null, false));
		Config::set('fuzzy_learn_enable', true);
		Config::set('fuzzy_learn_flags', [1 => ['label' => 'Spam']]);
		Config::set('API_SERVERS', ['mx1' => ['fuzzy_url' => 'http://127.0.0.1:11334']]);
		$this->setEnv('MY_API_SERVER_ALIAS', 'mx1');
		$this->setEnv('RSPAMD_CONTROLLER_ENABLE_PASS', null);
		$this->dir = sys_get_temp_dir() . '/rqwatch-fma-' . bin2hex(random_bytes(4));
		mkdir($this->dir);
		file_put_contents("{$this->dir}/Q1", "Subject: spam\r\n\r\nBuy now\r\n");
		$this->setEnv('QUARANTINE_DIR', $this->dir);
		(new ReflectionProperty(ApiClient::class, 'httpClient'))->setValue(null, fn (): HttpClientInterface => new MockHttpClient(
			fn (): MockResponse => array_shift($this->replies) ?? throw new \LogicException('No reply queued')
		));
		$this->mails = [['id' => 7, 'qid' => 'Q1', 'server' => 'mx1', 'mail_stored' => 1, 'mail_location' => "{$this->dir}/Q1"]];
		$this->learned = [['id' => 3, 'mail_log_id' => 7, 'qid' => 'Q1', 'api_server' => 'mx1', 'flag' => 1, 'weight' => 10, 'hashes' => json_encode([self::A])]];
	}

	#[\Override]
	protected function tearDown(): void {
		(new ReflectionProperty(ApiClient::class, 'httpClient'))->setValue(null, null);
		@unlink("{$this->dir}/Q1");
		@rmdir($this->dir);
		Model::unsetConnectionResolver();
		RecordingDatabase::forget();
		parent::tearDown();
	}

	/** @param array<string, string> $post */
	private function handle(array $post): Response {
		return $this->answer(fn () => (new FuzzyMailApi(Request::create('/api/fuzzy_mail.php', 'POST', $post + ['remote_user' => 'alice'])))->handle());
	}

	private function reply(string $body, int $code = 200): void {
		$this->replies[] = new MockResponse($body, ['http_code' => $code]);
	}

	private const string REQUESTED = "info [FuzzyMailApi] 'alice' via '192.0.2.7' requested fuzzy %s of mail Q1 on 'mx1'";

	public function testLearn(): void {
		$this->reply((string) json_encode(['hashes' => [self::A]]));
		$response = $this->handle(['action' => 'learn', 'id' => '7', 'flag' => '1']);
		$this->assertAnswer(200, 'Message learned', $response);
		$this->assertSame('text/plain; charset=UTF-8', $response->headers->get('Content-Type'));
		$requested = sprintf(self::REQUESTED, 'learn');
		$this->assertSame([$requested, $requested], array_slice($this->log->lines, 0, 2));
		$this->assertSame(['Q1', 'alice'], [$this->db->rows('mail_log_fuzzy')[0]['qid'] ?? null, $this->db->rows('mail_log_fuzzy')[0]['learned_by'] ?? null]);
	}

	public function testUnlearn(): void {
		$this->reply('{"success":true}');
		$this->assertAnswer(200, 'Message unlearned', $this->handle(['action' => 'unlearn', 'id' => '3']));
		$this->assertSame(sprintf(self::REQUESTED, 'unlearn'), $this->log->lines[0] ?? null);
		$this->assertContains(['delete from `mail_log_fuzzy` where `id` = ?', [3]], $this->db->changes);
	}

	public function testBoost(): void {
		$this->reply((string) json_encode(['hashes' => [self::A]]));
		$this->assertAnswer(200, 'Message boosted', $this->handle(['action' => 'boost', 'id' => '3', 'weight' => '5']));
		$this->assertSame(sprintf(self::REQUESTED, 'boost'), $this->log->lines[0] ?? null);
		$this->assertStringStartsWith('update `mail_log_fuzzy` set `weight` = `weight` + 5', $this->db->changes[0][0] ?? '');
	}

	/** @return array<string, array{array<string, string>, string}> the request, the error logged */
	public static function notFound(): array {
		return [
			'no such mail' => [['action' => 'learn', 'id' => '7', 'flag' => '1'], "learn of id 7 on 'mx1': Mail with ID '7' not found"],
			'no such record' => [['action' => 'unlearn', 'id' => '3'], "unlearn of id 3 on 'mx1': Fuzzy record 3 not found"],
		];
	}

	/** @param array<string, string> $post */
	#[DataProvider('notFound')]
	public function testNotFound(array $post, string $logged): void {
		$this->mails = [];
		$this->learned = [];
		$this->assertAnswer(422, 'Message not found', $this->handle($post));
		$this->assertContains("warning [FuzzyMailApi] alice via 192.0.2.7 requested fuzzy {$logged}", $this->log->lines);
	}

	/** @return array<string, array{array<string, string>, int, string}> the request, status, message */
	public static function refused(): array {
		return [
			'a conflict' => [['action' => 'boost', 'id' => '3', 'weight' => '65530'], 409, 'Mail Q1: total weight would exceed 65535'],
			'unavailable' => [['action' => 'learn', 'id' => '7', 'flag' => '2'], 501, "Fuzzy flag 2 is not configured on 'mx1'"],
			'rspamd failing' => [['action' => 'unlearn', 'id' => '3'], 502, "rspamd for 'mx1' refused the request (500)"],
		];
	}

	/** @param array<string, string> $post */
	#[DataProvider('refused')]
	public function testRefused(array $post, int $status, string $message): void {
		$this->reply('{"error":"busy"}', 500);
		$this->assertAnswer($status, $message, $this->handle($post));
		$action = $post['action'] ?? '';
		$id = $post['id'] ?? '';
		$this->assertContains("error [FuzzyMailApi] alice via 192.0.2.7 fuzzy {$action} of id {$id} on 'mx1' failed: {$message}", $this->log->lines);
	}

	// the mail's file gone: an internal failure
	public function testInternal(): void {
		unlink("{$this->dir}/Q1");
		$this->assertAnswer(500, 'Mail Q1 file not readable', $this->handle(['action' => 'learn', 'id' => '7', 'flag' => '1']));
	}
}
