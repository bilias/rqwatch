<?php
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Core\Auth;

use Psr\Log\LoggerInterface;

use RuntimeException;

class CustomBasicAuth implements AuthInterface {
	protected string $username;
	protected string $password;
	protected ?string $authenticatedUser = null;
	private ?LoggerInterface $logger = null;

	public function __construct() {
		if (empty($_ENV['API_USER']) or empty($_ENV['API_PASS'])) {	
			http_response_code(500);
			header('Content-Type: text/plain');
			echo "Internal Server Error: Something went wrong.";
			exit;
		}

		$this->username = trim($_ENV['API_USER']);
		$this->password = trim($_ENV['API_PASS']);
	}

	public function authenticate(): bool {
		if (empty($_SERVER['PHP_AUTH_USER']) || empty($_SERVER['PHP_AUTH_PW'])) {
			$this->failAuth();
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
			sleep((int)$_ENV['FAILED_LOGIN_TIMEOUT']);
			$this->failAuth();
		} else {
			$this->authenticatedUser = $providedUser;
			return true; // authenticated
		}
		return false;
	}

	public function getAuthenticatedUser(): string {
		if (!$this->authenticatedUser) {
			throw new RuntimeException("No user authenticated. We should not call this! (" . __METHOD__ . ")");
		}
		return $this->authenticatedUser;
	}

	protected static function failAuth(): void {
		header($_SERVER["SERVER_PROTOCOL"]. ' 401 Unauthorized');
		header('WWW-Authenticate: Basic realm="Restricted API Access"');
		echo "Access denied.\n";
		exit;
	}

	public function setLogger(LoggerInterface $logger): void {
		$this->logger = $logger;
	}
}
