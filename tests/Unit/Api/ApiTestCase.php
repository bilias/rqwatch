<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Base for the API tests: the API on, the client in every API's ACL with
 the right Basic Auth, and the two test seams set, so an answer (or a 401
 challenge) is thrown to the test instead of being sent before exit.
*/

namespace Tests\Unit\Api;

use App\Api\RqwatchApi;
use App\Core\Auth\BasicAuth;
use App\Utils\Helper;
use Closure;
use LogicException;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\Response;
use Tests\Unit\UnitTestCase;

abstract class ApiTestCase extends UnitTestCase
{
	protected const string CLIENT = '192.0.2.7';

	/** @var array<non-empty-string, string|null> what setServer() changed in $_SERVER, as it was (null: unset) */
	private array $serverSaved = [];

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->setEnv('API_ENABLE', 'true');
		foreach (['MAIL', 'RSPAMD'] as $api) {
			$this->setEnv("{$api}_API_ACL", '198.51.100.1, ' . self::CLIENT);
			$this->setEnv("{$api}_API_USER", 'rspamd');
			$this->setEnv("{$api}_API_PASS", 's3cret-pass');
		}
		$this->setServer('REMOTE_ADDR', self::CLIENT);
		$this->setServer('PHP_AUTH_USER', 'rspamd');
		$this->setServer('PHP_AUTH_PW', 's3cret-pass');
		$this->setServer('REQUEST_URI', '/api/test');
		self::seams(
			static fn (Response $response): never => throw new ApiAnswer($response),
			static fn (): never => throw new LogicException('401 challenge')
		);
	}

	#[\Override]
	protected function tearDown(): void {
		self::seams(null, null);
		foreach ($this->serverSaved as $key => $value) {
			unset($_SERVER[$key]);
			if ($value !== null) {
				$_SERVER[$key] = $value;
			}
		}
		$this->serverSaved = [];
		parent::tearDown();
	}

	private static function seams(?Closure $respond, ?Closure $challenge): void {
		(new ReflectionProperty(RqwatchApi::class, 'respond'))->setValue(null, $respond);
		(new ReflectionProperty(BasicAuth::class, 'challenge'))->setValue(null, $challenge);
	}

	/**
	 * a $_SERVER value for this test only (null: unset), put back in tearDown()
	 * @param non-empty-string $key
	 */
	protected function setServer(string $key, ?string $value): void {
		if (!array_key_exists($key, $this->serverSaved)) {
			$this->serverSaved[$key] = Helper::server_string($key);
		}
		unset($_SERVER[$key]);
		if ($value !== null) {
			$_SERVER[$key] = $value;
		}
	}

	// the answer $call ends in
	protected function answer(Closure $call): Response {
		try {
			$call();
		} catch (ApiAnswer $answer) {
			return $answer->response;
		}
		$this->fail('No answer');
	}

	// a plain-text answer: status and body
	protected function assertAnswer(int $status, string $body, Response $response): void {
		$this->assertSame([$status, $body], [$response->getStatusCode(), $response->getContent()]);
	}
}
