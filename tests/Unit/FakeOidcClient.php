<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 An OpenID Connect client for the unit tests, with no identity provider:
 each call is recorded, with its arguments, and answered as set here. Its
 authenticate() returns, where the real one redirects and exits to start
 a login, or throws $failure as the real one does on a provider's error.
*/

namespace Tests\Unit;

use App\Core\Auth\OIDC\OidcClient;
use Throwable;

final class FakeOidcClient implements OidcClient
{
	/** @var list<string> each call: its name and arguments */
	public array $calls = [];

	public ?Throwable $failure = null;

	public ?string $idToken = 'eyJ.id.token';

	public mixed $userInfo = null;

	public ?Throwable $endpointFailure = null;

	public ?Throwable $signOutFailure = null;

	#[\Override]
	public function setRedirectURL(string $url): void {
		$this->calls[] = "setRedirectURL {$url}";
	}

	#[\Override]
	public function setCodeChallengeMethod(string $codeChallengeMethod): void {
		$this->calls[] = "setCodeChallengeMethod {$codeChallengeMethod}";
	}

	#[\Override]
	public function authenticate(): bool {
		$this->calls[] = 'authenticate';
		if ($this->failure !== null) {
			throw $this->failure;
		}
		return true;
	}

	#[\Override]
	public function getIdToken(): ?string {
		$this->calls[] = 'getIdToken';
		return $this->idToken;
	}

	#[\Override]
	public function requestUserInfo(?string $attribute = null): mixed {
		$this->calls[] = 'requestUserInfo' . ($attribute === null ? '' : " {$attribute}");
		return $this->userInfo;
	}

	#[\Override]
	public function signOut(string $idToken, ?string $redirect): void {
		$this->calls[] = "signOut {$idToken} " . ($redirect ?? 'null');
		if ($this->signOutFailure !== null) {
			throw $this->signOutFailure;
		}
	}

	#[\Override]
	public function getEndSessionEndpoint(): string {
		$this->calls[] = 'getEndSessionEndpoint';
		if ($this->endpointFailure !== null) {
			throw $this->endpointFailure;
		}
		return 'https://idp.example.org/logout';
	}
}
