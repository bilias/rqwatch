<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Services;

use App\Core\App;

use App\Utils\Helper;

use App\Models\MailLogToken;

use Psr\Log\LoggerInterface;

use Throwable;

/*
 * Password-less access tokens for quarantine notification links.
 *
 * A token authorizes exactly one action pair - view and release - on exactly
 * one (mail_log_id, recipient_email) combination. It is NOT a login: nothing
 * here grants access to any other message, and the release destination is
 * always read from the token row, never from the request.
 *
 * Only the sha256 of the token is stored, so a database leak yields no usable
 * links. The plaintext exists once, in the notification mail.
 *
 * Tokens have no expiry column: a token is valid only while the quarantined
 * file is still on disk (mail_logs.mail_stored = 1). CronQuarantine clears
 * that flag and deletes the token rows when the retention period ends.
 */
class MailTokenService
{
	private const int TOKEN_BYTES = 32;

	// sha256 hex digest of the token, as stored in token_hash
	private const string TOKEN_FORMAT = '/^[a-f0-9]{64}$/';

	private LoggerInterface $logger;

	public function __construct() {
		$this->logger = App::fileLogger();
	}

	/*
	 * Whether password-less notification links are enabled.
	 */
	public static function isEnabled(): bool {
		return Helper::env_bool('NOTIFICATION_RELEASE_LINKS', false);
	}

	/*
	 * Issue a token for one recipient of one message and return the plaintext.
	 *
	 * Any previous token for the same (message, recipient) is replaced, so a
	 * re-notification invalidates the older link instead of leaving two valid.
	 *
	 * Returns null on failure. The database enforces that the pair is a real
	 * recipient of that message, so an invalid pair fails here rather than
	 * producing a link to an address that was never a recipient.
	 */
	public function issueToken(int $mailLogId, string $recipient): ?string {
		$recipient = strtolower(trim($recipient));

		if ($mailLogId <= 0 || $recipient === '') {
			$this->logger->warning(
				"[MailTokenService] refusing to issue token for mail id '{$mailLogId}' and empty recipient"
			);
			return null;
		}

		try {
			$token = bin2hex(random_bytes(self::TOKEN_BYTES));

			MailLogToken::updateOrCreate(
				[
					'mail_log_id' => $mailLogId,
					'recipient_email' => $recipient,
				],
				[
					'token_hash' => hash('sha256', $token),
				]
			);

			return $token;
		} catch (Throwable $e) {
			$this->logger->error(
				"[MailTokenService] failed issuing token for mail id {$mailLogId}: " . $e->getMessage()
			);
			return null;
		}
	}

	/*
	 * Resolve a plaintext token to its row, with the mail eager loaded.
	 *
	 * Returns null unless the token exists AND its message still has the
	 * quarantined file on disk. Callers may treat null as "invalid or
	 * expired" without distinguishing the two.
	 */
	public function findValidToken(string $token): ?MailLogToken {
		$token = trim($token);

		// keep malformed input out of the query entirely
		if (!preg_match(self::TOKEN_FORMAT, $token)) {
			return null;
		}

		try {
			return MailLogToken::with('mailLog')
				->where('token_hash', hash('sha256', $token))
				->whereHas('mailLog', function ($query) {
					$query->where('mail_stored', 1);
				})
				->first();
		} catch (Throwable $e) {
			$this->logger->error(
				"[MailTokenService] token lookup failed: " . $e->getMessage()
			);
			return null;
		}
	}

	/*
	 * Remove the token for one recipient of one message.
	 * Used after a successful release, so the link cannot be replayed.
	 */
	public function deleteToken(MailLogToken $token): bool {
		try {
			return (bool) MailLogToken::where('token_hash', $token->token_hash)->delete();
		} catch (Throwable $e) {
			$this->logger->error(
				"[MailTokenService] failed deleting token for mail id {$token->mail_log_id}: "
				. $e->getMessage()
			);
			return false;
		}
	}

	/*
	 * Remove every token for the given messages.
	 * Called from CronQuarantine once the quarantined files are deleted.
	 */
	public function deleteTokensForMails(array $mailLogIds): int {
		if (empty($mailLogIds)) {
			return 0;
		}

		try {
			return MailLogToken::whereIn('mail_log_id', $mailLogIds)->delete();
		} catch (Throwable $e) {
			$this->logger->error(
				"[MailTokenService] failed deleting tokens: " . $e->getMessage()
			);
			return 0;
		}
	}

}
