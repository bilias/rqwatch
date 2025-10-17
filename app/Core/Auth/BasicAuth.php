<?php declare(strict_types=1);
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

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

use Psr\Log\LoggerInterface;

class BasicAuth implements AuthInterface {
	private string $username;
	private string $password;
	protected ?string $authenticatedUser = null;
	private ?LoggerInterface $logger = null;

	public function __construct(string $username, #[\SensitiveParameter] string $password, LoggerInterface $logger) {
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

	public function authenticate(): bool {
		if (empty($_SERVER['PHP_AUTH_USER']) or empty($_SERVER['PHP_AUTH_PW'])) {
			$this->doBasicAuth(); // end exit
		}

		if (($_SERVER['PHP_AUTH_USER'] !== $this->username) or
			 ($_SERVER['PHP_AUTH_PW'] !== $this->password)) {
			if (API_MODE) {
				$mode = " API ";
			} else {
				$mode = "";
			}
			$this->logger->warning("Failed" . $mode . "Basic Auth user: '{$_SERVER['PHP_AUTH_USER']}'");
			//sleep((int)$_ENV['FAILED_LOGIN_TIMEOUT']);
			$this->doBasicAuth();
		} else {
			$this->authenticatedUser = $_SERVER['PHP_AUTH_USER'];
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

	public function getAuthenticatedUser(): string {
		if (!$this->authenticatedUser) {
			throw new \RuntimeException("No user authenticated. We should not call this! (" . __METHOD__ . ")");
		}
		return $this->authenticatedUser;
	}

	// not used by API
	public function setLogger(LoggerInterface $logger): void {
		$this->logger = $logger;
	}
}
