<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 An API class's construction: with the API on, the client in its IP ACL and
 the right Basic Auth it is built, the client authenticated. A setup error
 throws, logged: an empty ACL (checked first), empty credentials ('' and
 '0' included) or a published example password. The refusals that answer
 the client (API off, client not in the ACL) are in ApiRefusalsTest, Basic
 Auth's in BasicAuthTest.
*/

namespace Tests\Unit\Api;

use App\Api\RqwatchApi;
use App\Core\Auth\BasicAuth;
use App\Utils\Helper;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Tests\Unit\UnitTestCase;

// an API class with the ACL and credentials it is given
final class RqwatchApiProbe extends RqwatchApi
{
	/**
	 * @param list<string> $ips
	 * @param array{?string, ?string} $credentials
	 */
	public function __construct(private readonly array $ips, private readonly array $credentials) {
		parent::__construct(Request::create('/api'));
	}

	#[\Override]
	public function handle(): void {
	}

	/** @return list<string> */
	#[\Override]
	protected function getAllowedIps(): array {
		return $this->ips;
	}

	/** @return array{?string, ?string} */
	#[\Override]
	protected function getAuthCredentials(): array {
		return $this->credentials;
	}

	public function runtime(): string {
		return $this->getRuntime();
	}
}

final class RqwatchApiTest extends UnitTestCase
{
	private const string CLIENT = '192.0.2.7';

	/** @var array<non-empty-string, string|null> values setServer() changed in $_SERVER, as they were (null: unset) */
	private array $serverSaved = [];

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		// every case gets past the API switch: off, it answers and exits
		$this->setEnv('API_ENABLE', 'true');
		$this->setServer('REMOTE_ADDR', self::CLIENT);
		$this->setServer('PHP_AUTH_USER', 'rspamd');
		$this->setServer('PHP_AUTH_PW', 's3cret-pass');
	}

	#[\Override]
	protected function tearDown(): void {
		foreach ($this->serverSaved as $key => $value) {
			if ($value === null) {
				unset($_SERVER[$key]);
			} else {
				$_SERVER[$key] = $value;
			}
		}
		$this->serverSaved = [];
		parent::tearDown();
	}

	public function testBuiltAndAuthenticated(): void {
		$api = new RqwatchApiProbe(['198.51.100.1', self::CLIENT], ['rspamd', 's3cret-pass']);

		$auth = (new ReflectionProperty(RqwatchApi::class, 'auth'))->getValue($api);
		$this->assertInstanceOf(BasicAuth::class, $auth);
		$this->assertSame('rspamd', $auth->getAuthenticatedUser());
		$this->assertMatchesRegularExpression('/^Execution time: \d+\.\d{3} sec \| Memory used: /', $api->runtime());
		$this->assertSame([], $this->log->lines);
	}

	// before the credentials are even looked at
	public function testEmptyAclThrows(): void {
		$msg = 'getAllowedIps() returned empty IP ACL in ' . RqwatchApiProbe::class;
		$this->assertThrows($msg, fn (): RqwatchApiProbe => new RqwatchApiProbe([], [null, null]));
		$this->assertSame(["error {$msg}"], $this->log->lines);
	}

	/** @return array<string, array{?string, ?string}> */
	public static function emptyCredentials(): array {
		return [
			'no user' => [null, 's3cret-pass'],
			'no password' => ['rspamd', null],
			'empty user' => ['', 's3cret-pass'],
			'password 0' => ['rspamd', '0'],
		];
	}

	#[DataProvider('emptyCredentials')]
	public function testEmptyCredentialsThrow(?string $user, ?string $password): void {
		$msg = 'getAuthCredentials() returned empty username or password in ' . RqwatchApiProbe::class;
		$this->assertThrows($msg, fn (): RqwatchApiProbe => new RqwatchApiProbe([self::CLIENT], [$user, $password]));
		$this->assertSame(["error {$msg}"], $this->log->lines);
	}

	// .env-example's placeholder, which contrib's metadata_exporter.conf matches
	public function testPublishedPasswordThrows(): void {
		$msg = 'Published example API password in .env for ' . RqwatchApiProbe::class . '; set your own';
		$this->assertThrows($msg, fn (): RqwatchApiProbe => new RqwatchApiProbe([self::CLIENT], ['rspamd', 'CHANGE_ME_rspamd_api_pass']));
		$this->assertSame(["critical {$msg}"], $this->log->lines);
	}

	/** @param callable(): mixed $build */
	private function assertThrows(string $message, callable $build): void {
		try {
			$build();
		} catch (RuntimeException $e) {
			$this->assertSame(RuntimeException::class, $e::class);
			$this->assertSame($message, $e->getMessage());
			return;
		}
		$this->fail("No RuntimeException: {$message}");
	}

	/**
	 * a $_SERVER value for this test only, put back in tearDown()
	 * @param non-empty-string $key
	 */
	private function setServer(string $key, string $value): void {
		if (!array_key_exists($key, $this->serverSaved)) {
			$this->serverSaved[$key] = Helper::server_string($key);
		}
		$_SERVER[$key] = $value;
	}
}
