<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Core\Auth;

use SensitiveParameter; // For method params

use Psr\Log\LoggerInterface;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

//use Jumbojett\OpenIDConnectClient;

use App\Core\Routing\RouteName;

use App\Utils\Helper;

use RuntimeException;

class OpenIDConnectAuth implements AuthInterface {
	private LoggerInterface $logger;

	private bool $is_admin = false;
	private ?string $username = null;
	private ?string $email = null;
	private array $mail_aliases = [];
	private ?string $authenticatedUser = null;
	private ?string $firstname = null;
	private ?string $lastname = null;
	private ?UrlGeneratorInterface $urlGenerator = null;
	private ?string $callbackUrl = null;
	private ?string $postLogoutRedirectUrl = null;
	private ?string $idToken = null;

	public function __construct(LoggerInterface $logger) {
		$this->logger = $logger;
	}

	public function __debugInfo(): array {
		return [
			'username' => $this->username,
			'is_admin' => $this->is_admin,
			'email' => $this->email,
			'mail_aliases' => $this->mail_aliases,
			'firstname' => $this->firstname,
			'lastname' => $this->lastname,
			'authenticatedUser' => $this->authenticatedUser,
			'callbackUrl' => $this->callbackUrl,
			'postLogoutRedirectUrl' => $this->postLogoutRedirectUrl,
		];
	}

	#[\Override]
	public function authenticate(): bool {
		if (!Helper::env_bool('OPENIDC_AUTH_ENABLED')) {
			$this->logger->error("OpenID Connect disabled");
			return false;
		}

		$oidc = $this->createClient();
		$oidc->setRedirectURL($this->getCallbackUrl());

		$oidc->authenticate();

		// We should never reach here
		throw new \LogicException('Unexpected return from authenticate().');
		return false;
	}

	public function finishAuthentication(): bool {
		if (!Helper::env_bool('OPENIDC_AUTH_ENABLED')) {
			$this->logger->error("OpenID Connect disabled");
			return false;
		}

		$oidc = $this->createClient();

		$oidc->authenticate();

		$this->idToken = $oidc->getIdToken();

		$userInfo = $oidc->requestUserInfo();

		$usernameAttr = trim($_ENV['OPENIDC_PREFERRED_USERNAME_ATTR'] ?? '') ?: 'preferred_username';

		if (Helper::env_bool('OPENIDC_DEBUG_CLAIMS', false)) {
			$this->logger->info('OIDC claims', [
				'all_claims'         => array_keys((array) $userInfo),
				'sub'                => $userInfo->sub ?? null,
				'username_attr'      => $usernameAttr,
				'username_value'     => $userInfo->{$usernameAttr} ?? null,
				'email'              => $userInfo->email ?? null,
				'email_verified'     => $userInfo->email_verified ?? null,
			]);
		}

		if (empty($userInfo) || empty($userInfo->{$usernameAttr})) {
			$this->logger->error("Empty userinfo or {$usernameAttr} from OIDC. Check OPENIDC_PREFERRED_USERNAME_ATTR in .env");
			return false;
		}

		if (!is_scalar($userInfo->{$usernameAttr})) {
			$this->logger->error("OIDC claim '{$usernameAttr}' is not a scalar value. Check OPENIDC_PREFERRED_USERNAME_ATTR in .env");
			return false;
		}

		$this->authenticatedUser = strtolower(trim((string) $userInfo->{$usernameAttr}));

		$emailVerified = filter_var(
			$userInfo->email_verified ?? null,
			FILTER_VALIDATE_BOOLEAN,
			FILTER_NULL_ON_FAILURE
		);

		if (empty($userInfo->email)) {
			$this->logger->error("OIDC user '{$this->authenticatedUser}' has no email claim; login denied");
			return false;
		}

		if (!is_scalar($userInfo->email)) {
			$this->logger->error("OIDC claim 'email' for user '{$this->authenticatedUser}' is not a scalar value; login denied");
			return false;
		}

		if ($emailVerified !== true) {
			if (Helper::env_bool('OPENIDC_REQUIRE_VERIFIED_EMAIL', true)) {
				$this->logger->warning(
					"OIDC user '{$this->authenticatedUser}' email not verified (claim: " .
					var_export($userInfo->email_verified ?? null, true) .
					"); login denied. Set OPENIDC_REQUIRE_VERIFIED_EMAIL=false to allow."
				);
				return false;
			}
			$this->logger->warning(
				"OIDC user '{$this->authenticatedUser}' email not verified; allowed by OPENIDC_REQUIRE_VERIFIED_EMAIL=false"
			);
		}

		$this->email = strtolower(trim((string) $userInfo->email));

		$this->lastname = $userInfo->family_name ?? null;
		$this->firstname = $userInfo->given_name ?? null;

		// search if user is admin
		if (array_key_exists('OPENIDC_ADMINS', $_ENV) && !empty($_ENV['OPENIDC_ADMINS'])) {
			$openidc_admins_ar = array_map(
				fn($a) => strtolower(trim($a)),
				explode(',', $_ENV['OPENIDC_ADMINS'])
			);
			if (in_array($this->authenticatedUser, $openidc_admins_ar, true)) {
				$this->is_admin = true;
			}
		}

		return true;
	}

	public function logout(?string $idToken): bool {
		if (!Helper::env_bool('OPENIDC_AUTH_ENABLED')) {
			$this->logger->error("OpenID Connect disabled");
			return false;
		}

		if (empty($idToken)) {
			$this->logger->error("Empty OpenID ID Token");
			return false;
		}

		$oidc = $this->createClient();

		$oidc->signOut($idToken, $this->postLogoutRedirectUrl);

		// unreachable
		return true;
	}

	#[\Override]
	public function getAuthenticatedUser(): string {
		if (!$this->authenticatedUser) {
			throw new RuntimeException("No user authenticated. We should not call this! (" . __METHOD__ . ")");
		}
		return $this->authenticatedUser;
	}

	public function getIsAdmin(): bool {
		if (!$this->authenticatedUser) {
			throw new RuntimeException("No user authenticated. We should not call this! (" . __METHOD__ . ")");
		}
		return $this->is_admin;
	}

	public function getEmail(): ?string {
		if (!$this->authenticatedUser) {
			throw new RuntimeException("No user authenticated. We should not call this! (" . __METHOD__ . ")");
		}
		return $this->email;
	}

	public function getEmailAliases(): array {
		if (!$this->authenticatedUser) {
			throw new RuntimeException("No user authenticated. We should not call this! (" . __METHOD__ . ")");
		}
		return $this->mail_aliases;
	}

	public function getFirstName(): ?string {
		if (!$this->authenticatedUser) {
			throw new RuntimeException("No user authenticated. We should not call this! (" . __METHOD__ . ")");
		}
		return $this->firstname;
	}

	public function getLastName(): ?string {
		if (!$this->authenticatedUser) {
			throw new RuntimeException("No user authenticated. We should not call this! (" . __METHOD__ . ")");
		}
		return $this->lastname;
	}

	public function setUrlGenerator(UrlGeneratorInterface $urlGenerator): void {
		$this->urlGenerator = $urlGenerator;
	}

	public function setCallbackUrl(string $url): void {
		$this->callbackUrl = $url;
	}

	private function getCallbackUrl(): string {
		/*
		return $this->urlGenerator->generate(
			RouteName::OPENIDC_CALLBACK->value,
			[],
			UrlGeneratorInterface::ABSOLUTE_URL
		);
		*/
		return $this->callbackUrl ?? throw new \LogicException('OPENIDC redirect URL not set');
	}

	public function setPostLogoutRedirectUrl(string $url): void {
		$this->postLogoutRedirectUrl = $url;
	}

	public function getPostLogoutRedirectUrl(string $url): string {
		return $this->postLogoutRedirectUrl ?? throw new \LogicException('OPENIDC post logout redirect URL not set');
	}

	public function getIdToken(): ?string {
		return $this->idToken;
	}

	private function createClient(): RqwatchOpenIDConnectClient {
		$openidc_url = $_ENV['OPENIDC_URL'];
		$openidc_client_id = $_ENV['OPENIDC_CLIENT_ID'];
		$openidc_client_secret = $_ENV['OPENIDC_CLIENT_SECRET'];

		$oidc = new RqwatchOpenIDConnectClient(
			$_ENV['OPENIDC_URL'],
			$_ENV['OPENIDC_CLIENT_ID'],
			$_ENV['OPENIDC_CLIENT_SECRET']
		);

		if (Helper::env_bool('OPENIDC_REQUIRE_PKCE', true)) {
			$oidc->setCodeChallengeMethod('S256');
		}

		// $oidc->setCertPath('/path/to/my.cert');
		return $oidc;
	}

	public function getLogoutUrl(): ?string {
		if (!Helper::env_bool('OPENIDC_AUTH_ENABLED')) {
			return null;
		}

		$oidc = $this->createClient();
		return $oidc->getEndSessionEndpoint();
	}

}
