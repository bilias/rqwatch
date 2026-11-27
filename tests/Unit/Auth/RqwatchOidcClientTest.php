<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 RqwatchOpenIDConnectClient without an identity provider: the redirect
 URL, PKCE method and ID token it is given are the library's, and the
 end_session_endpoint is the provider's, refused when not text. The userinfo request and the sign-out need the provider.
 The jumbojett library (1.0) declares parameters implicitly nullable,
 which PHP 8.4 deprecates as the file loads: those deprecations, not
 rqwatch's, are ignored here.
*/

namespace Tests\Unit\Auth;

use App\Core\Auth\OIDC\RqwatchOpenIDConnectClient;
use Jumbojett\OpenIDConnectClientException;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use Tests\Unit\UnitTestCase;

#[IgnoreDeprecations('Implicitly marking parameter \$\w+ as nullable is deprecated')]
final class RqwatchOidcClientTest extends UnitTestCase
{
	private static function client(): RqwatchOpenIDConnectClient {
		return new RqwatchOpenIDConnectClient('https://idp.example.org', 'rqwatch', 'client-secret');
	}

	public function testSettings(): void {
		$client = self::client();
		$this->assertNull($client->getIdToken());
		$client->setRedirectURL('https://rq.example.org/login/openid/callback');
		$client->setCodeChallengeMethod('S256');
		$client->setIdToken('eyJ.id.token');
		$this->assertSame(
			['https://rq.example.org/login/openid/callback', 'S256', 'eyJ.id.token'],
			[$client->getRedirectURL(), $client->getCodeChallengeMethod(), $client->getIdToken()]
		);
	}

	public function testEndSessionEndpoint(): void {
		$client = self::client();
		$client->providerConfigParam(['end_session_endpoint' => 'https://idp.example.org/logout']);
		$this->assertSame('https://idp.example.org/logout', $client->getEndSessionEndpoint());
	}

	public function testEndSessionEndpointNotText(): void {
		$client = self::client();
		$client->providerConfigParam(['end_session_endpoint' => ['https://idp.example.org/logout']]);
		$this->expectException(OpenIDConnectClientException::class);
		$this->expectExceptionMessage('end_session_endpoint is not a string');
		$client->getEndSessionEndpoint();
	}
}
