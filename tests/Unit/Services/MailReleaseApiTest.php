<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 MailLogService::releaseMailViaApi(), through ApiClient's seam: a
 MockHttpClient answers. A mail stored on another node is released by that
 node's release_mail API: a POST with Basic Auth (MAIL_API_USER/PASS) of the
 mail id, the recipients and the releasing user, no redirects followed, the
 server's options over the TLS defaults. Only the API's own "Message
 Released" (blanks around it allowed) is success. Nothing is sent without a
 mail id, an API server, recipients, a user, the credentials, or a url for
 that server. A 403 is logged with the server's text and what to check: the
 API's own "Permission denied" points at the credentials and ACL, anything
 else (a web server's page) at the web server's access control too. Any other
 reply, and a connection that fails, is logged and is a failure. Replies are
 cut to 200 characters in the log.
*/

namespace Tests\Unit\Services;

use App\Configuration\Config;
use App\Core\App;
use App\Core\AppContainer;
use App\Core\Database\MigrationStatus;
use App\Services\ApiClient;
use App\Services\MailLogService;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Tests\Unit\UnitTestCase;

final class MailReleaseApiTest extends UnitTestCase
{
	private const string LF = '[releaseMailViaApi]';
	private const string PASS = 'k9-unit-test-pass';
	private const string ACCESS = 'Check remote web server access control as well as local and remote MAIL_API_USER, MAIL_API_PASS, MAIL_API_ACL, API_ENABLE';

	/** @var list<MockResponse> the replies, in order */
	private array $replies = [];

	/** @var list<array{string, string, array<string, list<string>>, ?string}> method, url, headers, body */
	private array $requests = [];

	/** @var list<array<string, mixed>> the options each client was built with */
	private array $clients = [];

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, null, new MigrationStatus(null, $this->log), null, false));
		$this->setEnv('MAIL_API_USER', 'rqwatch-api');
		$this->setEnv('MAIL_API_PASS', self::PASS);
		Config::set('RELEASE_MAIL_API_PATH', '/api/release_mail.php');
		Config::set('API_SERVERS', [
			'mx1' => ['fuzzy_url' => 'http://127.0.0.1:11334/'],
			'mx2' => ['url' => 'https://mx2.example.org', 'options' => ['verify_peer' => false, 'max_redirects' => 5]],
			'mx3' => ['url' => 'https://mx3.example.org'],
			'mx4' => ['url' => ''],
		]);
		(new ReflectionProperty(ApiClient::class, 'httpClient'))->setValue(null, $this->client(...));
		// without a warning: a server may lack any key
		set_error_handler(fn (int $no, string $message): never => throw new \ErrorException($message, 0, $no));
	}

	#[\Override]
	protected function tearDown(): void {
		restore_error_handler();
		(new ReflectionProperty(ApiClient::class, 'httpClient'))->setValue(null, null);
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
			$this->requests[] = [$method, $url, $headers, is_string($body) ? $body : null];
			return array_shift($this->replies) ?? throw new \LogicException("No reply queued for {$url}");
		});
	}

	private function reply(string $body, int $code = 200): void {
		$this->replies[] = new MockResponse($body, ['http_code' => $code]);
	}

	/** @param list<string> $to */
	private static function release(array $to = ['alice@example.org', 'bob@example.org'], int $id = 7, string $server = 'mx2', string $user = 'carol@example.org'): bool {
		return (new MailLogService())->releaseMailViaApi($to, $id, $server, $user);
	}

	/** @return array<string, array{string, string, array<string, mixed>}> server, url, client options */
	public static function servers(): array {
		return [
			'its options over the defaults, no redirects' => ['mx2', 'https://mx2.example.org/api/release_mail.php', ['verify_peer' => false, 'verify_host' => true, 'capath' => null, 'max_redirects' => 0]],
			'no options of its own' => ['mx3', 'https://mx3.example.org/api/release_mail.php', ['verify_peer' => true, 'verify_host' => true, 'capath' => null, 'max_redirects' => 0]],
		];
	}

	/** @param array<string, mixed> $options */
	#[DataProvider('servers')]
	public function testReleased(string $server, string $url, array $options): void {
		$this->reply("  Message Released\n");
		$this->assertTrue(self::release(server: $server));
		$this->assertSame([], $this->log->lines);
		$this->assertSame([$options], $this->clients);
		$this->assertCount(1, $this->requests);
		[$method, $sent, $headers, $body] = $this->requests[0];
		$this->assertSame(
			[
				'POST',
				$url,
				['Authorization: Basic ' . base64_encode('rqwatch-api:' . self::PASS)],
				http_build_query(['id' => 7, 'email' => ['alice@example.org', 'bob@example.org'], 'remote_user' => 'carol@example.org']),
			],
			[$method, $sent, $headers['authorization'] ?? null, $body]
		);
	}

	/** @return array<string, array{list<string>, int, string, string, string}> recipients, id, server, user, the error */
	public static function missing(): array {
		return [
			'no mail id' => [['alice@example.org'], 0, 'mx2', 'carol@example.org', 'empty mail id'],
			'no API server' => [['alice@example.org'], 7, '', 'carol@example.org', 'empty api server'],
			'no recipients' => [[], 7, 'mx2', 'carol@example.org', 'empty recipients'],
			'no user' => [['alice@example.org'], 7, 'mx2', '', 'empty local user email'],
		];
	}

	/** @param list<string> $to */
	#[DataProvider('missing')]
	public function testNothingSentWithout(array $to, int $id, string $server, string $user, string $error): void {
		$this->assertFalse(self::release($to, $id, $server, $user));
		$this->assertSame([['error ' . self::LF . " {$error}"], [], []], [$this->log->lines, $this->clients, $this->requests]);
	}

	public function testNothingSentWithoutCredentials(): void {
		$this->setEnv('MAIL_API_PASS', 'CHANGE_ME_mail_api_pass');
		$this->assertFalse(self::release());
		$this->assertSame(
			[['warning ' . self::LF . ' MAIL_API_USER or MAIL_API_PASS not set or a published example value'], [], []],
			[$this->log->lines, $this->clients, $this->requests]
		);
	}

	/** @return array<string, array{string}> */
	public static function withoutUrl(): array {
		return [
			'not in API_SERVERS' => ['mx9'],
			'no url' => ['mx1'],
			'an empty url' => ['mx4'],
		];
	}

	#[DataProvider('withoutUrl')]
	public function testNothingSentWithoutUrl(string $server): void {
		$this->assertFalse(self::release(server: $server));
		$this->assertSame(
			[['error ' . self::LF . " API server '{$server}' does not exist in API_SERVERS or has an empty url. Check config.local.php"], [], []],
			[$this->log->lines, $this->clients, $this->requests]
		);
	}

	/** @return array<string, array{int, string, list<string>}> code, body, lines logged */
	public static function failures(): array {
		$long = str_repeat('é', 250);
		$cut = str_repeat('é', 197) . '...';
		return [
			'200, another text' => [200, 'Message released', ['error ' . self::LF . " unexpected reply from API server 'mx2': 200 'Message released'"]],
			'200, a long text' => [200, $long, ['error ' . self::LF . " unexpected reply from API server 'mx2': 200 '{$cut}'"]],
			'403, the API\'s refusal' => [403, 'Permission denied', [
				'error ' . self::LF . " wrong response code: 403 Forbidden, from API server 'mx2'. API server said: 'Permission denied'",
				'warning ' . self::LF . ' Check local and remote MAIL_API_USER, MAIL_API_PASS, MAIL_API_ACL',
			]],
			'403, a web page' => [403, $long, [
				'error ' . self::LF . " wrong response code: 403 Forbidden, from API server 'mx2'. API server said: '{$cut}'",
				'warning ' . self::LF . ' ' . self::ACCESS,
			]],
			'404, not found' => [404, 'Not Found', ['error ' . self::LF . " wrong response code: 404 from API server 'mx2'. API server said: 'Not Found'"]],
			'500, a long text' => [500, $long, ['error ' . self::LF . " wrong response code: 500 from API server 'mx2'. API server said: '{$cut}'"]],
			'302, not followed' => [302, '', ['error ' . self::LF . " wrong response code: 302 from API server 'mx2'. API server said: ''"]],
		];
	}

	/** @param list<string> $lines */
	#[DataProvider('failures')]
	public function testReleaseFails(int $code, string $body, array $lines): void {
		$this->reply($body, $code);
		$this->assertFalse(self::release());
		$this->assertSame($lines, $this->log->lines);
		$this->assertCount(1, $this->requests);
	}

	public function testUnreachable(): void {
		$this->replies[] = new MockResponse('', ['error' => 'Connection refused']);
		$this->assertFalse(self::release());
		$this->assertSame(['error ' . self::LF . ' problem: Connection refused'], $this->log->lines);
	}
}
