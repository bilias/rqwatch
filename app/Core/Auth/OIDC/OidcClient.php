<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Core\Auth\OIDC;

/*
 The OpenID Connect client calls OpenIDConnectAuth makes, each as the
 jumbojett client's method of that name. RqwatchOpenIDConnectClient is the
 real client; a unit test gives OpenIDConnectAuth another, so a login runs
 without an identity provider. The real authenticate() and signOut()
 redirect the browser and exit when the user must go to the provider.
*/
interface OidcClient
{
	public function setRedirectURL(string $url): void;

	public function setCodeChallengeMethod(string $codeChallengeMethod): void;

	// the provider's error, or a failed token check, throws
	public function authenticate(): bool;

	public function getIdToken(): ?string;

	// the userinfo endpoint's reply, decoded: an object from a well-behaved provider
	public function requestUserInfo(?string $attribute = null): mixed;

	public function signOut(string $idToken, ?string $redirect): void;

	public function getEndSessionEndpoint(): string;
}
