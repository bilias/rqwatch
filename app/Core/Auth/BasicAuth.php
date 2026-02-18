<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Core\Auth;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

use Psr\Log\LoggerInterface;

use SensitiveParameter;

use RuntimeException;

class BasicAuth implements AuthInterface {
	private string $username;
	private string $password;
	protected ?string $authenticatedUser = null;
	private ?LoggerInterface $logger = null;

	public function __construct(string $username, #[SensitiveParameter] string $password, LoggerInterface $logger) {
		if (empty($username) or empty($password)) {
			throw new HttpException(500, 'API credentials not configured');
		}

		$this->username = trim($username);
		$this->password = trim($password);
		$this->logger = $logger;
	}

	public function __debugInfo(): array {
		return [
			'username' => $this->username,
			'password' => '***REDACTED***',
			'authenticatedUser' => $this->authenticatedUser,
		];
	}

	#[\Override]
	public function authenticate(): bool {
		if (empty($_SERVER['PHP_AUTH_USER']) || empty($_SERVER['PHP_AUTH_PW'])) {
			$this->doBasicAuth(); // end exit
		}

		$providedUser = (string) $_SERVER['PHP_AUTH_USER'];
		$providedPass = (string) $_SERVER['PHP_AUTH_PW'];

		if (!hash_equals($this->username, $providedUser) ||
			!hash_equals($this->password, $providedPass)) {
			if (API_MODE) {
				$mode = " API ";
			} else {
				$mode = "";
			}
			$this->logger->warning("Failed" . $mode . "Basic Auth user: '{$providedUser}' from '{$_SERVER['REMOTE_ADDR']}'");
			//sleep((int)$_ENV['FAILED_LOGIN_TIMEOUT']);
			$this->doBasicAuth();
		} else {
			$this->authenticatedUser = $providedUser;
			return true; // auth OK
		}
		return false;
	}

	private static function doBasicAuth(): void {
		$response = new Response('Authentication required', 401,
		   ['WWW-Authenticate' => 'Basic']);
		$response->send();
		exit;
	}

	#[\Override]
	public function getAuthenticatedUser(): string {
		if (!$this->authenticatedUser) {
			throw new RuntimeException("No user authenticated. We should not call this! (" . __METHOD__ . ")");
		}
		return $this->authenticatedUser;
	}

	// not used by API
	public function setLogger(LoggerInterface $logger): void {
		$this->logger = $logger;
	}
}
