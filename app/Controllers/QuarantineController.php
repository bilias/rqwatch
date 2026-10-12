<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Controllers;

use App\Core\Routing\RouteName;

use App\Models\MailLogToken;

use App\Services\MailLogService;
use App\Services\MailTokenService;
use App\Services\MailerService;

use App\Utils\Helper;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

use Exception;

/*
 * Password-less quarantine access from notification mails.
 *
 * All actions are public. A token authorizes view and release of exactly one
 * message for exactly one recipient - it is not a login and grants nothing
 * else.
 *
 * The emailed link is /notify#<token>. The fragment never reaches the server,
 * so no URL in any request or log carries the token: landing() serves a page
 * whose script posts it to link(), which stores its hash in the session under
 * a random handle and redirects. Later URLs carry only the handle, which means
 * nothing without the session cookie.
 *
 * landing() and confirm() are plain GETs because mail security appliances and
 * clients prefetch links in email; they perform no action. link(), view() and
 * release() are POST + CSRF so a prefetch cannot trigger them.
 */
class QuarantineController extends ViewController
{
	private const string SESSION_KEY = 'notify_tokens';

	// handles kept per session; the oldest is dropped beyond this
	private const int SESSION_MAX = 10;

	/*
	 * Step 0: the emailed link lands here; the page's script posts the token
	 * from the URL fragment to link().
	 */
	public function landing(): Response {
		if (!MailTokenService::isEnabled()) {
			return $this->tokenDisabled();
		}

		// enable form rendering support (needed for csrf_token() in templates)
		$this->twigFormView($this->request);

		return $this->tokenResponse($this->twig->render('quarantine/landing.twig'));
	}

	/*
	 * Step 0b: trades the posted token for a handle.
	 */
	public function link(): Response {
		if (!MailTokenService::isEnabled()) {
			return $this->tokenDisabled();
		}

		if (!$this->csrfValid('token_redeem')) {
			$this->fileLogger->warning(
				"[QuarantineController] CSRF check failed on token_redeem from "
				. ($_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN')
			);
			return $this->tokenError();
		}

		// all(): get() throws on an array-valued field (token[]=...)
		$token = $this->request->request->all()['token'] ?? '';

		if (!is_string($token)) {
			return $this->tokenError();
		}

		// an expired link is reported here, before the user clicks
		$tokenRow = $this->getTokenService()->findValidToken($token);

		if ($tokenRow === null) {
			return $this->tokenError();
		}

		$handle = bin2hex(random_bytes(16));
		$handles = $this->session->get(self::SESSION_KEY, []);
		$handles[$handle] = ['hash' => $tokenRow->token_hash, 'redeemed' => false, 'seen' => time()];
		$this->session->set(self::SESSION_KEY, array_slice($handles, -self::SESSION_MAX, null, true));

		// a planted session id must not inherit the handle
		$this->session->migrate(true);

		return $this->tokenResponse(new RedirectResponse(
			$this->url(RouteName::TOKEN_CONFIRM, ['handle' => $handle]),
			Response::HTTP_SEE_OTHER
		));
	}

	/*
	 * Step 1: shows nothing about the message, only a button to proceed.
	 */
	public function confirm(string $handle): Response {
		if (!MailTokenService::isEnabled()) {
			return $this->tokenDisabled();
		}

		if ($this->sessionToken($handle) === null) {
			return $this->tokenError();
		}

		// enable form rendering support (needed for csrf_token() in templates)
		$this->twigFormView($this->request);

		return $this->tokenResponse($this->twig->render('quarantine/confirm.twig', [
			'handle' => $handle,
		]));
	}

	/*
	 * Step 2: show the held message. POST only.
	 */
	public function view(string $handle): Response {
		[$tokenRow, $error] = $this->resolveToken($handle, 'token_view');

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

		return $this->tokenResponse($this->twig->render('quarantine/view.twig', [
			'handle' => $handle,
			'recipient' => $tokenRow->recipient_email,
			'log' => $mailobject->getMailLog(),
			'textBody' => $mailobject->getTextBody(),
			'htmlBody' => Helper::normalizeToUtf8($mailobject->getHtmlBody()),
			'virus_found' => $mailobject->getVirusFound(),
			'release_disabled' => $this->releaseDisabled($mailobject->getMailLog()->server),
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
	public function release(string $handle): Response {
		[$tokenRow, $error] = $this->resolveToken($handle, 'token_release');

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

		// refused before sending, so the token stays usable once it is fixed
		if ($this->releaseDisabled($maillog->server)) {
			$this->fileLogger->error(
				"[QuarantineController] release of mail id {$tokenRow->mail_log_id} refused: "
				. "MAILER_DSN or MAILER_FROM is not set in .env"
			);
			return $this->tokenMessage(
				'This message cannot be released at the moment. Please contact your administrator.'
			);
		}

		/*
		if ($maillog->released) {
			return $this->tokenMessage(
				'This message has already been released to your mailbox.'
			);
		}
		*/

		// destination is the token's recipient only; the mail's own server
		// sends it, since it holds the file and records the release
		$recipient = $tokenRow->recipient_email;

		if ($_ENV['MY_API_SERVER_ALIAS'] === $maillog->server) {
			$released = $service->releaseHtmlMail([$recipient], $maillog, $this->twig);
		} else {
			$released = $service->releaseMailViaApi([$recipient], $maillog->id, $maillog->server, $recipient);
		}

		if (!$released) {
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
		// per-mail trail of who released it; a remote node also logs its own send
		$this->syslogLogger->info(
			"Message {$maillog->qid} released to '{$recipient}' via notification link from '"
			. ($_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN') . "' (sent by {$maillog->server})"
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
	private function resolveToken(string $handle, string $csrfId): array {
		if (!MailTokenService::isEnabled()) {
			return [null, $this->tokenDisabled()];
		}

		if (!$this->csrfValid($csrfId)) {
			$this->fileLogger->warning(
				"[QuarantineController] CSRF check failed on {$csrfId} from "
				. ($_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN')
			);
			return [null, $this->tokenError()];
		}

		$tokenRow = $this->sessionToken($handle);

		if ($tokenRow === null || !$this->redeem($handle, $tokenRow)) {
			return [null, $this->tokenError()];
		}

		return [$tokenRow, null];
	}

	// the token row behind a handle stored by link() in this session
	private function sessionToken(string $handle): ?MailLogToken {
		$handles = $this->session->get(self::SESSION_KEY, []);
		$hash = $handles[$handle]['hash'] ?? null;

		if (!is_string($hash)) {
			return null;
		}

		// idle handles expire on their own, whatever the session lifetime
		if (time() - ($handles[$handle]['seen'] ?? 0) > MailTokenService::idleSeconds()) {
			unset($handles[$handle]);
			$this->session->set(self::SESSION_KEY, $handles);
			return null;
		}

		$handles[$handle]['seen'] = time();
		$this->session->set(self::SESSION_KEY, $handles);

		return $this->getTokenService()->findValidTokenByHash($hash);
	}

	// one-time links: the first POST rotates the hash, so the emailed link stops working
	private function redeem(string $handle, MailLogToken $tokenRow): bool {
		$handles = $this->session->get(self::SESSION_KEY, []);

		if (!MailTokenService::isOneTime() || ($handles[$handle]['redeemed'] ?? false)) {
			return true;
		}

		$newHash = $this->getTokenService()->redeemToken($tokenRow);

		if ($newHash === null) {
			return false;
		}

		$handles[$handle]['hash'] = $newHash;
		$handles[$handle]['redeemed'] = true;
		$this->session->set(self::SESSION_KEY, $handles);
		$tokenRow->token_hash = $newHash;

		$this->fileLogger->info(
			"[QuarantineController] notification link for mail id {$tokenRow->mail_log_id} used from "
			. ($_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN')
		);

		return true;
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

	// links are issued by the notification cron, which may run with a different .env
	private function tokenDisabled(): Response {
		$this->fileLogger->warning(
			"[QuarantineController] notification link refused: NOTIFICATION_RELEASE_LINKS is not enabled on this web node"
		);
		return $this->tokenMessage(
			'Links in notification mails are disabled. Please sign in to view this message.'
		);
	}

	// a local mail needs this node's mailer; a remote one is sent and checked by its own node
	private function releaseDisabled(string $server): bool {
		return $_ENV['MY_API_SERVER_ALIAS'] === $server && !MailerService::isConfigured();
	}

	private function tokenError(): Response {
		return $this->tokenMessage(
			'This link is not valid, has already been used, or has expired. '
			. 'Quarantined messages are kept for a limited time.'
		);
	}

	private function tokenMessage(string $message): Response {
		$this->twigView();
		return $this->tokenResponse($this->twig->render('quarantine/message.twig', [
			'message' => $message,
		]));
	}

	// token pages: keep the bearer token out of caches and referrers
	private function tokenResponse(Response|string $content): Response {
		$response = $content instanceof Response ? $content : new Response($content);
		$response->headers->set('Cache-Control', 'no-store');
		$response->headers->set('Referrer-Policy', 'no-referrer');
		return $response;
	}

}
