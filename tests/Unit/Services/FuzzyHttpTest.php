<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 FuzzyService over HTTP, through ApiClient's seam: a MockHttpClient answers.
 A mail on another node is learned, unlearned or given weight through that
 node's fuzzy_mail API: a POST with Basic Auth (MAIL_API_USER/PASS), the
 action, id, user and the flag or weight given, no redirects followed, the
 server's options over the TLS defaults. Only the API's own "Message
 <action>ed" is success; else the error names what went wrong: no url, no
 credentials, no answer, access refused (the API's text with it), the
 API's own error text, or an unexpected reply; each logged, the reply cut
 to 200 characters. Locally, rspamd's controller at fuzzy_url is POSTed the
 mail (/fuzzyadd, with flag and weight) or the hashes (/fuzzydelhash, one
 Hash header each), with RSPAMD_CONTROLLER_ENABLE_PASS as Password; its
 JSON reply is returned, a refusal or non-JSON reply is an error, logged
 with rspamd's error text. /fuzzyadd must report hashes.
*/

namespace Tests\Unit\Services;

use App\Configuration\Config;
use App\Core\Exception\FuzzyError;
use App\Core\Exception\FuzzyException;
use App\Models\MailLog;
use App\Models\MailLogFuzzy;
use App\Services\ApiClient;
use App\Services\FuzzyService;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Tests\Unit\UnitTestCase;

final class FuzzyHttpTest extends UnitTestCase
{
	private const string API = 'https://mx2.example.org/api/fuzzy_mail.php';
	private const string PASS = 'k9-unit-test-pass';

	/** @var list<MockResponse> the replies, in order */
	private array $replies = [];

	/** @var list<array{string, string, array<string, list<string>>, ?string, ?float}> method, url, headers, body, timeout */
	private array $requests = [];

	/** @var list<array<string, mixed>> the options each client was built with */
	private array $clients = [];

	private string $dir = '';

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->setEnv('MY_API_SERVER_ALIAS', 'mx1');
		$this->setEnv('MAIL_API_USER', 'rqwatch-api');
		$this->setEnv('MAIL_API_PASS', self::PASS);
		$this->setEnv('RSPAMD_CONTROLLER_ENABLE_PASS', null);
		Config::set('FUZZY_MAIL_API_PATH', '/api/fuzzy_mail.php');
		Config::set('fuzzy_learn_flags', [1 => ['label' => 'Spam']]);
		Config::set('API_SERVERS', [
			'mx1' => ['fuzzy_url' => 'http://127.0.0.1:11334/'],
			'mx2' => ['url' => 'https://mx2.example.org', 'fuzzy_url' => 'http://192.0.2.2:11334', 'options' => ['verify_peer' => false]],
			'mx3' => ['fuzzy_url' => 'http://192.0.2.3:11334'],
		]);
		(new ReflectionProperty(ApiClient::class, 'httpClient'))->setValue(null, $this->client(...));
		$this->dir = sys_get_temp_dir() . '/rqwatch-fq-' . bin2hex(random_bytes(4));
		mkdir($this->dir);
		$this->setEnv('QUARANTINE_DIR', $this->dir);
		// without a warning: a reply may lack any key
		set_error_handler(fn (int $no, string $message): never => throw new \ErrorException($message, 0, $no));
	}

	#[\Override]
	protected function tearDown(): void {
		restore_error_handler();
		(new ReflectionProperty(ApiClient::class, 'httpClient'))->setValue(null, null);
		foreach (['Q1', 'empty'] as $file) {
			@unlink("{$this->dir}/{$file}");
		}
		@rmdir($this->dir);
		parent::tearDown();
	}

	/** @param array<string, mixed> $options */
	private function client(array $options): HttpClientInterface {
		$this->clients[] = $options;
		return new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
			/** @var array<string, list<string>> $headers */
			$headers = $options['normalized_headers'] ?? [];
			/** @var mixed $body */
			$body = $options['body'] ?? null;
			/** @var mixed $timeout */
			$timeout = $options['timeout'] ?? null;
			$this->requests[] = [$method, $url, $headers, is_string($body) ? $body : null, is_float($timeout) || is_int($timeout) ? (float) $timeout : null];
			return array_shift($this->replies) ?? throw new \LogicException("No reply queued for {$url}");
		});
	}

	private function reply(string $body, int $code = 200): void {
		$this->replies[] = new MockResponse($body, ['http_code' => $code]);
	}

	private static function mail(string $server = 'mx2', ?string $location = null, ?string $qid = 'Q1'): MailLog {
		$mail = new MailLog();
		$mail->setRawAttributes(['id' => 7, 'qid' => $qid, 'server' => $server, 'mail_stored' => 1, 'mail_location' => $location]);
		return $mail;
	}

	private static function row(string $server = 'mx2', ?string $qid = 'Q1'): MailLogFuzzy {
		$row = new MailLogFuzzy();
		$row->setRawAttributes(['id' => 3, 'qid' => $qid, 'api_server' => $server]);
		return $row;
	}

	private static function call(string $method, mixed ...$arguments): mixed {
		$service = new FuzzyService();
		return (new ReflectionMethod($service, $method))->invoke($service, ...$arguments);
	}

	/** @param Closure(): mixed $call */
	private function assertRefused(Closure $call, string $message, FuzzyError $error): void {
		try {
			$call();
		} catch (FuzzyException $e) {
			$this->assertSame([$message, $error], [$e->getMessage(), $e->error]);
			return;
		}
		$this->fail('No FuzzyException');
	}

	// the remote API's request: POST, Basic Auth, the form, the client's options
	private function assertApiCall(string $body): void {
		$this->assertCount(1, $this->requests);
		[$method, $url, $headers, $sent] = $this->requests[0];
		$this->assertSame(
			['POST', self::API, ['Authorization: Basic ' . base64_encode('rqwatch-api:' . self::PASS)], $body],
			[$method, $url, $headers['authorization'] ?? null, $sent]
		);
		$this->assertSame([['verify_peer' => false, 'verify_host' => true, 'capath' => null, 'max_redirects' => 0]], $this->clients);
	}

	/** @return array<string, array{?int, string}> weight, the form sent */
	public static function learnForms(): array {
		return [
			'the flag' => [null, 'action=learn&id=7&remote_user=alice&flag=1'],
			'and a weight' => [20, 'action=learn&id=7&remote_user=alice&flag=1&weight=20'],
		];
	}

	#[DataProvider('learnForms')]
	public function testLearnRemote(?int $weight, string $form): void {
		$this->reply("Message learned\n");
		(new FuzzyService())->learn(self::mail(), 1, 'alice', $weight);
		$this->assertApiCall($form);
		$this->assertSame([], $this->log->lines);
	}

	public function testUnlearnRemote(): void {
		$this->reply('Message unlearned');
		(new FuzzyService())->unlearn(self::row(), 'alice');
		$this->assertApiCall('action=unlearn&id=3&remote_user=alice');
	}

	public function testAddWeightRemote(): void {
		$this->reply('Message boosted');
		(new FuzzyService())->addWeight(self::row(), 5, 'alice');
		$this->assertApiCall('action=boost&id=3&remote_user=alice&weight=5');
	}

	// mx3 learns (fuzzy_url) but has no API url: nothing is sent
	public function testApiWithoutUrl(): void {
		$this->assertRefused(fn () => (new FuzzyService())->learn(self::mail('mx3'), 1, 'alice'), "API server 'mx3' has no url in API_SERVERS", FuzzyError::Internal);
		$this->assertSame([["error [FuzzyService_callApi] API server 'mx3' does not exist in API_SERVERS or has an empty url. Check config.local.php"], []], [$this->log->lines, $this->requests]);
	}

	public function testApiWithoutCredentials(): void {
		$this->setEnv('MAIL_API_PASS', null);
		$this->assertRefused(fn () => (new FuzzyService())->unlearn(self::row(), 'alice'), 'MAIL_API_USER or MAIL_API_PASS not set or a published example value', FuzzyError::Internal);
		$this->assertSame([['warning [FuzzyService_callApi] MAIL_API_USER or MAIL_API_PASS not set or a published example value'], []], [$this->log->lines, $this->requests]);
	}

	public function testApiUnreachable(): void {
		$this->replies[] = new MockResponse('', ['error' => 'Connection refused']);
		$this->assertRefused(fn () => (new FuzzyService())->unlearn(self::row(), 'alice'), "API server 'mx2' is not reachable", FuzzyError::Upstream);
		$this->assertSame(["error [FuzzyService_callApi] Q1 unlearn via 'mx2' failed: Connection refused"], $this->log->lines);
	}

	/** @return array<string, array{int, string, string, FuzzyError, list<string>}> code, body, message, error, more lines logged */
	public static function apiFailures(): array {
		$access = 'warning [FuzzyService_callApi] Check remote web server access control as well as local and remote MAIL_API_USER, MAIL_API_PASS, MAIL_API_ACL, API_ENABLE';
		return [
			'200, another text' => [200, 'Message learned', "API server 'mx2' gave an unexpected reply (200)", FuzzyError::Upstream, []],
			'401, the API\'s text' => [401, 'Unauthorized user', "API server 'mx2' refused access (401): Unauthorized user", FuzzyError::Internal, [$access]],
			'403, a web page' => [403, '<html>Forbidden</html>', "API server 'mx2' refused access (403)", FuzzyError::Internal, [$access]],
			'403, nothing' => [403, '', "API server 'mx2' refused access (403)", FuzzyError::Internal, [$access]],
			'400, the API\'s error' => [400, 'Mail 3 not learned', 'Mail 3 not learned', FuzzyError::Upstream, []],
			'502, a web page' => [502, '<h1>Bad Gateway</h1>', "API server 'mx2' gave an unexpected reply (502)", FuzzyError::Upstream, []],
			'404, nothing' => [404, '', "API server 'mx2' gave an unexpected reply (404)", FuzzyError::Upstream, []],
		];
	}

	/** @param list<string> $more */
	#[DataProvider('apiFailures')]
	public function testApiFails(int $code, string $body, string $message, FuzzyError $error, array $more): void {
		$this->reply($body, $code);
		$this->assertRefused(fn () => (new FuzzyService())->unlearn(self::row(), 'alice'), $message, $error);
		$this->assertSame(["error [FuzzyService_callApi] Q1 unlearn via 'mx2' returned {$code}: '{$body}'", ...$more], $this->log->lines);
	}

	/** @return array<string, array{Closure(FuzzyService): void, string}> the call, its action */
	public static function withoutQid(): array {
		return [
			'learn' => [static fn (FuzzyService $s) => $s->learn(self::mail(qid: null), 1, 'alice'), 'learn'],
			'unlearn' => [static fn (FuzzyService $s) => $s->unlearn(self::row(qid: null), 'alice'), 'unlearn'],
			'add weight' => [static fn (FuzzyService $s) => $s->addWeight(self::row(qid: null), 5, 'alice'), 'boost'],
		];
	}

	/**
	 * a mail without a qid is named '' in the log
	 * @param Closure(FuzzyService): void $call
	 */
	#[DataProvider('withoutQid')]
	public function testApiWithoutQid(Closure $call, string $action): void {
		$this->reply('No such mail', 404);
		$this->assertRefused(fn () => $call(new FuzzyService()), 'No such mail', FuzzyError::Upstream);
		$this->assertSame(["error [FuzzyService_callApi]  {$action} via 'mx2' returned 404: 'No such mail'"], $this->log->lines);
	}

	public function testApiReplyCutInTheLog(): void {
		$this->reply(str_repeat('é', 250), 500);
		$this->assertRefused(fn () => (new FuzzyService())->unlearn(self::row(), 'alice'), str_repeat('é', 250), FuzzyError::Upstream);
		$this->assertSame(["error [FuzzyService_callApi] Q1 unlearn via 'mx2' returned 500: '" . str_repeat('é', 197) . "...'"], $this->log->lines);
	}

	public function testRspamdCall(): void {
		$this->setEnv('RSPAMD_CONTROLLER_ENABLE_PASS', 'enable-pass');
		$this->reply('{"success":true}');
		$this->assertSame(['success' => true], self::call('call', 'mx1', '/fuzzyadd', 'raw', ['Flag' => '1'], 'Q1'));
		[$method, $url, $headers, $body, $timeout] = $this->requests[0];
		$this->assertSame(
			['POST', 'http://127.0.0.1:11334/fuzzyadd', ['Flag: 1'], ['Password: enable-pass'], 'raw', 10.0],
			[$method, $url, $headers['flag'] ?? null, $headers['password'] ?? null, $body, $timeout]
		);
		$this->assertSame([['verify_peer' => true, 'verify_host' => true, 'capath' => null]], $this->clients);
	}

	// without RSPAMD_CONTROLLER_ENABLE_PASS: no Password header
	public function testRspamdCallWithoutPassword(): void {
		$this->reply('{}');
		$this->assertSame([], self::call('call', 'mx1', '/fuzzyadd', 'raw', [], 'Q1'));
		$this->assertArrayNotHasKey('password', $this->requests[0][2]);
	}

	public function testRspamdNotConfigured(): void {
		$this->assertRefused(fn (): mixed => self::call('call', 'mx9', '/fuzzyadd', 'raw', [], 'Q1'), "Fuzzy learning is not configured for 'mx9': no fuzzy_url", FuzzyError::Unavailable);
		$this->assertSame([["error [FuzzyService_call] no fuzzy_url for API server 'mx9'. Check config.local.php"], []], [$this->log->lines, $this->requests]);
	}

	public function testRspamdUnreachable(): void {
		$this->replies[] = new MockResponse('', ['error' => 'Connection refused']);
		$this->assertRefused(fn (): mixed => self::call('call', 'mx1', '/fuzzyadd', 'raw', [], 'Q1'), "rspamd controller http://127.0.0.1:11334/ for 'mx1' is not reachable", FuzzyError::Upstream);
		$this->assertSame(["error [FuzzyService_call] Q1 /fuzzyadd for 'mx1' failed: Connection refused"], $this->log->lines);
	}

	/** @return array<string, array{int, string, string, string}> code, body, message, logged */
	public static function rspamdFailures(): array {
		return [
			'refused, its error' => [403, '{"error":"Unauthorized"}', "rspamd for 'mx1' refused the request (403)", 'Unauthorized'],
			'refused, not JSON' => [500, 'Internal error', "rspamd for 'mx1' refused the request (500)", 'Internal error'],
			'refused, no error text' => [400, '{"error":7}', "rspamd for 'mx1' refused the request (400)", '{"error":7}'],
			'refused, no error' => [500, '{"success":false}', "rspamd for 'mx1' refused the request (500)", '{"success":false}'],
			'200, not JSON' => [200, 'OK', "rspamd for 'mx1' gave an unexpected reply (200)", 'OK'],
			'200, JSON text' => [200, '"OK"', "rspamd for 'mx1' gave an unexpected reply (200)", '"OK"'],
			'long, cut' => [500, str_repeat('x', 300), "rspamd for 'mx1' refused the request (500)", str_repeat('x', 197) . '...'],
		];
	}

	#[DataProvider('rspamdFailures')]
	public function testRspamdFails(int $code, string $body, string $message, string $logged): void {
		$this->reply($body, $code);
		$this->assertRefused(fn (): mixed => self::call('call', 'mx1', '/fuzzyadd', 'raw', [], 'Q1'), $message, FuzzyError::Upstream);
		$this->assertSame(["error [FuzzyService_call] Q1 /fuzzyadd for 'mx1' returned {$code}: '{$logged}'"], $this->log->lines);
	}

	public function testDeleteHashes(): void {
		$this->reply('{"success":true}');
		self::call('deleteHashes', 'mx1', [5 => str_repeat('a', 128), 9 => str_repeat('b', 128)], 2, 'Q1');
		[, $url, $headers, $body] = $this->requests[0];
		$this->assertSame(
			['http://127.0.0.1:11334/fuzzydelhash', ['Flag: 2'], ['Hash: ' . str_repeat('a', 128), 'Hash: ' . str_repeat('b', 128)], ''],
			[$url, $headers['flag'] ?? null, $headers['hash'] ?? null, $body]
		);
	}

	public function testFuzzyAdd(): void {
		file_put_contents("{$this->dir}/Q1", "Subject: spam\r\n\r\nBuy now\r\n");
		$hashes = [str_repeat('a', 128), str_repeat('b', 128)];
		$this->reply((string) json_encode(['success' => true, 'hashes' => $hashes]));
		$this->assertSame($hashes, self::call('fuzzyAdd', self::mail('mx1', "{$this->dir}/Q1"), 1, 20, '[lf]'));
		[, $url, $headers, $body] = $this->requests[0];
		$this->assertSame(
			['http://127.0.0.1:11334/fuzzyadd', ['Flag: 1'], ['Weight: 20'], "Subject: spam\r\n\r\nBuy now\r\n"],
			[$url, $headers['flag'] ?? null, $headers['weight'] ?? null, $body]
		);
	}

	/** @return array<string, array{?string, bool}> file, outside the quarantine to Helper */
	public static function unreadable(): array {
		return ['missing' => ['Q9', true], 'empty' => ['empty', false], 'no location' => [null, true]];
	}

	#[DataProvider('unreadable')]
	public function testFuzzyAddUnreadable(?string $file, bool $refused): void {
		touch("{$this->dir}/empty");
		$location = $file === null ? null : "{$this->dir}/{$file}";
		$this->assertRefused(fn (): mixed => self::call('fuzzyAdd', self::mail('mx1', $location), 1, 20, '[lf]'), 'Mail Q1 file not readable', FuzzyError::Internal);
		$this->assertSame([
			...($refused ? ["warning Not a file under QUARANTINE_DIR: {$location}"] : []),
			"error [lf] Q1 file '{$location}' not readable",
		], $this->log->lines);
		$this->assertSame([], $this->requests);
	}

	// a mail without a qid is learned all the same, named ''
	public function testFuzzyAddWithoutQid(): void {
		file_put_contents("{$this->dir}/Q1", "Subject: spam\r\n\r\nBuy now\r\n");
		$this->reply((string) json_encode(['hashes' => [str_repeat('c', 128)]]));
		$this->assertSame([str_repeat('c', 128)], self::call('fuzzyAdd', self::mail('mx1', "{$this->dir}/Q1", null), 1, 20, '[lf]'));
	}

	/** @return array<string, array{string}> */
	public static function noHashes(): array {
		return ['none' => ['{"success":true}'], 'not one' => ['{"hashes":["short"]}']];
	}

	#[DataProvider('noHashes')]
	public function testFuzzyAddWithoutHashes(string $reply): void {
		file_put_contents("{$this->dir}/Q1", "Subject: spam\r\n\r\nBuy now\r\n");
		$this->reply($reply);
		$this->assertRefused(fn (): mixed => self::call('fuzzyAdd', self::mail('mx1', "{$this->dir}/Q1"), 1, 20, '[lf]'), 'Unexpected reply from rspamd', FuzzyError::Upstream);
		$this->assertSame(["error [lf] Q1 unexpected fuzzyadd reply: {$reply}"], $this->log->lines);
	}
}
