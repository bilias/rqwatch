<?php
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU Affero General Public License version 3
as published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU Affero General Public License for more details.

You should have received a copy of the GNU Affero General Public License
along with this program.  If not, see <https://www.gnu.org/licenses/>.
*/

namespace App\Core\Auth;

use Psr\Log\LoggerInterface;

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
		if (empty($_SERVER['PHP_AUTH_USER']) or empty($_SERVER['PHP_AUTH_PW'])) {
			$this->failAuth();
		}

		if (($_SERVER['PHP_AUTH_USER'] !== $this->username) or
			 ($_SERVER['PHP_AUTH_PW'] !== $this->password)) {
			if (API_MODE) {
				$mode = " API ";
			} else {
				$mode = "";
			}
			$this->logger->warning("Failed" . $mode . "Basic Auth user: '{$_SERVER['PHP_AUTH_USER']}'");
			sleep((int)$_ENV['FAILED_LOGIN_TIMEOUT']);
			$this->failAuth();
		} else {
			$this->authenticatedUser = $_SERVER['PHP_AUTH_USER'];
			return true; // authenticated
		}
		return false;
	}

	public function getAuthenticatedUser(): string {
		if (!$this->authenticatedUser) {
			throw new \RuntimeException("No user authenticated. We should not call this! (" . __METHOD__ . ")");
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
