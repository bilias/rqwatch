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
use Sensitive;          // For properties (PHP 8.2+)

use App\Utils\Helper;
use Psr\Log\LoggerInterface;

use LDAP\Connection as LdapConnection;

use RuntimeException;

class LdapAuth implements AuthInterface {
	private string $username;
	private string $password;
	private bool $is_admin = false;
	private ?string $email = null;
	private ?string $authenticatedUser = null;
	private ?string $firstname = null;
	private ?string $lastname = null;
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
			'firstname' => $this->firstname,
			'lastname' => $this->lastname,
			'authenticatedUser' => $this->authenticatedUser,
		];
	}

	public function authenticate(): bool {
		if (empty($this->username) or empty($this->password)) {
			return false;
		}
		$ldap_uri = $_ENV['LDAP_URI']; 
		$ldap_base =  $_ENV['LDAP_BASE'];
		$ldap_bind_dn = $_ENV['LDAP_BIND_DN'] ?? '';
		$ldap_bind_pass = $_ENV['LDAP_BIND_PASS'] ?? '';
		$ldap_login_attr = $_ENV['LDAP_LOGIN_ATTR'] ?? 'mail';
		$ldap_mail_attr = $_ENV['LDAP_MAIL_ATTR'] ?? 'mail';
		$ldap_sn_attr = $_ENV['LDAP_SN_ATTR'] ?? 'sn';
		$ldap_sn_attr_fallback = $_ENV['LDAP_SN_ATTR_FALLBAK'] ?? 'sn';
		$ldap_givenname_attr = $_ENV['LDAP_GIVENNAME_ATTR'] ?? 'givenName';
		$ldap_givenname_attr_fallback = $_ENV['LDAP_GIVENNAME_ATTR_FALLBACK'] ?? 'givenName';
		$ldap_attrs = [
			$ldap_mail_attr,
			$ldap_sn_attr,
			$ldap_sn_attr_fallback,
			$ldap_givenname_attr,
			$ldap_givenname_attr_fallback,
		];

		$ldap = ldap_connect($ldap_uri);

		if (!$ldap) {
			$this->logger->error("ldap_connect failed. Check LDAP_URI");
			return false;
		}

		if (!ldap_bind($ldap, $ldap_bind_dn, $ldap_bind_pass)) {
			$error = $this->getError($ldap);
			$this->logger->error("ldap_bind failed: {$error}. Check LDAP_BIND_DN and LDAP_BIND_PASS");
			return false;
		}

		// search for user based on ldap_login_attr,
		// mail by default
		$filter = "({$ldap_login_attr}={$this->username})";

		$res = ldap_search($ldap, $ldap_base, $filter, $ldap_attrs);

		if (!$res) {
			$error = $this->getError($ldap);
			$this->logger->error("ldap_search failed: {$error}");
			return false;
		}

		$count = ldap_count_entries($ldap, $res);
		if ($count == 0) {
			$this->logger->warning("User '{$this->username}' not found in LDAP");
			return false;
		}

		// having more than 1 users with same mail will produce error
		if ($count !== 1) {
			$this->logger->error("LDAP search for {$ldap_login_attr} '{$this->username}' returned {$count} users");
			return false;
		}

		$users = ldap_get_entries($ldap, $res);

		if (!$users) {
			$error = $this->getError($ldap);
			$this->logger->error("ldap_get_entries failed: {$error}");
			return false;
		}

		$entry = ldap_first_entry($ldap, $res);

		if (!$entry) {
			$error = $this->getError($ldap);
			$this->logger->error("ldap_first_entry failed: {$error}");
			return false;
		}

		// search for user DN
		$user_dn = ldap_get_dn($ldap, $entry);

		if (!$user_dn) {
			$error = $this->getError($ldap);
			$this->logger->error("ldap_get_dn failed: {$error}");
			return false;
		}

		$mail_ar = ldap_get_values($ldap, $entry, $ldap_mail_attr);

		if (!$mail_ar) {
			$error = $this->getError($ldap);
			$this->logger->error("ldap_get_values failed: {$error}");
			return false;
		}

		// having more than 1 mail attribute produces error
		$mail_count = $mail_ar['count'];
		if ($mail_count !== 1) {
			$this->logger->error("User '{$this->username}' has {$mail_count} {$ldap_mail_attr} attributes");
			return false;
		}
		
		if (array_key_exists(0, $mail_ar)) {
			// we store this for later, so we don't search again after user bind.
			$ldap_mail = strtolower(trim($mail_ar[0]));
		} else {
			$this->logger->error("Something went wrong with mail attributes: " . print_r($mail_ar, true));
			return false;
		}

		// we now have a user DN to bind with
		if (!ldap_bind($ldap, $user_dn, $this->password)) {
			$error = $this->getError($ldap);
			$this->logger->warning("LDAP Authentication for user '{$this->username}' failed: {$error}");
			return false;
		}
		
		// Authentication OK
		// user mail from first search
		if ($this->email = $ldap_mail) {
			$this->authenticatedUser = strtolower(trim($this->username));

			// search if user is admin
			if (array_key_exists('LDAP_ADMINS', $_ENV) && !empty($_ENV['LDAP_ADMINS'])) {
				$ldap_admins_ar = array_map(
					fn($a) => strtolower(trim($a)),
					explode(',', $_ENV['LDAP_ADMINS'])
				);
				if (in_array($this->authenticatedUser, $ldap_admins_ar, true)) {
					$this->is_admin = true;
				}
			}

			$sn = $this->getAttr(ldap_get_attributes($ldap, $entry), $ldap_sn_attr);
			if (!empty($sn)) {
				$this->lastname = $sn;
			} else {
				$sn_fallback = $this->getAttr(ldap_get_attributes($ldap, $entry), $ldap_sn_attr_fallback);
				if (!empty($sn_fallback)) {
					$this->lastname = $sn_fallback;
				}
			}

			$givenName = $this->getAttr(ldap_get_attributes($ldap, $entry), $ldap_givenname_attr);
			if (!empty($givenName)) {
				$this->firstname = $givenName;
			} else {
				$givenName_fallback = $this->getAttr(ldap_get_attributes($ldap, $entry), $ldap_givenname_attr_fallback);
				if (!empty($givenName_fallback)) {
					$this->firstname = $givenName_fallback;
				}
			}

			return true;
		}
		return false;
	}

	private static function getError(LdapConnection $ldap): string {
		$ldap_error = ldap_error($ldap);
		ldap_get_option($ldap, LDAP_OPT_DIAGNOSTIC_MESSAGE, $ldap_diag);
		return "{$ldap_error}. {$ldap_diag}";
	}

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
