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

use App\Utils\Helper;

use SensitiveParameter;

use RuntimeException;

final class BasicAuth implements AuthInterface {
	private string $username;
	private string $password;
	private LoggerInterface $logger;

	protected ?string $authenticatedUser = null;

	public function __construct(string $username, #[SensitiveParameter] string $password, LoggerInterface $logger) {
		if (empty($username) || empty($password)) {
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
		$providedUser = Helper::server_string('PHP_AUTH_USER');
		$providedPass = Helper::server_string('PHP_AUTH_PW');
		// '' and '0' are no credentials too: fail closed
		if (in_array($providedUser, [null, '', '0'], true) || in_array($providedPass, [null, '', '0'], true)) {
			$this->doBasicAuth(); // and exit
		}

		if (!hash_equals($this->username, $providedUser) ||
			!hash_equals($this->password, $providedPass)) {
			/** @psalm-suppress RedundantCondition API_MODE is defined per entry point */
			if (API_MODE) {
				$mode = " API ";
			} else {
				$mode = "";
			}
			$this->logger->warning("Failed" . $mode . "Basic Auth user: '{$providedUser}' from '" . Helper::remote_addr() . "'");
			//sleep((int)$_ENV['FAILED_LOGIN_TIMEOUT']);
			$this->doBasicAuth(); // and exit
		} else {
			$this->authenticatedUser = $providedUser;
			return true; // auth OK
		}
		// @phpstan-ignore deadCode.unreachable (defensive: doBasicAuth() never returns)
		return false;
	}

	private static function doBasicAuth(): never {
		$response = new Response('Authentication required', 401,
		   ['WWW-Authenticate' => 'Basic']);
		$response->send();
		exit;
	}

	#[\Override]
	public function getAuthenticatedUser(): string {
		/** @psalm-suppress RiskyTruthyFalsyComparison '' and '0' are no user too: fail closed */
		if (!$this->authenticatedUser) {
			throw new RuntimeException("No user authenticated. We should not call this! (" . __METHOD__ . ")");
		}
		return $this->authenticatedUser;
	}

}
