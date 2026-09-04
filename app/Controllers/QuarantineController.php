<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Controllers;

use App\Models\MailLogToken;

use App\Services\MailLogService;
use App\Services\MailTokenService;

use App\Utils\Helper;

use Symfony\Component\HttpFoundation\Response;

use Exception;

/*
 * Password-less quarantine access from notification mails.
 *
 * All three actions are public: the token in the URL is the only credential.
 * A token authorizes view and release of exactly one message for exactly one
 * recipient - it is not a login and grants nothing else.
 *
 * confirm() is a plain GET because mail security appliances and clients
 * prefetch links in email. It renders boilerplate only and performs no
 * action. view() and release() are POST + CSRF so a prefetch cannot trigger
 * them.
 */
class QuarantineController extends ViewController
{
	/*
	 * Step 1: emailed link lands here. Safe for link scanners to fetch.
	 * Shows nothing about the message, only a button to proceed.
	 */
	public function confirm(string $token): Response {
		if (!MailTokenService::isEnabled()) {
			return $this->tokenError();
		}

		// Read-only validation so an expired link is reported before the
		// user clicks, rather than after.
		if ($this->getTokenService()->findValidToken($token) === null) {
			return $this->tokenError();
		}

		// enable form rendering support (needed for csrf_token() in templates)
		$this->twigFormView($this->request);

		return new Response($this->twig->render('quarantine/confirm.twig', [
			'token' => $token,
		]));
	}

	/*
	 * Step 2: show the held message. POST only.
	 */
	public function view(string $token): Response {
		[$tokenRow, $error] = $this->resolveToken($token, 'token_view');

		if ($tokenRow === null) {
			return $error;
		}

		$service = $this->getScopedService($tokenRow);

		try {
			$mailobject = $service->getMailObject($tokenRow->mail_log_id);
		} catch (Exception $e) {
			$this->fileLogger->warning(
				"[QuarantineController] view failed for mail id {$tokenRow->mail_log_id}: "
				. $e->getMessage()
			);
			return $this->tokenError();
		}

		$this->twigFormView($this->request);

		return new Response($this->twig->render('quarantine/view.twig', [
			'token' => $token,
			'recipient' => $tokenRow->recipient_email,
			'log' => $mailobject->getMailLog(),
			'textBody' => $mailobject->getTextBody(),
			'htmlBody' => Helper::normalizeToUtf8($mailobject->getHtmlBody()),
			'virus_found' => $mailobject->getVirusFound(),
			//'released' => (bool) $mailobject->getMailLog()->released,
			//'symbols' => $mailobject->getSymbols(),
		]));
	}

	/*
	 * Step 3: release the message to the token's recipient. POST only.
	 *
	 * The destination comes from the token row, never from the request, so a
	 * token holder cannot redirect mail to an address of their choosing.
	 */
	public function release(string $token): Response {
		[$tokenRow, $error] = $this->resolveToken($token, 'token_release');

		if ($tokenRow === null) {
			return $error;
		}

		$service = $this->getScopedService($tokenRow);

		try {
			$mailobject = $service->getMailObject($tokenRow->mail_log_id);
			$maillog = $mailobject->getMailLog();
		} catch (Exception $e) {
			$this->fileLogger->warning(
				"[QuarantineController] release failed for mail id {$tokenRow->mail_log_id}: "
				. $e->getMessage()
			);
			return $this->tokenError();
		}

		/*
		if ($maillog->released) {
			return $this->tokenMessage(
				'This message has already been released to your mailbox.'
			);
		}
		*/

		// destination is the token's recipient only
		if (!$service->releaseHtmlMail([$tokenRow->recipient_email], $maillog, $this->twig)) {
			$this->fileLogger->error(
				"[QuarantineController] release of mail id {$tokenRow->mail_log_id} to "
				. "{$tokenRow->recipient_email} failed"
			);
			return $this->tokenMessage(
				'The message could not be released. Please contact your administrator.'
			);
		}

		$this->fileLogger->info(
			"[QuarantineController] mail id {$tokenRow->mail_log_id} released to "
			. "{$tokenRow->recipient_email} via notification link"
		);

		// single use: the link cannot be replayed
		$this->getTokenService()->deleteToken($tokenRow);

		return $this->tokenMessage(
			'The message has been released and should arrive in your mailbox shortly.'
		);
	}

	/*
	 * Shared validation for the two POST actions.
	 *
	 * Returns [MailLogToken, null] on success or [null, Response] to return.
	 * Every failure renders the same page, so a caller cannot tell whether a
	 * token was unknown, expired, or the CSRF check failed.
	 */
	private function resolveToken(string $token, string $csrfId): array {
		if (!MailTokenService::isEnabled()) {
			return [null, $this->tokenError()];
		}

		if (!$this->csrfValid($csrfId)) {
			$this->fileLogger->warning(
				"[QuarantineController] CSRF check failed on {$csrfId} from "
				. ($_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN')
			);
			return [null, $this->tokenError()];
		}

		$tokenRow = $this->getTokenService()->findValidToken($token);

		if ($tokenRow === null) {
			return [null, $this->tokenError()];
		}

		return [$tokenRow, null];
	}

	/*
	 * A MailLogService whose effective identity is the token's recipient, so
	 * the existing applyUserScope() authorizes the lookup rather than being
	 * bypassed.
	 */
	private function getScopedService(MailLogToken $tokenRow): MailLogService {
		return new MailLogService([
			'is_admin' => false,
			'username' => null,
			'email' => $tokenRow->recipient_email,
			'user_aliases' => [],
		]);
	}

	private function getTokenService(): MailTokenService {
		return new MailTokenService();
	}

	private function tokenError(): Response {
		return $this->tokenMessage(
			'This link is not valid or has expired. '
			. 'Quarantined messages are kept for a limited time.'
		);
	}

	private function tokenMessage(string $message): Response {
		$this->twigView();
		return new Response($this->twig->render('quarantine/message.twig', [
			'message' => $message,
		]));
	}

}
