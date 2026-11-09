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

final class LdapAuth implements AuthInterface {
	private string $username;
	private string $password;
	private LoggerInterface $logger;

	private bool $is_admin = false;
	private ?string $email = null;
	/** @var list<string> */
	private array $mail_aliases = [];
	private ?string $authenticatedUser = null;
	private ?string $firstname = null;
	private ?string $lastname = null;

	public function __construct(
		string $username,
		#[SensitiveParameter] string $password,
		LoggerInterface $logger
	) {
		$this->username = $username;
		$this->password = $password;
		$this->logger = $logger;
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
		$ldap_uri = Helper::env_required('LDAP_URI'); 
		$ldap_base =  Helper::env_required('LDAP_BASE');
		$ldap_bind_dn = Helper::env_string('LDAP_BIND_DN') ?? '';
		$ldap_bind_pass = Helper::env_string('LDAP_BIND_PASS') ?? '';
		$ldap_login_attr = Helper::env_string('LDAP_LOGIN_ATTR') ?? 'mail';
		$ldap_mail_attr = Helper::env_string('LDAP_MAIL_ATTR') ?? 'mail';
		$ldap_mail_alias_attr = Helper::env_string('LDAP_MAIL_ALIAS_ATTR');
		$ldap_sn_attr = Helper::env_string('LDAP_SN_ATTR') ?? 'sn';
		$ldap_givenname_attr = Helper::env_string('LDAP_GIVENNAME_ATTR') ?? 'givenName';
		$ldap_sn_attr_fallback = Helper::env_string('LDAP_SN_ATTR_FALLBACK');
		$ldap_givenname_attr_fallback = Helper::env_string('LDAP_GIVENNAME_ATTR_FALLBACK');
		$ldap_attrs = [
			$ldap_mail_attr,
			$ldap_sn_attr,
			$ldap_givenname_attr,
		];

		if ($ldap_mail_alias_attr !== null && $ldap_mail_alias_attr !== '') {
			$ldap_attrs[] = $ldap_mail_alias_attr;
		}

		if ($ldap_sn_attr_fallback !== null && $ldap_sn_attr_fallback !== '') {
			$ldap_attrs[] = $ldap_sn_attr_fallback;
		}

		if ($ldap_givenname_attr_fallback !== null && $ldap_givenname_attr_fallback !== '') {
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

		/** @var \LDAP\Result|false $res one connection, so never the parallel-search array */
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
			$this->logger->warning(
				"LDAP user '{$this->username}' has multiple values in '{$ldap_mail_attr}' attribute. Authentication denied", [
				'values' => $mail_ar,
				'hint' => 'Set LDAP_MAIL_ATTR_MULTIVALUE=true in .env to allow and treat additional values as aliases.',
			]);
			return false;
		}

		sort($mail_ar, SORT_STRING);

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

		$this->is_admin = AuthManager::isExternalAdmin('LDAP', $this->authenticatedUser) === true;

		$sn = $this->getValue($attrs, $ldap_sn_attr);
		if ($sn !== null && $sn !== '') {
			$this->lastname = $sn;
		} else {
			$sn_fallback = $this->getValue($attrs, $ldap_sn_attr_fallback);
			if ($sn_fallback !== null && $sn_fallback !== '') {
				$this->lastname = $sn_fallback;
			}
		}

		$givenName = $this->getValue($attrs, $ldap_givenname_attr);
		if ($givenName !== null && $givenName !== '') {
			$this->firstname = $givenName;
		} else {
			$givenName_fallback = $this->getValue($attrs, $ldap_givenname_attr_fallback);
			if ($givenName_fallback !== null && $givenName_fallback !== '') {
				$this->firstname = $givenName_fallback;
			}
		}

		return true;
	}

	private static function getError(LdapConnection $ldap): string {
		$ldap_error = ldap_error($ldap);

		$ldap_diag = '';
		ldap_get_option($ldap, LDAP_OPT_DIAGNOSTIC_MESSAGE, $ldap_diag);
		$ldap_diag = is_string($ldap_diag) ? $ldap_diag : '';
		return trim("error: '{$ldap_error}'. diag: '{$ldap_diag}'");
	}

	#[\Override]
	public function getAuthenticatedUser(): string {
		/** @psalm-suppress RiskyTruthyFalsyComparison '' and '0' are no user too: fail closed */
		if (!$this->authenticatedUser) {
			throw new RuntimeException("No user authenticated. We should not call this! (" . __METHOD__ . ")");
		}
		return $this->authenticatedUser;
	}

	public function getIsAdmin(): bool {
		/** @psalm-suppress RiskyTruthyFalsyComparison '' and '0' are no user too: fail closed */
		if (!$this->authenticatedUser) {
			throw new RuntimeException("No user authenticated. We should not call this! (" . __METHOD__ . ")");
		}
		return $this->is_admin;
	}

	public function getEmail(): ?string {
		/** @psalm-suppress RiskyTruthyFalsyComparison '' and '0' are no user too: fail closed */
		if (!$this->authenticatedUser) {
			throw new RuntimeException("No user authenticated. We should not call this! (" . __METHOD__ . ")");
		}
		return $this->email;
	}

	/** @return list<string> */
	public function getEmailAliases(): array {
		/** @psalm-suppress RiskyTruthyFalsyComparison '' and '0' are no user too: fail closed */
		if (!$this->authenticatedUser) {
			throw new RuntimeException("No user authenticated. We should not call this! (" . __METHOD__ . ")");
		}
		return $this->mail_aliases;
	}

	public function getFirstName(): ?string {
		/** @psalm-suppress RiskyTruthyFalsyComparison '' and '0' are no user too: fail closed */
		if (!$this->authenticatedUser) {
			throw new RuntimeException("No user authenticated. We should not call this! (" . __METHOD__ . ")");
		}
		return $this->firstname;
	}

	public function getLastName(): ?string {
		/** @psalm-suppress RiskyTruthyFalsyComparison '' and '0' are no user too: fail closed */
		if (!$this->authenticatedUser) {
			throw new RuntimeException("No user authenticated. We should not call this! (" . __METHOD__ . ")");
		}
		return $this->lastname;
	}

	/**
	 * The values of attribute description $field (sn, sn;lang-en) in an
	 * ldap_get_attributes() result, null if the entry has none. The keys are
	 * the descriptions as the server returned them, options included and in
	 * the server's case, so they are matched whole and case-insensitively; a
	 * tagged description does not fall back to its base type (that is what the
	 * *_FALLBACK settings are for). Only the name => values entries are arrays,
	 * not 'count' or the numbered list of names.
	 *
	 * @param array<array-key, mixed> $attrs
	 * @return array<array-key, mixed>|null
	 */
	private static function attrValues(array $attrs, ?string $field): ?array {
		if ($field === null || $field === '') {
			return null;
		}
		foreach ($attrs as $name => $values) {
			if (is_string($name) && is_array($values) && strcasecmp($name, $field) === 0) {
				return $values;
			}
		}
		return null;
	}

	/** @param array<array-key, mixed> $attrs ldap_get_attributes() result */
	public function getValue(array $attrs, ?string $field): ?string {
		$values = self::attrValues($attrs, $field);
		if ($values !== null && array_key_exists(0, $values) && is_string($values[0])) {
			return trim($values[0]);
		}

		return null;
	}

	/**
	 * @param array<array-key, mixed> $attrs ldap_get_attributes() result
	 * @return list<string>
	 */
	public function getValues(array $attrs, ?string $field): array {
		$values = self::attrValues($attrs, $field);
		if ($values === null) {
			return [];
		}

		// Remove LDAP metadata
		unset($values['count']);

		return array_values(array_filter(
			$values,
			fn(mixed $value): bool => is_string($value) && trim($value) !== ''
		));
	}

}
