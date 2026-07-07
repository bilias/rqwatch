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

use Jumbojett\OpenIDConnectClient;

use App\Core\RouteName;

use App\Utils\Helper;

use RuntimeException;

class OpenIDConnectAuth implements AuthInterface {
	private bool $is_admin = false;
	private ?string $username = null;
	private ?string $email = null;
	private ?string $authenticatedUser = null;
	private ?string $firstname = null;
	private ?string $lastname = null;
	private ?LoggerInterface $logger = null;
	private ?UrlGeneratorInterface $urlGenerator = null;
	private ?string $callbackUrl = null;

	public function __debugInfo(): array {
		return [
			'username' => $this->username,
			'is_admin' => $this->is_admin,
			'email' => $this->email,
			'firstname' => $this->firstname,
			'lastname' => $this->lastname,
			'authenticatedUser' => $this->authenticatedUser,
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

		$userInfo = $oidc->requestUserInfo();
		if (empty($userInfo) || empty($userInfo->preferred_username)) {
			$this->logger->error("Empty userinfo or preferred_username from OIDC");
			return false;
		}
		$this->authenticatedUser = strtolower(trim($userInfo->preferred_username));

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

		$this->email = $userInfo->email ?? null;
		$this->lastname = $userInfo->family_name ?? null;
		$this->firstname = $userInfo->given_name ?? null;

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

	public function setLogger(LoggerInterface $logger): void {
		$this->logger = $logger;
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

	private function createClient(): OpenIDConnectClient {
		$openidc_url = $_ENV['OPENIDC_URL'];
		$openidc_client_id = $_ENV['OPENIDC_CLIENT_ID'];
		$openidc_client_secret = $_ENV['OPENIDC_CLIENT_SECRET'];

		$oidc = new OpenIDConnectClient(
			$_ENV['OPENIDC_URL'],
			$_ENV['OPENIDC_CLIENT_ID'],
			$_ENV['OPENIDC_CLIENT_SECRET']
		);

		if(Helper::env_bool('OPENIDC_REQUIRE_PKCE')) {
			$oidc->setCodeChallengeMethod('S256');
		}

		// $oidc->setCertPath('/path/to/my.cert');
		return $oidc;
	}

	public function getAttr(array $attrs, string $field): ?string {
		$baseAttr = strtok($field, ';');
		if (array_key_exists($baseAttr, $attrs)) {
			if (array_key_exists(0, $attrs[$baseAttr])) {
				return $attrs[$baseAttr][0];
			}
		}

		return null;
	}

}
