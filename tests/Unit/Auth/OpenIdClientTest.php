<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The OpenID Connect steps on FakeOidcClient. Each step builds a client for
 OPENIDC_URL with OPENIDC_CLIENT_ID and OPENIDC_CLIENT_SECRET, with PKCE
 (S256) unless OPENIDC_REQUIRE_PKCE is false. Starting a login sends the
 user to the provider with the callback URL; the real client then exits,
 so a return is a bug, and without a callback URL nothing is asked.
 Finishing it keeps the ID token and checks the userinfo for the username
 claim (OPENIDC_PREFERRED_USERNAME_ATTR, trimmed, preferred_username when
 blank); a provider's error is not caught. Logging out hands the ID token
 and the post-logout URL to the provider. The logout URL is the provider's
 end_session_endpoint, none (logged) when it has none. AuthManager keeps
 the OpenID Connect login, its ID token and user.
*/

namespace Tests\Unit\Auth;

use App\Core\Auth\AuthManager;
use App\Core\Auth\OIDC\OidcClient;
use App\Core\Auth\OpenIDConnectAuth;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use RuntimeException;
use Tests\Unit\FakeOidcClient;
use Tests\Unit\UnitTestCase;

final class OpenIdClientTest extends UnitTestCase
{
	private FakeOidcClient $client;

	/** @var list<list<string>> the URL, client id and secret of each client built */
	private array $built = [];

	private const string CALLBACK = 'https://rq.example.org/login/openid/callback';

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->client = new FakeOidcClient();
		$this->client->userInfo = (object) ['preferred_username' => 'Alice', 'sub' => 'a-1', 'email' => 'Alice@Example.org', 'email_verified' => true];
		(new ReflectionProperty(OpenIDConnectAuth::class, 'client'))->setValue(null, function (string $url, string $id, string $secret): OidcClient {
			$this->built[] = [$url, $id, $secret];
			return $this->client;
		});
		$this->setEnv('OPENIDC_AUTH_ENABLED', 'true');
		$this->setEnv('OPENIDC_URL', 'https://idp.example.org');
		$this->setEnv('OPENIDC_CLIENT_ID', 'rqwatch');
		$this->setEnv('OPENIDC_CLIENT_SECRET', 'client-secret');
		foreach (['OPENIDC_REQUIRE_PKCE', 'OPENIDC_PREFERRED_USERNAME_ATTR', 'OPENIDC_ADMINS', 'OPENIDC_DEBUG_CLAIMS', 'OPENIDC_REQUIRE_VERIFIED_EMAIL'] as $setting) {
			$this->setEnv($setting, null);
		}
	}

	#[\Override]
	protected function tearDown(): void {
		(new ReflectionProperty(OpenIDConnectAuth::class, 'client'))->setValue(null, null);
		parent::tearDown();
	}

	private function oidc(): OpenIDConnectAuth {
		return new OpenIDConnectAuth($this->log);
	}

	/** @return array<string, array{?string, list<string>}> OPENIDC_REQUIRE_PKCE, the client's set up */
	public static function pkce(): array {
		return [
			'by default' => [null, ['setCodeChallengeMethod S256']],
			'required' => ['true', ['setCodeChallengeMethod S256']],
			'not required' => ['false', []],
		];
	}

	/** @param list<string> $setUp */
	#[DataProvider('pkce')]
	public function testStart(?string $required, array $setUp): void {
		$this->setEnv('OPENIDC_REQUIRE_PKCE', $required);
		$oidc = $this->oidc();
		$oidc->setCallbackUrl(self::CALLBACK);
		try {
			$oidc->authenticate();
			$this->fail('no exception');
		} catch (LogicException $e) {
			$this->assertSame('Unexpected return from authenticate().', $e->getMessage());
		}
		$this->assertSame([...$setUp, 'setRedirectURL ' . self::CALLBACK, 'authenticate'], $this->client->calls);
		$this->assertSame([['https://idp.example.org', 'rqwatch', 'client-secret']], $this->built);
	}

	public function testStartWithoutCallback(): void {
		$this->expectException(LogicException::class);
		$this->expectExceptionMessage('OPENIDC redirect URL not set');
		try {
			$this->oidc()->authenticate();
		} finally {
			$this->assertSame(['setCodeChallengeMethod S256'], $this->client->calls);
		}
	}

	// AuthManager: the callback URL is its redirect URL
	public function testStartThroughAuthManager(): void {
		$this->expectException(LogicException::class);
		try {
			(new AuthManager($this->log, self::CALLBACK))->startOpenIdConnectAuthentication();
		} finally {
			$this->assertSame('setRedirectURL ' . self::CALLBACK, $this->client->calls[1] ?? null);
		}
	}

	public function testFinish(): void {
		$oidc = $this->oidc();
		$this->assertTrue($oidc->finishAuthentication());
		$this->assertSame(['alice', 'alice@example.org', 'eyJ.id.token'], [$oidc->getAuthenticatedUser(), $oidc->getEmail(), $oidc->getIdToken()]);
		$this->assertSame(['setCodeChallengeMethod S256', 'authenticate', 'getIdToken', 'requestUserInfo'], $this->client->calls);
		$this->assertSame([], $this->log->lines);
	}

	/** @return array<string, array{string, string}> OPENIDC_PREFERRED_USERNAME_ATTR, the user */
	public static function usernameClaims(): array {
		return [
			'another claim, trimmed' => [' sub ', 'a-1'],
			'blank: preferred_username' => [' ', 'alice'],
			'empty: preferred_username' => ['', 'alice'],
		];
	}

	#[DataProvider('usernameClaims')]
	public function testUsernameClaim(string $claim, string $user): void {
		$this->setEnv('OPENIDC_PREFERRED_USERNAME_ATTR', $claim);
		$oidc = $this->oidc();
		$this->assertTrue($oidc->finishAuthentication());
		$this->assertSame($user, $oidc->getAuthenticatedUser());
	}

	// the ID token is kept for the logout even when the userinfo is refused
	public function testFinishRefused(): void {
		$this->client->userInfo = (object) ['preferred_username' => 'alice'];
		$oidc = $this->oidc();
		$this->assertFalse($oidc->finishAuthentication());
		$this->assertSame('eyJ.id.token', $oidc->getIdToken());
		$this->assertSame(["error OIDC user 'alice' has no email claim; login denied"], $this->log->lines);
	}

	public function testProviderError(): void {
		$this->client->failure = new RuntimeException('Error: access_denied Description: user cancelled');
		$oidc = $this->oidc();
		try {
			$oidc->finishAuthentication();
			$this->fail('no exception');
		} catch (RuntimeException $e) {
			$this->assertSame('Error: access_denied Description: user cancelled', $e->getMessage());
		}
		$this->assertSame([null, ['setCodeChallengeMethod S256', 'authenticate']], [$oidc->getIdToken(), $this->client->calls]);
	}

	// AuthManager keeps the login, its ID token and user
	public function testFinishThroughAuthManager(): void {
		$auth = new AuthManager($this->log);
		$this->assertTrue($auth->finishOpenIdConnectAuthentication());
		$this->assertSame(['eyJ.id.token', 'alice', 'alice@example.org'], [$auth->getIdToken(), $auth->getAuthenticatedUser(), $auth->getUserEmail()]);
	}

	public function testLogout(): void {
		$oidc = $this->oidc();
		$oidc->setPostLogoutRedirectUrl('https://rq.example.org/');
		$this->assertTrue($oidc->logout('eyJ.id.token'));
		$this->assertSame(['setCodeChallengeMethod S256', 'signOut eyJ.id.token https://rq.example.org/'], $this->client->calls);
		// without a post-logout URL: the provider's own page
		$this->client = new FakeOidcClient();
		$this->assertTrue($this->oidc()->logout('eyJ.id.token'));
		$this->assertSame('signOut eyJ.id.token null', $this->client->calls[1] ?? null);
	}

	// AuthManager logs out an OpenID Connect login, to its redirect URL
	public function testLogoutThroughAuthManager(): void {
		$auth = new AuthManager($this->log, 'https://rq.example.org/');
		$this->assertTrue($auth->finishOpenIdConnectAuthentication());
		$this->client = new FakeOidcClient();
		$this->assertTrue($auth->logoutOpenIdConnect('eyJ.id.token'));
		$this->assertSame('signOut eyJ.id.token https://rq.example.org/', $this->client->calls[1] ?? null);
	}

	public function testLogoutUrl(): void {
		$this->assertSame('https://idp.example.org/logout', (new AuthManager($this->log))->getOpenIdConnectLogoutUrl());
		$this->client->endpointFailure = new RuntimeException('end_session_endpoint is not a string');
		$this->assertNull($this->oidc()->getLogoutUrl());
		$this->assertSame(['warning OPENIDC end_session_endpoint unavailable: end_session_endpoint is not a string'], $this->log->lines);
	}
}
