<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Core\Auth;

use App\Models\User;
use SensitiveParameter; // For method params

use App\Utils\Helper;

use Psr\Log\LoggerInterface;

use RuntimeException;

class DbAuth implements AuthInterface {
	private string $username;
	private string $password;
	private bool $is_admin = false;
	private ?string $email = null;
	private ?string $authenticatedUser = null;
	private ?int $user_id = null;
	private ?LoggerInterface $logger = null;

	public function __construct(string $username, #[SensitiveParameter] string $password) {
		$this->username = $username;
		$this->password = $password;
	}

	public function __debugInfo(): array {
		return [
			'username' => $this->username,
			'password' => '***REDACTED***',
			'is_admin' => $this->is_admin,
			'email' => $this->email,
			'authenticatedUser' => $this->authenticatedUser,
			'user_id' => $this->user_id,
		];
	}

	#[\Override]
	public function authenticate(): bool {
		if (empty($this->username) or empty($this->password)) {
			return false;
		}

		$user = User::select('id', 'username', 'password', 'email', 'is_admin')
		              ->where('username', $this->username)
						  ->first();

		// user not found
		if (!$user) {
			return false;
		}

		if (Helper::passwordVerify($this->password, $user->password)) {
			$this->authenticatedUser = $user->username;
			$this->email = $user->email;
			$this->is_admin = $user->is_admin;
			$this->user_id = $user->id;
			return true; // AUTH OK
		}
		// wrong password
		return false;
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

	public function getId(): ?int {
		if (!$this->authenticatedUser) {
			throw new RuntimeException("No user authenticated. We should not call this! (" . __METHOD__ . ")");
		}
		return $this->user_id;
	}

	public function setLogger(LoggerInterface $logger): void {
		$this->logger = $logger;
	}
}
