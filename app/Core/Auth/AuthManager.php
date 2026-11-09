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

final class AuthManager
{
	private ?AuthInterface $provider = null;
	/** @var int<0, 2> key of $authProviders */
	private int $providerId = self::PROVIDER_DB;
	private ?string $providerDescr = null;
	private LoggerInterface $logger;
	private ?UrlGeneratorInterface $urlGenerator = null;
	private ?string $redirectUrl = null;

	// keys of $authProviders, as stored in users.auth_provider
	private const int PROVIDER_DB = 0;
	private const int PROVIDER_LDAP = 1;
	private const int PROVIDER_OPENIDC = 2;

	/** @var array{0: 'DB', 1: 'LDAP', 2: 'OPENIDC'} */
	public static array $authProviders = [
		self::PROVIDER_DB => 'DB',
		self::PROVIDER_LDAP => 'LDAP',
		self::PROVIDER_OPENIDC => 'OPENIDC',
	];

	public function __construct(
	   LoggerInterface $logger, 
		?UrlGeneratorInterface $urlGenerator = null,
		?string $redirectUrl = null
	) {
		$this->logger = $logger;
		$this->urlGenerator = $urlGenerator;
		$this->redirectUrl = $redirectUrl;
	}

	public function authenticate(
		string $username,
		#[SensitiveParameter] string $password
	): bool {
		$provider = $this->selectAuthProvider($username, $password, $this->logger);

		if ($provider->authenticate()) {
			$this->provider = $provider;
			return true;
		}

		return false;
	}

	public function startOpenIdConnectAuthentication(): bool {
		$provider = new OpenIDConnectAuth($this->logger);

		if ($this->urlGenerator) {
			$provider->setUrlGenerator($this->urlGenerator);
		} else {
			throw new RuntimeException("Logging interface problem");
		}

		/** @psalm-suppress RiskyTruthyFalsyComparison '' and '0' are no URL: fail closed */
		if ($this->redirectUrl) {
			$provider->setCallbackUrl($this->redirectUrl);
		} else {
			throw new RuntimeException("OPENIDC redirect URL problem");
		}

		if ($provider->authenticate()) {
			$this->provider = $provider;
			return true;
		}

		return false;
	}

	public function finishOpenIdConnectAuthentication(): bool {
		$this->providerDescr = "OPENIDC";
		$this->providerId = self::PROVIDER_OPENIDC;

		$provider = new OpenIDConnectAuth($this->logger);

		if ($this->urlGenerator) {
			$provider->setUrlGenerator($this->urlGenerator);
		} else {
			throw new RuntimeException("Logging interface problem");
		}

		$this->provider = $provider;
		return $provider->finishAuthentication();
	}

	public function logoutOpenIdConnect(?string $idToken): bool {
		/** @psalm-suppress RiskyTruthyFalsyComparison '' and '0' are no token too: fail closed */
		if (empty($idToken)) {
			$this->logger->error("Empty OpenID ID Token");
			return false;
		}

		$provider = new OpenIDConnectAuth($this->logger);

		if ($this->urlGenerator) {
			$provider->setUrlGenerator($this->urlGenerator);
		} else {
			throw new RuntimeException("Logging interface problem");
		}

		/** @psalm-suppress RiskyTruthyFalsyComparison '' and '0' are no URL: fail closed */
		if ($this->redirectUrl) {
			$provider->setPostLogoutRedirectUrl($this->redirectUrl);
		} else {
			throw new RuntimeException("OPENIDC post logout redirect URL problem");
		}

		if (method_exists($provider, 'logout')) {
			return $provider->logout($idToken);
		}

		// unreachable
		throw new RuntimeException("OPENIDC logout method missing");
	}

	public function getOpenIdConnectLogoutUrl(): ?string {
		$provider = new OpenIDConnectAuth($this->logger);
		if ($this->urlGenerator) {
			$provider->setUrlGenerator($this->urlGenerator);
		} else {
			throw new RuntimeException("Logging interface problem");
		}

		return $provider->getLogoutUrl();
	}

	public function getAuthProvider(): ?string {
		return $this->providerDescr;
	}

	/** @return int<0, 2> */
	public function getAuthProviderId(): int {
		return $this->providerId;
	}

	/** @return array<int, string> */
	public static function getAuthProviders(): array {
		return self::$authProviders;
	}

	public static function getAuthProviderById(int $id): string {
		if (!empty(self::$authProviders[$id])) {
			return self::$authProviders[$id];
		}
		return 'UNKNOWN';
	}

	// admin per LDAP_ADMINS / OPENIDC_ADMINS; null for other providers
	public static function isExternalAdmin(string $provider, string $username): ?bool {
		$var = match ($provider) {
			'LDAP' => 'LDAP_ADMINS',
			'OPENIDC' => 'OPENIDC_ADMINS',
			default => null,
		};
		if ($var === null) {
			return null;
		}
		$admins = array_map(
			fn($a) => strtolower(trim($a)),
			explode(',', Helper::env_string($var) ?? '')
		);
		return in_array(strtolower(trim($username)), $admins, true);
	}

	private function selectAuthProvider(
		string $username,
		string $password,
		LoggerInterface $logger
	): AuthInterface {

		if ($username === 'admin') {
			$this->providerDescr = "DB";
			return new DbAuth($username, $password);
		}

		if (Helper::env_bool('LDAP_AUTH_ENABLED') && str_contains($username, '@')) {
			$this->providerDescr = "LDAP";
			$this->providerId = self::PROVIDER_LDAP;
			return new LdapAuth($username, $password, $logger);
		}

		// Default to DB
		$this->providerDescr = "DB";
		return new DbAuth($username, $password);
	}

	public function getIdToken(): ?string {
		if ($this->provider instanceof OpenIDConnectAuth) {
			return $this->provider->getIdToken();
		}
		return null;
	}

	public function getAuthenticatedUser(): ?string {
		if ($this->provider) {
			return $this->provider->getAuthenticatedUser();
		}
		return null;
	}

	public function getUserId(): ?int {
		if ($this->provider instanceof DbAuth) {
			return $this->provider->getId();
		}
		return null;
	}

	public function getUserEmail(): ?string {
		if ($this->provider instanceof DbAuth || $this->provider instanceof LdapAuth || $this->provider instanceof OpenIDConnectAuth) {
			return $this->provider->getEmail();
		}
		return null;
	}

	/** @return list<string> */
	public function getUserEmailAliases(): array {
		if ($this->provider instanceof LdapAuth || $this->provider instanceof OpenIDConnectAuth) {
			return $this->provider->getEmailAliases();
		}
		return [];
	}

	public function getUserFirstName(): ?string {
		if ($this->provider instanceof LdapAuth || $this->provider instanceof OpenIDConnectAuth) {
			return $this->provider->getFirstName();
		}
		return null;
	}

	public function getUserLastName(): ?string {
		if ($this->provider instanceof LdapAuth || $this->provider instanceof OpenIDConnectAuth) {
			return $this->provider->getLastName();
		}
		return null;
	}

	public function getIsAdmin(): bool {
		if ($this->provider instanceof DbAuth || $this->provider instanceof LdapAuth || $this->provider instanceof OpenIDConnectAuth) {
			return $this->provider->getIsAdmin();
		}
		return false;
	}

}
