<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The OpenID Connect steps up to the identity provider. With
 OPENIDC_AUTH_ENABLED off each step is refused, logged; a logout needs an
 ID token. The client is built from OPENIDC_URL, OPENIDC_CLIENT_ID and
 OPENIDC_CLIENT_SECRET, each required: here one is missing, which stops
 the step before any client exists, and the logout URL is then none,
 logged. AuthManager refuses to start a step without the redirect URL,
 and logs out only an OpenID Connect login.
*/

namespace Tests\Unit\Auth;

use App\Core\Auth\AuthManager;
use App\Core\Auth\DbAuth;
use App\Core\Auth\OpenIDConnectAuth;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Tests\Unit\UnitTestCase;

final class OpenIdFlowTest extends UnitTestCase
{
	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->setEnv('OPENIDC_AUTH_ENABLED', 'true');
		$this->setEnv('OPENIDC_URL', 'https://idp.example.org');
		$this->setEnv('OPENIDC_CLIENT_ID', 'rqwatch');
		$this->setEnv('OPENIDC_CLIENT_SECRET', null);
	}

	private function oidc(): OpenIDConnectAuth {
		return new OpenIDConnectAuth($this->log);
	}

	public function testDisabled(): void {
		$this->setEnv('OPENIDC_AUTH_ENABLED', 'false');
		$oidc = $this->oidc();
		$this->assertFalse($oidc->authenticate());
		$this->assertFalse($oidc->finishAuthentication());
		$this->assertFalse($oidc->logout('token'));
		$this->assertNull($oidc->getLogoutUrl());
		$this->assertSame(array_fill(0, 3, 'error OpenID Connect disabled'), $this->log->lines);
	}

	/** @return array<string, array{?string}> */
	public static function noTokens(): array {
		return ['none' => [null], 'empty' => [''], 'zero' => ['0']];
	}

	#[DataProvider('noTokens')]
	public function testLogoutNeedsAToken(?string $token): void {
		$this->assertFalse($this->oidc()->logout($token));
		$this->assertSame(['error Empty OpenID ID Token'], $this->log->lines);
	}

	/** @return array<string, array{string}> */
	public static function steps(): array {
		return ['authenticate' => ['authenticate'], 'finishAuthentication' => ['finishAuthentication'], 'logout' => ['logout']];
	}

	// each setting required, in order: the client is never built here
	#[DataProvider('steps')]
	public function testClientSettingsRequired(string $step): void {
		$oidc = $this->oidc();
		$run = fn (): bool => match ($step) {
			'authenticate' => $oidc->authenticate(),
			'finishAuthentication' => $oidc->finishAuthentication(),
			default => $oidc->logout('token'),
		};
		foreach (['OPENIDC_URL', 'OPENIDC_CLIENT_ID', 'OPENIDC_CLIENT_SECRET'] as $missing) {
			$this->setEnv('OPENIDC_URL', $missing === 'OPENIDC_URL' ? null : 'https://idp.example.org');
			$this->setEnv('OPENIDC_CLIENT_ID', $missing === 'OPENIDC_CLIENT_ID' ? null : 'rqwatch');
			$this->setEnv('OPENIDC_CLIENT_SECRET', null);
			try {
				$run();
				$this->fail("{$step}: no exception");
			} catch (RuntimeException $e) {
				$this->assertSame("{$missing} is not set in .env", $e->getMessage(), $step);
			}
		}
		$this->assertSame([], $this->log->lines);
	}

	public function testNoLogoutUrl(): void {
		$this->assertNull($this->oidc()->getLogoutUrl());
		$this->assertSame(['warning OPENIDC end_session_endpoint unavailable: OPENIDC_CLIENT_SECRET is not set in .env'], $this->log->lines);
	}

	public function testUrls(): void {
		$oidc = $this->oidc();
		$callback = new ReflectionMethod($oidc, 'getCallbackUrl');
		try {
			$callback->invoke($oidc);
			$this->fail('no exception');
		} catch (LogicException $e) {
			$this->assertSame('OPENIDC redirect URL not set', $e->getMessage());
		}
		$oidc->setCallbackUrl('https://rq.example.org/login/openid/callback');
		$oidc->setPostLogoutRedirectUrl('https://rq.example.org/');
		$this->assertSame('https://rq.example.org/login/openid/callback', $callback->invoke($oidc));
		$this->assertSame([
			'is_admin' => false, 'email' => null, 'mail_aliases' => [], 'firstname' => null, 'lastname' => null,
			'authenticatedUser' => null, 'callbackUrl' => 'https://rq.example.org/login/openid/callback',
			'postLogoutRedirectUrl' => 'https://rq.example.org/',
		], $oidc->__debugInfo());
	}

	// AuthManager: the redirect URL
	public function testStartNeedsUrl(): void {
		$this->assertThrows('OPENIDC redirect URL problem', fn (): bool => (new AuthManager($this->log))->startOpenIdConnectAuthentication());
		$this->assertThrows('OPENIDC redirect URL problem', fn (): bool => (new AuthManager($this->log, ''))->startOpenIdConnectAuthentication());
	}

	public function testStartDisabled(): void {
		$this->setEnv('OPENIDC_AUTH_ENABLED', null);
		$auth = new AuthManager($this->log, 'https://rq.example.org/cb');
		$this->assertFalse($auth->startOpenIdConnectAuthentication());
		$this->assertNull($auth->getAuthenticatedUser(), 'no provider set');
	}

	public function testFinish(): void {
		$this->setEnv('OPENIDC_AUTH_ENABLED', null);
		$auth = new AuthManager($this->log);
		$this->assertFalse($auth->finishOpenIdConnectAuthentication());
		$this->assertSame(['OPENIDC', 2], [$auth->getAuthProvider(), $auth->getAuthProviderId()]);
		$this->assertInstanceOf(OpenIDConnectAuth::class, (new ReflectionProperty($auth, 'provider'))->getValue($auth));
	}

	public function testLogout(): void {
		$this->assertFalse((new AuthManager($this->log))->logoutOpenIdConnect(''));
		$this->assertSame(['error Empty OpenID ID Token'], $this->log->lines);
		$this->assertThrows('OPENIDC post logout redirect URL problem', fn (): bool => (new AuthManager($this->log))->logoutOpenIdConnect('token'));

		// not an OpenID Connect login
		$auth = new AuthManager($this->log, 'https://rq.example.org/');
		$this->assertThrows('OPENIDC logout method missing', fn (): bool => $auth->logoutOpenIdConnect('token'));
		(new ReflectionProperty($auth, 'provider'))->setValue($auth, new DbAuth('alice', 'secret'));
		$this->assertThrows('OPENIDC logout method missing', fn (): bool => $auth->logoutOpenIdConnect('token'));

		$this->setEnv('OPENIDC_AUTH_ENABLED', null);
		(new ReflectionProperty($auth, 'provider'))->setValue($auth, $this->oidc());
		$this->assertFalse($auth->logoutOpenIdConnect('token'));
	}

	public function testLogoutUrl(): void {
		$this->setEnv('OPENIDC_AUTH_ENABLED', null);
		$this->assertNull((new AuthManager($this->log))->getOpenIdConnectLogoutUrl());
	}

	/** @param \Closure(): mixed $call */
	private function assertThrows(string $message, \Closure $call): void {
		try {
			$call();
		} catch (RuntimeException $e) {
			$this->assertSame([RuntimeException::class, $message], [$e::class, $e->getMessage()]);
			return;
		}
		$this->fail("No RuntimeException: {$message}");
	}
}
