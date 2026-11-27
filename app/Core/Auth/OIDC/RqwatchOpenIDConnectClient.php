<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Core\Auth\OIDC;

use Jumbojett\OpenIDConnectClient;
use Jumbojett\OpenIDConnectClientException;

/*
 The jumbojett client as an OidcClient: the methods the library leaves
 untyped get the types its documentation gives, and the provider's
 end_session_endpoint, which it keeps protected.
*/
/** @psalm-suppress PropertyNotSetInConstructor $accessToken and $idToken are the library's, set by authenticate() */
final class RqwatchOpenIDConnectClient extends OpenIDConnectClient implements OidcClient
{
	#[\Override]
	public function setRedirectURL(string $url): void {
		parent::setRedirectURL($url);
	}

	#[\Override]
	public function setCodeChallengeMethod(string $codeChallengeMethod): void {
		parent::setCodeChallengeMethod($codeChallengeMethod);
	}

	#[\Override]
	public function getIdToken(): ?string {
		return parent::getIdToken();
	}

	#[\Override]
	public function requestUserInfo(?string $attribute = null): mixed {
		return parent::requestUserInfo($attribute);
	}

	/** @param string|null $redirect untyped, as the library's */
	#[\Override]
	public function signOut(string $idToken, $redirect): void {
		parent::signOut($idToken, $redirect);
	}

	#[\Override]
	public function getEndSessionEndpoint(): string {
		$value = $this->getProviderConfigValue('end_session_endpoint');
		if (!is_string($value)) {
			throw new OpenIDConnectClientException('end_session_endpoint is not a string');
		}
		return $value;
	}
}
