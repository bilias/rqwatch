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

use LDAP\Connection as LdapConnection;

use App\Utils\Helper;

use RuntimeException;

class LdapAuth implements AuthInterface {
	private string $username;
	private string $password;
	private bool $is_admin = false;
	private ?string $email = null;
	private array $mail_aliases = [];
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
			'mail_aliases' => $this->mail_aliases,
			'firstname' => $this->firstname,
			'lastname' => $this->lastname,
			'authenticatedUser' => $this->authenticatedUser,
		];
	}

	#[\Override]
	public function authenticate(): bool {
		if (empty($this->username) || empty($this->password)) {
			return false;
		}
		$ldap_uri = $_ENV['LDAP_URI']; 
		$ldap_base =  $_ENV['LDAP_BASE'];
		$ldap_bind_dn = $_ENV['LDAP_BIND_DN'] ?? '';
		$ldap_bind_pass = $_ENV['LDAP_BIND_PASS'] ?? '';
		$ldap_login_attr = $_ENV['LDAP_LOGIN_ATTR'] ?? 'mail';
		$ldap_mail_attr = $_ENV['LDAP_MAIL_ATTR'] ?? 'mail';
		$ldap_mail_alias_attr = $_ENV['LDAP_MAIL_ALIAS_ATTR'] ?? null;
		$ldap_sn_attr = $_ENV['LDAP_SN_ATTR'] ?? 'sn';
		$ldap_givenname_attr = $_ENV['LDAP_GIVENNAME_ATTR'] ?? 'givenName';
		$ldap_sn_attr_fallback = $_ENV['LDAP_SN_ATTR_FALLBACK'] ?? null;
		$ldap_givenname_attr_fallback = $_ENV['LDAP_GIVENNAME_ATTR_FALLBACK'] ?? null;
		$ldap_attrs = [
			$ldap_mail_attr,
			$ldap_sn_attr,
			$ldap_givenname_attr,
		];

		if ($ldap_mail_alias_attr) {
			$ldap_attrs[] = $ldap_mail_alias_attr;
		}

		if ($ldap_sn_attr_fallback) {
			$ldap_attrs[] = $ldap_sn_attr_fallback;
		}

		if ($ldap_givenname_attr_fallback) {
			$ldap_attrs[] = $ldap_givenname_attr_fallback;
		}

		$ldap = ldap_connect($ldap_uri);
		if ($ldap === false) {
			$this->logger->error("ldap_connect failed. Check LDAP_URI");
			return false;
		}

		if (ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3) === false) {
			$this->logger->error("ldap_set_option(LDAP_OPT_PROTOCOL_VERSION) failed");
			return false;
		}

		if (ldap_bind($ldap, $ldap_bind_dn, $ldap_bind_pass) === false) {
			$error = $this->getError($ldap);
			$this->logger->error("ldap_bind failed: {$error}. Check LDAP_BIND_DN and LDAP_BIND_PASS");
			return false;
		}

		// search for user based on ldap_login_attr,
		// mail by default
		$filter = "({$ldap_login_attr}=".ldap_escape($this->username, "", LDAP_ESCAPE_FILTER).")";

		$res = ldap_search($ldap, $ldap_base, $filter, $ldap_attrs);

		if ($res === false) {
			$error = $this->getError($ldap);
			$this->logger->error("ldap_search failed: {$error}");
			return false;
		}

		$count = ldap_count_entries($ldap, $res);
		if ($count === -1) {
			$error = $this->getError($ldap);
			$this->logger->error("ldap_count_entries failed: {$error}");
			return false;
		}

		if ($count === 0) {
			$this->logger->warning("User '{$this->username}' not found in LDAP");
			return false;
		}

		// having more than 1 users with same mail will produce error
		if ($count !== 1) {
			$this->logger->error("LDAP search for {$ldap_login_attr} '{$this->username}' returned {$count} users");
			return false;
		}

		$entry = ldap_first_entry($ldap, $res);

		if ($entry === false) {
			$error = $this->getError($ldap);
			$this->logger->error("ldap_first_entry failed: {$error}");
			return false;
		}

		// search for user DN
		$user_dn = ldap_get_dn($ldap, $entry);

		if ($user_dn === false) {
			$error = $this->getError($ldap);
			$this->logger->error("ldap_get_dn failed: {$error}");
			return false;
		}

		$attrs = ldap_get_attributes($ldap, $entry);

		if (empty($attrs)) {
			$error = $this->getError($ldap);
			$this->logger->error("ldap_get_attributes returned empty attributes");
			return false;
		}

		$mail_ar = $this->getValues($attrs, $ldap_mail_attr);
		$mail_alias_ar = $this->getValues($attrs, $ldap_mail_alias_attr);

		if (empty($mail_ar)) {
			$this->logger->error("Empty {$ldap_mail_attr} attribute for LDAP user '{$this->username}'");
			return false;
		}

		$ldap_mail_allow_multivalue = Helper::env_bool('LDAP_MAIL_ATTR_MULTIVALUE', true);

		if (count($mail_ar) > 1 && !$ldap_mail_allow_multivalue) {
			$this->logger->error(
				"LDAP user '{$this->username}' has multiple values in '{$ldap_mail_attr}' attribute. Authentication denied", [
				'values' => $mail_ar,
				'hint' => 'Set LDAP_MAIL_ATTR_MULTIVALUE=true in .env to allow and treat additional values as aliases.',
			]);
			return false;
		}

		sort($mail_ar, SORT_STRING);
		
		if (!array_key_exists(0, $mail_ar)) {
			$this->logger->error("Something went wrong with mail attributes: " . print_r($mail_ar, true));
			return false;
		}

		// we store primary e-mail for later, so we don't search again after user bind.
		$ldap_mail = strtolower(trim($mail_ar[0]));
		unset($mail_ar[0]);

		// Combine aliases from both LDAP attributes
		$aliases = array_merge($mail_ar, $mail_alias_ar);

		// Normalize, deduplicate and remove primary mail
		$this->mail_aliases = array_values(array_unique(array_filter(
			array_map(
				fn($mail) => strtolower(trim($mail)),
				$aliases
			),
			fn($mail) => $mail !== '' && $mail !== $ldap_mail
		)));

		// we now have a user DN to bind with
		if (ldap_bind($ldap, $user_dn, $this->password) === false) {
			$error = $this->getError($ldap);
			$this->logger->warning("LDAP Authentication for user '{$this->username}' failed: {$error}");
			return false;
		}
		
		// Authentication OK
		// user mail from first search
		$this->email = $ldap_mail;

		if (empty($this->email)) {
			$this->logger->error(
				"LDAP user '{$this->username}' has empty '{$ldap_mail_attr}' attribute after authentication"
			);
			return false;
		}

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

		$sn = $this->getValue($attrs, $ldap_sn_attr);
		if (!empty($sn)) {
			$this->lastname = $sn;
		} else {
			$sn_fallback = $this->getValue($attrs, $ldap_sn_attr_fallback);
			if (!empty($sn_fallback)) {
				$this->lastname = $sn_fallback;
			}
		}

		$givenName = $this->getValue($attrs, $ldap_givenname_attr);
		if (!empty($givenName)) {
			$this->firstname = $givenName;
		} else {
			$givenName_fallback = $this->getValue($attrs, $ldap_givenname_attr_fallback);
			if (!empty($givenName_fallback)) {
				$this->firstname = $givenName_fallback;
			}
		}

		return true;
	}

	private static function getError(LdapConnection $ldap): string {
		$ldap_error = ldap_error($ldap);

		$ldap_diag = '';
		ldap_get_option($ldap, LDAP_OPT_DIAGNOSTIC_MESSAGE, $ldap_diag);
		return trim("error: '{$ldap_error}'. diag: '{$ldap_diag}'");
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

	public function setLogger(LoggerInterface $logger): void {
		$this->logger = $logger;
	}

	public function getValue(array $attrs, ?string $field): ?string {
		if (empty($field)) {
			return null;
		}

		$baseAttr = strtok($field, ';');
		if (array_key_exists($baseAttr, $attrs)) {
			if (array_key_exists(0, $attrs[$baseAttr])) {
				return trim($attrs[$baseAttr][0]);
			}
		}

		return null;
	}

	public function getValues(array $attrs, ?string $field): array {
		if (empty($field)) {
			return [];
		}

		$baseAttr = strtok($field, ';');

		if (!array_key_exists($baseAttr, $attrs)) {
			return [];
		}

		$values = $attrs[$baseAttr];

		// Remove LDAP metadata
		unset($values['count']);

		return array_values(array_filter(
			$values,
			fn($value) => is_string($value) && trim($value) !== ''
		));
	}

}
