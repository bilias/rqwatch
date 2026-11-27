<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 A quarantined mail as a MailObject (its bodies and attachments), on
 RecordingDatabase and, for another node, ApiClient's seam with a
 MockHttpClient. getMailObject() reads the mail in the user's scope; a mail
 not found or not stored is refused. This node's own mail is read from its
 file, which must be a file under QUARANTINE_DIR. Another node's comes from
 that node's get_mail API: a POST with Basic Auth (MAIL_API_USER/PASS) of
 the mail id and the user's email, no redirects followed, the server's
 options over the TLS defaults; only a 200 is the mail. Without the
 credentials, a url for the server, or on any other reply or a failed
 connection, nothing is shown and "Error. Contact admin" is all the user
 learns; the reason is logged (a 403 with what to check, replies cut to
 200 characters).
*/

namespace Tests\Unit\Services;

use App\Configuration\Config;
use App\Core\App;
use App\Core\AppContainer;
use App\Core\Database\MigrationStatus;
use App\Inventory\MailObject;
use App\Services\ApiClient;
use App\Services\MailLogService;
use Closure;
use Exception;
use Illuminate\Database\Eloquent\Model;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Tests\Unit\RecordingDatabase;
use Tests\Unit\UnitTestCase;

final class MailLogObjectTest extends UnitTestCase
{
	private const string EML = "From: a@example.org\r\nTo: alice@example.org\r\nSubject: Held\r\nMIME-Version: 1.0\r\n"
		. "Content-Type: multipart/mixed; boundary=\"b1\"\r\n\r\n"
		. "--b1\r\nContent-Type: text/plain; charset=us-ascii\r\n\r\nHeld <body>\r\n"
		. "--b1\r\nContent-Type: application/pdf; name=\"a.pdf\"\r\nContent-Disposition: attachment; filename=\"a.pdf\"\r\nContent-Transfer-Encoding: base64\r\n\r\nUERG\r\n"
		. "--b1--\r\n";
	private const string API = 'https://mx2.example.org/api/get_mail.php';
	private const string PASS = 'k9-unit-test-pass';
	private const string ACCESS = 'Check remote web server access control as well as local and remote MAIL_API_USER, MAIL_API_PASS, MAIL_API_ACL, API_ENABLE';

	private RecordingDatabase $db;

	private string $dir = '';

	/** @var array<string, mixed>|null the mail_logs row, null for none */
	private ?array $mail = null;

	/** @var list<MockResponse> the replies, in order */
	private array $replies = [];

	/** @var list<array{string, string, array<string, list<string>>, ?string}> method, url, headers, body */
	private array $requests = [];

	/** @var list<array<string, mixed>> the options each client was built with */
	private array $clients = [];

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->db = new RecordingDatabase($this->createStub(PDO::class));
		$this->db->capsule->bootEloquent();
		$this->db->answer = fn (string $sql): array => match (true) {
			str_contains($sql, 'from `mail_logs`') => $this->mail === null ? [] : [$this->mail],
			str_contains($sql, 'from `mail_log_data`') => [['mail_log_id' => 7, 'symbols' => null, 'headers' => null]],
			default => [],
		};
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, $this->db->capsule, new MigrationStatus(null, $this->log), null, false));
		$this->dir = sys_get_temp_dir() . '/rqwatch-mo-' . bin2hex(random_bytes(4));
		mkdir("{$this->dir}/q/2026-11-26/Q7", 0777, true);
		file_put_contents("{$this->dir}/q/2026-11-26/Q7/Q7", self::EML);
		file_put_contents("{$this->dir}/outside", self::EML);
		$this->setEnv('QUARANTINE_DIR', "{$this->dir}/q");
		$this->setEnv('MY_API_SERVER_ALIAS', 'mx1');
		$this->setEnv('MAIL_API_USER', 'rqwatch-api');
		$this->setEnv('MAIL_API_PASS', self::PASS);
		Config::set('GET_MAIL_API_PATH', '/api/get_mail.php');
		Config::set('API_SERVERS', [
			'mx1' => ['fuzzy_url' => 'http://127.0.0.1:11334/'],
			'mx2' => ['url' => 'https://mx2.example.org', 'options' => ['verify_peer' => false]],
			'mx3' => ['url' => ''],
		]);
		$this->mail = self::row('mx1', "{$this->dir}/q/2026-11-26/Q7/Q7");
		(new ReflectionProperty(ApiClient::class, 'httpClient'))->setValue(null, $this->client(...));
		// without a warning: a row or reply may lack any key
		set_error_handler(fn (int $no, string $message): never => throw new \ErrorException($message, 0, $no));
	}

	#[\Override]
	protected function tearDown(): void {
		restore_error_handler();
		(new ReflectionProperty(ApiClient::class, 'httpClient'))->setValue(null, null);
		@unlink("{$this->dir}/q/2026-11-26/Q7/Q7");
		@unlink("{$this->dir}/outside");
		@rmdir("{$this->dir}/q/2026-11-26/Q7");
		@rmdir("{$this->dir}/q/2026-11-26");
		@rmdir("{$this->dir}/q");
		@rmdir($this->dir);
		Model::unsetConnectionResolver();
		RecordingDatabase::forget();
		parent::tearDown();
	}

	/** @return array<string, mixed> */
	private static function row(string $server, ?string $location, int $stored = 1): array {
		return ['id' => 7, 'qid' => 'Q7', 'server' => $server, 'mail_stored' => $stored, 'mail_location' => $location, 'score' => 9.5, 'has_virus' => 0];
	}

	/** @param array<string, mixed> $options */
	private function client(array $options): HttpClientInterface {
		$this->clients[] = $options;
		return new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
			/** @var array<string, list<string>> $headers */
			$headers = $options['normalized_headers'] ?? [];
			/** @var mixed $body */
			$body = $options['body'] ?? null;
			$this->requests[] = [$method, $url, $headers, is_string($body) ? $body : null];
			return array_shift($this->replies) ?? throw new \LogicException("No reply queued for {$url}");
		});
	}

	private function reply(string $body, int $code = 200): void {
		$this->replies[] = new MockResponse($body, ['http_code' => $code]);
	}

	private static function admin(): MailLogService {
		return new MailLogService(['is_admin' => true, 'email' => 'root@example.org']);
	}

	/** @return list<string> the log, each caller's file and line cut */
	private function lines(): array {
		return array_map(fn (string $line): string => preg_replace('~ /\S+/app/Services/MailLogService\.php:\d+ ~', ' ', $line) ?? $line, $this->log->lines);
	}

	/**
	 * @param Closure(): mixed $call
	 * @param list<string> $lines
	 */
	private function assertRefused(Closure $call, string $message, array $lines): void {
		try {
			$call();
			$this->fail('No exception');
		} catch (Exception $e) {
			$this->assertSame([Exception::class, $message], [$e::class, $e->getMessage()]);
		}
		$this->assertSame($lines, $this->lines());
	}

	private function assertMail(MailObject $mail): void {
		$this->assertSame(
			[7, "Held &lt;body&gt;", [['filename' => 'a.pdf', 'filetype' => 'application/pdf']]],
			[$mail->getMailLog()->id, $mail->getTextBody(), $mail->getAttached()]
		);
	}

	public function testLocal(): void {
		$this->assertMail(self::admin()->getMailObjectLocal(7));
		$this->assertSame([], $this->log->lines);
	}

	public function testLocalWithoutId(): void {
		$lf = '[MailLogService_getMailObjectLocal]';
		$this->assertRefused(fn (): MailObject => self::admin()->getMailObjectLocal(0), 'Error. Contact admin', ["error {$lf} empty mail id", "error '{$lf} empty mail id'"]);
		$this->assertSame([], $this->db->selects);
	}

	public function testLocalNotFound(): void {
		$this->mail = null;
		$lf = '[MailLogService_getMailObjectLocal]';
		$this->assertRefused(fn (): MailObject => self::admin()->getMailObjectLocal(7), "{$lf} Mail with ID '7' not found", [
			"warning '[MailLogService_detailById] Mail with ID '7' not found'",
			"warning '{$lf} Mail with ID '7' not found'",
		]);
	}

	public function testLocalNotStored(): void {
		$this->mail = self::row('mx1', "{$this->dir}/q/2026-11-26/Q7/Q7", 0);
		$lf = '[MailLogService_getMailObjectLocal]';
		$this->assertRefused(fn (): MailObject => self::admin()->getMailObjectLocal(7), "{$lf} Mail with ID '7' not stored", ["error '{$lf} Mail with ID '7' not stored'"]);
	}

	/** @return array<string, array{?string}> */
	public static function badLocations(): array {
		return [
			'outside QUARANTINE_DIR' => ['outside'],
			'no file' => ['q/2026-11-26/Q7/Q8'],
			'a directory' => ['q/2026-11-26/Q7'],
			'none' => [null],
		];
	}

	#[DataProvider('badLocations')]
	public function testLocalFileRefused(?string $location): void {
		$path = $location === null ? null : "{$this->dir}/{$location}";
		$this->mail = self::row('mx1', $path);
		$lf = '[MailLogService_getMailObjectLocal]';
		$this->assertRefused(fn (): MailObject => self::admin()->getMailObjectLocal(7), "{$lf} File '{$path}' not found", [
			'warning Not a file under QUARANTINE_DIR: ' . ($path ?? ''),
			"error '{$lf} File '{$path}' not found'",
		]);
	}

	/** @param array<string, string> $body the form sent */
	private function assertApiCall(array $body): void {
		$this->assertCount(1, $this->requests);
		[$method, $url, $headers, $sent] = $this->requests[0];
		$this->assertSame(
			['POST', self::API, ['Authorization: Basic ' . base64_encode('rqwatch-api:' . self::PASS)], http_build_query($body)],
			[$method, $url, $headers['authorization'] ?? null, $sent]
		);
		$this->assertSame([['verify_peer' => false, 'verify_host' => true, 'capath' => null, 'max_redirects' => 0]], $this->clients);
	}

	public function testViaApi(): void {
		$this->mail = self::row('mx2', '/var/quarantine/2026-11-26/Q7/Q7');
		$this->reply(self::EML);
		$this->assertMail(self::admin()->getMailObjectViaApi(7, 'mx2'));
		$this->assertApiCall(['id' => '7', 'remote_user' => 'root@example.org']);
		$this->assertSame([], $this->log->lines);
	}

	/** @return array<string, array{int, string, string}> id, server, the error logged */
	public static function apiArguments(): array {
		return [
			'no id' => [0, 'mx2', 'empty mail id'],
			'no server' => [7, '', 'empty api server'],
		];
	}

	#[DataProvider('apiArguments')]
	public function testViaApiWithout(int $id, string $server, string $error): void {
		$this->assertRefused(fn (): MailObject => self::admin()->getMailObjectViaApi($id, $server), 'Error. Contact admin', ["error '[MailLogService_getMailObjectViaApi] {$error}'"]);
		$this->assertSame([[], []], [$this->db->selects, $this->requests]);
	}

	public function testViaApiNotFound(): void {
		$this->mail = null;
		$this->assertRefused(fn (): MailObject => self::admin()->getMailObjectViaApi(7, 'mx2'), "Mail with ID '7' not found", [
			"warning '[MailLogService_detailById] Mail with ID '7' not found'",
			"warning '[MailLogService_getMailObjectViaApi] InvalidArgumentException for id 7: Mail with ID '7' not found'",
		]);
	}

	public function testViaApiNotStored(): void {
		$this->mail = self::row('mx2', null, 0);
		$this->assertRefused(fn (): MailObject => self::admin()->getMailObjectViaApi(7, 'mx2'), 'Mail with id 7 is not stored', ["error '[MailLogService_getMailObjectViaApi] Mail with id 7 is not stored'"]);
		$this->assertSame([], $this->requests);
	}

	/** @return array<string, array{string}> */
	public static function withoutUrl(): array {
		return [
			'not in API_SERVERS' => ['mx9'],
			'no url' => ['mx1'],
			'an empty url' => ['mx3'],
		];
	}

	#[DataProvider('withoutUrl')]
	public function testViaApiWithoutUrl(string $server): void {
		$this->mail = self::row($server, null);
		$this->assertRefused(fn (): MailObject => self::admin()->getMailObjectViaApi(7, $server), 'Error. Contact admin', [
			"error '[MailLogService_getMailObjectViaApi] API server '{$server}' does not exist in API_SERVERS or has an empty url. Check config.local.php'",
		]);
		$this->assertSame([], $this->requests);
	}

	/** @return array<string, array{int, string, list<string>}> code, body, the lines logged before the refusal */
	public static function apiFailures(): array {
		$lf = '[MailLogService_getMailObjectViaApi]';
		$long = str_repeat('é', 250);
		$cut = str_repeat('é', 197) . '...';
		return [
			"403, the API's refusal" => [403, 'Permission denied', [
				"error {$lf} wrong response code: 403 Forbidden, from API server 'mx2'. API server said: 'Permission denied'",
				"warning {$lf} Check local and remote MAIL_API_USER, MAIL_API_PASS, MAIL_API_ACL",
			]],
			'403, a web page' => [403, $long, [
				"error {$lf} wrong response code: 403 Forbidden, from API server 'mx2'. API server said: '{$cut}'",
				"warning {$lf} " . self::ACCESS,
			]],
			'404, not found' => [404, 'Not Found', ["error {$lf} wrong response code: 404 from API server 'mx2'. API server said: 'Not Found'"]],
			'500, a long text' => [500, $long, ["error {$lf} wrong response code: 500 from API server 'mx2'. API server said: '{$cut}'"]],
			'302, not followed' => [302, '', ["error {$lf} wrong response code: 302 from API server 'mx2'. API server said: ''"]],
		];
	}

	/** @param list<string> $lines */
	#[DataProvider('apiFailures')]
	public function testViaApiFails(int $code, string $body, array $lines): void {
		$this->mail = self::row('mx2', null);
		$this->reply($body, $code);
		$this->assertRefused(fn (): MailObject => self::admin()->getMailObjectViaApi(7, 'mx2'), 'Error. Contact admin', [
			...$lines,
			"error '[MailLogService_getMailObjectViaApi] problem: Error. Contact admin'",
		]);
	}

	public function testViaApiUnreachable(): void {
		$this->mail = self::row('mx2', null);
		$this->replies[] = new MockResponse('', ['error' => 'Connection refused']);
		$this->assertRefused(fn (): MailObject => self::admin()->getMailObjectViaApi(7, 'mx2'), 'Error. Contact admin', [
			"error '[MailLogService_getMailObjectViaApi] problem: Connection refused'",
		]);
	}

	public function testMailObjectLocal(): void {
		$this->assertMail(self::admin()->getMailObject(7));
		$this->assertSame([], $this->requests);
	}

	public function testMailObjectViaApi(): void {
		$this->mail = self::row('mx2', null);
		$this->reply(self::EML);
		$this->assertMail((new MailLogService(['is_admin' => false, 'email' => 'alice@example.org']))->getMailObject(7));
		$this->assertApiCall(['id' => '7', 'remote_user' => 'alice@example.org']);
	}

	public function testMailObjectWithoutId(): void {
		$this->assertRefused(fn (): MailObject => self::admin()->getMailObject(0), 'Error. Contact admin', ["error '[MailLogService_getMailObject] empty mail id'"]);
		$this->assertSame([], $this->db->selects);
	}

	public function testMailObjectNotFound(): void {
		$this->mail = null;
		$lf = '[MailLogService_getMailObject]';
		$this->assertRefused(fn (): MailObject => (new MailLogService(['is_admin' => false, 'email' => 'alice@example.org']))->getMailObject(7), "Mail with ID '7' not found", [
			"warning '[MailLogService_getMailLog] Mail with ID '7' not found'",
			"warning {$lf} Mail with ID '7' not found. Mail does not exist or user does not have access to it",
			"error '{$lf} Mail with ID '7' not found'",
		]);
		$this->assertSame(['email' => 'alice@example.org', 'is_admin' => false], $this->log->contexts[1]);
	}

	public function testMailObjectNotStored(): void {
		$this->mail = self::row('mx1', null, 0);
		$this->assertRefused(fn (): MailObject => self::admin()->getMailObject(7), "Mail with ID '7' not stored", ["error '[MailLogService_getMailObject] Mail with ID '7' not stored'"]);
	}

	public function testMailObjectLocalFails(): void {
		$this->mail = self::row('mx1', "{$this->dir}/outside");
		$message = "[MailLogService_getMailObjectLocal] File '{$this->dir}/outside' not found";
		$this->assertRefused(fn (): MailObject => self::admin()->getMailObject(7), $message, [
			"warning Not a file under QUARANTINE_DIR: {$this->dir}/outside",
			"error '{$message}'",
			"error '[MailLogService_getMailObject] {$message}'",
		]);
	}

	public function testMailObjectWithoutCredentials(): void {
		$this->mail = self::row('mx2', null);
		$this->setEnv('MAIL_API_PASS', 'CHANGE_ME_mail_api_pass');
		$lf = '[MailLogService_getMailObject]';
		$this->assertRefused(fn (): MailObject => self::admin()->getMailObject(7), 'Error. Contact admin', [
			"warning {$lf} MAIL_API_USER or MAIL_API_PASS not set or a published example value",
			"error '{$lf} Error. Contact admin'",
		]);
		$this->assertSame([], $this->requests);
	}

	public function testMailObjectViaApiFails(): void {
		$this->mail = self::row('mx2', null);
		$this->reply('Not Found', 404);
		$this->assertRefused(fn (): MailObject => self::admin()->getMailObject(7), 'Error. Contact admin', [
			"error [MailLogService_getMailObjectViaApi] wrong response code: 404 from API server 'mx2'. API server said: 'Not Found'",
			"error '[MailLogService_getMailObjectViaApi] problem: Error. Contact admin'",
			"error '[MailLogService_getMailObject] Error. Contact admin'",
		]);
	}
}
