<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The API's Basic Auth needs both a username and a password ('0' is none),
 kept trimmed; the password never shows in a dump. No user is
 authenticated before authenticate(). A request without both, or with
 either one wrong, gets the 401 challenge; a wrong one is logged with the
 client's address, as an API failure. Here the challenge throws instead
 of exiting.
*/

namespace Tests\Unit\Auth;

use App\Core\Auth\BasicAuth;
use App\Utils\Helper;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Unit\UnitTestCase;

final class BasicAuthTest extends UnitTestCase
{
	private const array SERVER_KEYS = ['PHP_AUTH_USER', 'PHP_AUTH_PW', 'REMOTE_ADDR'];

	/** @var array<non-empty-string, ?string> as they were */
	private array $serverSaved = [];

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		foreach (self::SERVER_KEYS as $key) {
			$this->serverSaved[$key] = Helper::server_string($key);
			unset($_SERVER[$key]);
		}
		$_SERVER['REMOTE_ADDR'] = '192.0.2.7';
		self::setChallenge(static fn (): never => throw new LogicException('401 challenge'));
	}

	#[\Override]
	protected function tearDown(): void {
		self::setChallenge(null);
		foreach ($this->serverSaved as $key => $value) {
			unset($_SERVER[$key]);
			if ($value !== null) {
				$_SERVER[$key] = $value;
			}
		}
		parent::tearDown();
	}

	private static function setChallenge(?\Closure $challenge): void {
		(new ReflectionProperty(BasicAuth::class, 'challenge'))->setValue(null, $challenge);
	}

	private function auth(): BasicAuth {
		return new BasicAuth('api', 'secret', $this->log);
	}

	private static function provide(?string $user, ?string $password): void {
		unset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']);
		if ($user !== null) {
			$_SERVER['PHP_AUTH_USER'] = $user;
		}
		if ($password !== null) {
			$_SERVER['PHP_AUTH_PW'] = $password;
		}
	}

	// challenged, and no user authenticated after it
	private function assertChallenged(BasicAuth $auth): void {
		try {
			$auth->authenticate();
			$this->fail('Not challenged');
		} catch (LogicException $e) {
			$this->assertSame('401 challenge', $e->getMessage());
		}
		$this->assertNull($auth->__debugInfo()['authenticatedUser']);
	}

	/** @return array<string, array{string, string}> */
	public static function missing(): array {
		return [
			'no username' => ['', 'secret'],
			'no password' => ['api', ''],
			'username 0' => ['0', 'secret'],
			'password 0' => ['api', '0'],
			'neither' => ['', ''],
		];
	}

	#[DataProvider('missing')]
	public function testNotConfigured(string $username, string $password): void {
		try {
			new BasicAuth($username, $password, $this->log);
		} catch (HttpException $e) {
			$this->assertSame(500, $e->getStatusCode());
			$this->assertSame('API credentials not configured', $e->getMessage());
			return;
		}
		$this->fail('No HttpException');
	}

	public function testTrimmedAndRedacted(): void {
		$auth = new BasicAuth(" api\t", ' secret ', $this->log);
		$this->assertSame(['username' => 'api', 'password' => '***REDACTED***', 'authenticatedUser' => null], $auth->__debugInfo());
		$this->assertSame('secret', (new ReflectionProperty(BasicAuth::class, 'password'))->getValue($auth));
	}

	public function testNoUserBeforeAuthenticate(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('No user authenticated. We should not call this! (App\Core\Auth\BasicAuth::getAuthenticatedUser)');
		$this->auth()->getAuthenticatedUser();
	}

	public function testAuthenticated(): void {
		self::provide('api', 'secret');
		$auth = $this->auth();
		$this->assertTrue($auth->authenticate());
		$this->assertSame('api', $auth->getAuthenticatedUser());
		$this->assertSame([], $this->log->lines);
	}

	/** @return array<string, array{?string, ?string}> */
	public static function notProvided(): array {
		return [
			'no username' => [null, 'secret'],
			'empty username' => ['', 'secret'],
			'username 0' => ['0', 'secret'],
			'no password' => ['api', null],
			'empty password' => ['api', ''],
			'password 0' => ['api', '0'],
			'neither' => [null, null],
		];
	}

	// challenged before any comparison, and not logged
	#[DataProvider('notProvided')]
	public function testNotProvided(?string $user, ?string $password): void {
		self::provide($user, $password);
		$this->assertChallenged($this->auth());
		$this->assertSame([], $this->log->lines);
	}

	/** @return list<array{string, string}> user, password: either one wrong */
	private static function wrong(): array {
		return [['admin', 'secret'], ['api', 'nope'], ['admin', 'nope'], ['API', 'secret'], ['api', 'secret ']];
	}

	public function testWrong(): void {
		$expected = [];
		foreach (self::wrong() as [$user, $password]) {
			self::provide($user, $password);
			$this->assertChallenged($this->auth());
			$expected[] = "warning Failed API Basic Auth user: '{$user}' from '192.0.2.7'";
		}
		$this->assertSame($expected, $this->log->lines);
	}
}
