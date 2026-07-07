<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Core\Auth;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use App\Utils\Helper;
use Psr\Log\LoggerInterface;

use SensitiveParameter;

use RuntimeException;

class AuthManager
{
	private ?AuthInterface $provider = null;
	private int $providerId = 0;
	private ?string $providerDescr = null;
	private ?LoggerInterface $logger;
	private ?UrlGeneratorInterface $urlGenerator = null;
	private ?string $callbackUrl = null;

	public static array $authProviders = [
		0 => 'DB',
		1 => 'LDAP',
		2 => 'OPENIDC',
	];

	public function __construct(
	   LoggerInterface $logger, 
		?UrlGeneratorInterface $urlGenerator = null,
		?string $callbackUrl = null
	) {
		$this->logger = $logger;
		$this->urlGenerator = $urlGenerator;
		$this->callbackUrl = $callbackUrl;
	}

	public function authenticate(
		string $username,
		#[SensitiveParameter] string $password
	): bool {
		$provider = $this->selectAuthProvider($username, $password);

		if (!$provider) {
			throw new RuntimeException("Authentication provider problem");
		}

		if (method_exists($provider, 'setLogger') && $this->logger) {
			// DbAuth and LdapAuth use the setLogger setter
			// API does not use AuthManager but BasicAuth directly
			// because we want to catch early constructor errors.
			// API passes logger on ther BasicAuth constructor
			$provider->setLogger($this->logger);
		} else {
			throw new RuntimeException("Logging interface problem");
		}

		if (method_exists($provider, 'authenticate') && $provider->authenticate()) {
			$this->provider = $provider;
			return true;
		}

		return false;
	}

	public function startOpenIdConnectAuthentication(): bool {
		$provider = new OpenIDConnectAuth();

		if (!$provider) {
			throw new RuntimeException("Authentication provider problem");
		}

		if (method_exists($provider, 'setLogger') && $this->logger) {
			// DbAuth and LdapAuth use the setLogger setter
			// API does not use AuthManager but BasicAuth directly
			// because we want to catch early constructor errors.
			// API passes logger on ther BasicAuth constructor
			$provider->setLogger($this->logger);
		} else {
			throw new RuntimeException("Logging interface problem");
		}

		if (method_exists($provider, 'setUrlGenerator') && $this->urlGenerator) {
			$provider->setUrlGenerator($this->urlGenerator);
		} else {
			throw new RuntimeException("Logging interface problem");
		}

		if (method_exists($provider, 'setCallbackUrl') && $this->callbackUrl) {
			$provider->setCallbackUrl($this->callbackUrl);
		} else {
			throw new RuntimeException("OPENIDC redirect URL problem");
		}

		if (method_exists($provider, 'authenticate') && $provider->authenticate()) {
			$this->provider = $provider;
			return true;
		}

		return false;
	}

	public function finishOpenIdConnectAuthentication(): bool {
		$this->providerDescr = "OPENIDC";
		$this->providerId = array_search("OPENIDC", self::$authProviders);

		$provider = new OpenIDConnectAuth();

		if (!$provider) {
			throw new RuntimeException("Authentication provider problem");
		}

		if (method_exists($provider, 'setLogger') && $this->logger) {
			// DbAuth and LdapAuth use the setLogger setter
			// API does not use AuthManager but BasicAuth directly
			// because we want to catch early constructor errors.
			// API passes logger on ther BasicAuth constructor
			$provider->setLogger($this->logger);
		} else {
			throw new RuntimeException("Logging interface problem");
		}

		if (method_exists($provider, 'setUrlGenerator') && $this->urlGenerator) {
			$provider->setUrlGenerator($this->urlGenerator);
		} else {
			throw new RuntimeException("Logging interface problem");
		}

		$this->provider = $provider;
		return $provider->finishAuthentication();
	}

	public function getAuthProvider(): ?string {
		return $this->providerDescr;
	}

	public function getAuthProviderId(): int {
		return $this->providerId;
	}

	public static function getAuthProviders(): array {
		return self::$authProviders;
	}

	public static function getAuthProviderById(int $id): string {
		if (!empty(self::$authProviders[$id])) {
			return self::$authProviders[$id];
		}
		return 'UNKNOWN';
	}

	private function selectAuthProvider(string $username, string $password): AuthInterface {
		if ($username === 'admin') {
			$this->providerDescr = "DB";
			return new DbAuth($username, $password);
		}

		if (Helper::env_bool('LDAP_AUTH_ENABLED')) {
			if (str_contains($username, '@')) {
				$this->providerDescr = "LDAP";
				$this->providerId = array_search("LDAP", self::$authProviders);
				return new LdapAuth($username, $password);
			}
		}

		// Default to DB
		$this->providerDescr = "DB";
		return new DbAuth($username, $password);
	}

	public function getAuthenticatedUser(): ?string {
		if ($this->provider && method_exists($this->provider, 'getAuthenticatedUser')) {
			return $this->provider->getAuthenticatedUser();
		}
		return null;
	}

	public function getUserId(): ?int {
		if ($this->provider && method_exists($this->provider, 'getId')) {
			return $this->provider->getId();
		}
		return null;
	}

	public function getUserEmail(): ?string {
		if ($this->provider && method_exists($this->provider, 'getEmail')) {
			return $this->provider->getEmail();
		}
		return null;
	}

	public function getUserFirstName(): ?string {
		if ($this->provider && method_exists($this->provider, 'getFirstName')) {
			return $this->provider->getFirstName();
		}
		return null;
	}

	public function getUserLastName(): ?string {
		if ($this->provider && method_exists($this->provider, 'getLastName')) {
			return $this->provider->getLastName();
		}
		return null;
	}

	public function getIsAdmin(): bool {
		if ($this->provider && method_exists($this->provider, 'getIsAdmin')) {
			return $this->provider->getIsAdmin();
		}
		return false;
	}

}
