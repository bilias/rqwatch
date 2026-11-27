<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The notification-link pages, on RecordingDatabase, with the mail in a
 QUARANTINE_DIR of its own and MailerService's transport seam. Every page
 is kept out of caches and referrers. With NOTIFICATION_RELEASE_LINKS off
 each says links are off, logged. The landing page posts the token from
 the fragment, with a CSRF token; link() trades a valid token for a
 random handle in a renewed session (the last 10 kept) and goes on to the
 confirm page, which needs the handle. view() and release() need their
 CSRF token and the handle: the first use of a one-time link rotates its
 hash (logged); the mail is read in the recipient's scope. The view shows
 the mail, its inline styles allowed; the release sends it to the token's
 recipient only, logs it (and to syslog) and deletes the token; without a
 mailer it is refused first, keeping the token. Any failure is the same
 page, whatever went wrong.
*/

namespace Tests\Unit\Controllers;

use App\Configuration\Config;
use App\Controllers\QuarantineController;
use App\Core\Routing\RouteName;
use App\Services\MailerService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final class QuarantineControllerTest extends ControllerTestCase
{
	private const string TOKEN = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';
	private const string EML = "From: a@example.net\r\nTo: alice@example.org\r\nSubject: Invoice\r\nMIME-Version: 1.0\r\n"
		. "Content-Type: text/plain; charset=us-ascii\r\n\r\nPlease pay the held invoice\r\n";
	private const string ERROR = 'This link is not valid, has already been used, or has expired. Quarantined messages are kept for a limited time.';

	private string $dir = '';

	/** @var list<Email> */
	private array $sent = [];

	private bool $mailerFails = false;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/rqwatch-qc-' . bin2hex(random_bytes(4));
		mkdir("{$this->dir}/Q7", 0777, true);
		file_put_contents("{$this->dir}/Q7/Q7", self::EML);
		$this->setEnv('QUARANTINE_DIR', $this->dir);
		$this->setEnv('MY_API_SERVER_ALIAS', 'mx1');
		$this->setEnv('NOTIFICATION_RELEASE_LINKS', 'true');
		$this->setEnv('MAILER_DSN', 'null://null');
		$this->setEnv('MAILER_FROM', 'rqwatch@example.org');
		Config::set('release_mail_subject', 'Released');
		$hash = hash('sha256', self::TOKEN);
		$mail = $this->mailRow();
		$this->answers = [
			['from `mail_log_tokens` where `token_hash` = ?', function (array $bindings) use ($hash): array {
				/** @var mixed $wanted */
				$wanted = $bindings[0] ?? null;
				// the token's hash, or one it was rotated to
				return is_string($wanted) && ($wanted === $hash || preg_match('/^[a-f0-9]{64}$/D', $wanted) === 1 && $this->db->changes !== [])
					? [['token_hash' => $wanted, 'mail_log_id' => 7, 'recipient_email' => 'alice@example.org']] : [];
			}],
			['from `mail_logs`', [$mail]],
			['from `mail_log_data`', [['mail_log_id' => 7, 'symbols' => null, 'headers' => null]]],
			['from `mail_log_recipients`', [['mail_log_id' => 7, 'recipient_email' => 'alice@example.org']]],
		];
		$this->db->affect = fn (string $sql): int => 1;
		$transport = new class ($this->record(...)) extends AbstractTransport {
			/** @param \Closure(SentMessage): void $record */
			public function __construct(private \Closure $record) {
				parent::__construct();
			}

			#[\Override]
			protected function doSend(SentMessage $message): void {
				($this->record)($message);
			}

			#[\Override]
			public function __toString(): string {
				return 'recording://';
			}
		};
		(new ReflectionProperty(MailerService::class, 'transport'))->setValue(null, fn (string $dsn): TransportInterface => $transport);
	}

	/** @return array<string, mixed> the mail_logs row */
	private function mailRow(): array {
		return ['id' => 7, 'qid' => 'Q7', 'server' => 'mx1', 'mail_stored' => 1, 'mail_location' => "{$this->dir}/Q7/Q7", 'subject' => 'Invoice',
			'score' => 9.5, 'has_virus' => 0, 'ip' => null, 'mail_from' => 'a@example.net', 'mime_from' => 'a@example.net', 'mime_to' => 'undisclosed@example.net',
			'action' => 'reject', 'created_at' => '2026-11-26 10:00:00', 'message_id' => '<m1@example.net>'];
	}

	#[\Override]
	protected function tearDown(): void {
		(new ReflectionProperty(MailerService::class, 'transport'))->setValue(null, null);
		@unlink("{$this->dir}/Q7/Q7");
		@rmdir("{$this->dir}/Q7");
		@rmdir($this->dir);
		parent::tearDown();
	}

	private function record(SentMessage $message): void {
		if ($this->mailerFails) {
			throw new RuntimeException('550 rejected');
		}
		$email = $message->getOriginalMessage();
		$this->assertInstanceOf(Email::class, $email);
		$this->sent[] = $email;
	}

	private function url(RouteName $route, string $handle = ''): string {
		return $this->urls->generate($route->value, $handle === '' ? [] : ['handle' => $handle]);
	}

	private function quarantine(Request $request): QuarantineController {
		return $this->controller(new QuarantineController(), $request);
	}

	private function assertTokenPage(Response $response, ?string $message = null): string {
		$this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
		$this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
		$html = (string) $response->getContent();
		if ($message !== null) {
			$this->assertStringContainsString("<p class='center'>{$message}</p>", $html);
		}
		return $html;
	}

	/** @param array<string, mixed> $handles */
	private static function session(array $handles = []): Request {
		return self::request('/notify', $handles === [] ? [] : ['notify_tokens' => $handles]);
	}

	/** @return array{string, Request} the handle, the request whose session holds it */
	private function linked(bool $redeemed = false): array {
		$handle = str_repeat('ab', 16);
		$request = self::session([$handle => ['hash' => hash('sha256', self::TOKEN), 'redeemed' => $redeemed, 'seen' => time()]]);
		return [$handle, $request];
	}

	/** @return array<string, array{string}> */
	public static function actions(): array {
		return [
			'landing' => ['landing'],
			'link' => ['link'],
			'confirm' => ['confirm'],
			'view' => ['view'],
			'release' => ['release'],
		];
	}

	#[DataProvider('actions')]
	public function testLinksOff(string $action): void {
		$this->setEnv('NOTIFICATION_RELEASE_LINKS', 'false');
		[$handle, $request] = $this->linked();
		$controller = $this->quarantine($request);
		$response = match ($action) {
			'landing' => $controller->landing(),
			'link' => $controller->link(),
			'confirm' => $controller->confirm($handle),
			'view' => $controller->view($handle),
			default => $controller->release($handle),
		};
		$this->assertTokenPage($response, 'Links in notification mails are disabled. Please sign in to view this message.');
		$this->assertSame(['warning [QuarantineController] notification link refused: NOTIFICATION_RELEASE_LINKS is not enabled on this web node'], $this->log->lines);
		$this->assertSame([], $this->db->selects);
	}

	public function testLanding(): void {
		$request = self::session();
		$html = $this->assertTokenPage($this->quarantine($request)->landing());
		$this->assertCsrfAfter("action='/notify/redeem?_token=", $html, $request, 'token_redeem');
		$this->assertStringContainsString("<input type='hidden' name='token' id='redeem-token' value=''>", $html);
	}

	/**
	 * @param array<string, mixed> $post
	 * @return array{Response, Request}
	 */
	private function link(array $post = ['token' => self::TOKEN], ?string $csrf = null, ?Request $request = null): array {
		$request ??= self::session();
		$request->setMethod('POST');
		$request->request->replace($post);
		$request->query->set('_token', $csrf ?? self::csrf($request, 'token_redeem'));
		return [$this->quarantine($request)->link(), $request];
	}

	public function testLink(): void {
		$request = self::session();
		$before = $request->getSession()->getId();
		[$response] = $this->link(['token' => ' ' . self::TOKEN . ' '], null, $request);
		$this->assertTokenPage($response);
		$this->assertSame(Response::HTTP_SEE_OTHER, $response->getStatusCode());
		/** @var mixed $handles */
		$handles = $request->getSession()->get('notify_tokens');
		$this->assertIsArray($handles);
		$this->assertCount(1, $handles);
		$handle = (string) array_key_first($handles);
		$this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $handle);
		$entry = (array) $handles[$handle];
		$this->assertSame(['hash' => hash('sha256', self::TOKEN), 'redeemed' => false], array_slice($entry, 0, 2));
		$this->assertEqualsWithDelta(time(), $entry['seen'] ?? 0, 2);
		$this->assertRedirect($this->url(RouteName::TOKEN_CONFIRM, $handle), $response);
		$this->assertNotSame($before, $request->getSession()->getId());
		$this->assertSame([hash('sha256', self::TOKEN), 1], $this->selected('from `mail_log_tokens`')[1] ?? null);
	}

	public function testLinkKeepsTen(): void {
		$handles = [];
		for ($i = 1; $i <= 10; $i++) {
			$handles["h{$i}"] = ['hash' => 'x', 'redeemed' => false, 'seen' => $i];
		}
		$request = self::session($handles);
		$this->link([], null, $request);
		$this->link(['token' => self::TOKEN], null, $request);
		/** @var mixed $kept */
		$kept = $request->getSession()->get('notify_tokens');
		$this->assertIsArray($kept);
		$this->assertCount(10, $kept);
		$this->assertSame(['h2', 'h3', 'h4', 'h5', 'h6', 'h7', 'h8', 'h9', 'h10'], array_slice(array_keys($kept), 0, 9));
	}

	/** @return array<string, array{array<string, mixed>}> */
	public static function badTokens(): array {
		return [
			'none' => [[]],
			'not hex' => [['token' => str_repeat('z', 64)]],
			'too short' => [['token' => 'a1b2']],
			'a list' => [['token' => [self::TOKEN]]],
		];
	}

	/** @param array<string, mixed> $post */
	#[DataProvider('badTokens')]
	public function testLinkBadToken(array $post): void {
		[$response, $request] = $this->link($post);
		$this->assertTokenPage($response, self::ERROR);
		$this->assertSame([], $this->db->selects);
		$this->assertFalse($request->getSession()->has('notify_tokens'));
	}

	public function testLinkUnknownToken(): void {
		[$response] = $this->link(['token' => str_repeat('0', 64)]);
		$this->assertTokenPage($response, self::ERROR);
		$this->assertCount(1, $this->db->selects);
	}

	// the message page builds its own view when nothing else has: here the
	// first page of the request (a command line's, so without a cache)
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testMessageBuildsItsView(): void {
		define('CLI_MODE', true);
		$request = self::session();
		$request->query->set('_token', 'forged');
		$controller = new QuarantineController();
		$controller->setRequest($request);
		$controller->setUrlGenerator($this->urls);
		$this->assertTokenPage($controller->link(), self::ERROR);
	}

	public function testLinkBadCsrf(): void {
		[$response] = $this->link(['token' => self::TOKEN], 'forged');
		$this->assertTokenPage($response, self::ERROR);
		$this->assertSame(['warning [QuarantineController] CSRF check failed on token_redeem from 192.0.2.7'], $this->log->lines);
		$this->assertSame([], $this->db->selects);
	}

	public function testConfirm(): void {
		[$handle, $request] = $this->linked();
		$html = $this->assertTokenPage($this->quarantine($request)->confirm($handle));
		$this->assertCsrfAfter("action='/notify/view/{$handle}?_token=", $html, $request, 'token_view');
		$this->assertStringNotContainsString('Invoice', $html);
	}

	public function testConfirmUnknownHandle(): void {
		[, $request] = $this->linked();
		$this->assertTokenPage($this->quarantine($request)->confirm('cd'), self::ERROR);
		$this->assertSame([], $this->db->selects);
	}

	/** @return array{Response, Request} */
	private function post(string $action, bool $redeemed = false, ?string $csrf = null): array {
		[$handle, $request] = $this->linked($redeemed);
		$request->setMethod('POST');
		$request->query->set('_token', $csrf ?? self::csrf($request, $action === 'view' ? 'token_view' : 'token_release'));
		$controller = $this->quarantine($request);
		return [$action === 'view' ? $controller->view($handle) : $controller->release($handle), $request];
	}

	public function testView(): void {
		[$response, $request] = $this->post('view');
		$html = $this->assertTokenPage($response);
		$this->assertStringContainsString('Please pay the held invoice', $html);
		$this->assertStringContainsString("<div class='detail'>alice@example.org</div>", $html);
		$this->assertStringContainsString('<button', substr($html, (int) strpos($html, '/notify/release/')));
		// read in the recipient's scope
		$read = $this->selected('from `mail_logs` where `id` = ?');
		$this->assertNotNull($read);
		$this->assertContains('alice@example.org', $read[1]);
		// and only the recipient's own address among the mail's recipients
		$recipients = $this->selected('from `mail_log_recipients` where `mail_log_recipients`.`mail_log_id` in');
		$this->assertNotNull($recipients);
		$this->assertContains('alice@example.org', $recipients[1]);
		$this->assertStringContainsString("style-src 'self' 'unsafe-inline'", (string) $response->headers->get('Content-Security-Policy'));
		$this->assertCsrfAfter("action='/notify/release/abababababababababababababababab?_token=", $html, $request, 'token_release');
		// the first use rotates the emailed link's hash
		$update = $this->db->changes[0] ?? null;
		$this->assertNotNull($update);
		$this->assertSame('update `mail_log_tokens` set `token_hash` = ? where `token_hash` = ?', $update[0]);
		$this->assertSame(hash('sha256', self::TOKEN), $update[1][1] ?? null);
		/** @var mixed $handles */
		$handles = $request->getSession()->get('notify_tokens');
		$this->assertIsArray($handles);
		$entry = (array) reset($handles);
		$this->assertSame([$update[1][0] ?? null, true], [$entry['hash'] ?? null, $entry['redeemed'] ?? null]);
		$this->assertSame(['info [QuarantineController] notification link for mail id 7 used from 192.0.2.7'], $this->log->lines);
	}

	public function testViewVirus(): void {
		$this->answers = array_merge([
			['from `mail_logs` where `id` = ?', [['has_virus' => 1] + $this->mailRow()]],
			['from `mail_log_data`', [['mail_log_id' => 7, 'symbols' => json_encode([['name' => 'CLAM_VIRUS', 'score' => 15.0, 'group' => 'antivirus', 'options' => ['Eicar-Signature']]]), 'headers' => null]]],
		], $this->answers);
		[$response] = $this->post('view', true);
		$this->assertStringContainsString('<strong>Yes</strong>: [CLAM_VIRUS: Eicar-Signature]', $this->assertTokenPage($response));
	}

	// without a mailer the view offers no release
	public function testViewWithoutMailer(): void {
		$this->setEnv('MAILER_DSN', null);
		[$response] = $this->post('view', true);
		$html = $this->assertTokenPage($response);
		$this->assertStringContainsString('This message cannot be released at the moment. Please contact your administrator.', $html);
		$this->assertStringNotContainsString('/notify/release/', $html);
	}

	// a handle stored without its redeemed flag is not redeemed yet
	public function testRedeemedFlagMissing(): void {
		$handle = str_repeat('ab', 16);
		$request = self::session([$handle => ['hash' => hash('sha256', self::TOKEN), 'seen' => time()]]);
		$request->setMethod('POST');
		$request->query->set('_token', self::csrf($request, 'token_view'));
		$this->quarantine($request)->view($handle);
		$this->assertCount(1, $this->db->changes);
	}

	public function testViewRedeemedAlready(): void {
		[$response] = $this->post('view', true);
		$this->assertStringContainsString('Please pay the held invoice', $this->assertTokenPage($response));
		$this->assertSame([[], []], [$this->db->changes, $this->log->lines]);
	}

	public function testViewReusableLink(): void {
		Config::set('notification_links_one_time', false);
		[$response] = $this->post('view');
		$this->assertStringContainsString('Please pay the held invoice', $this->assertTokenPage($response));
		$this->assertSame([], $this->db->changes);
	}

	public function testRedeemFails(): void {
		$this->db->affect = fn (string $sql): int => 0;
		[$response] = $this->post('view');
		$this->assertTokenPage($response, self::ERROR);
	}

	public function testViewMailGone(): void {
		unlink("{$this->dir}/Q7/Q7");
		[$response] = $this->post('view', true);
		$this->assertTokenPage($response, self::ERROR);
		$this->assertLogged('warning [QuarantineController] view failed for mail id 7: [MailLogService_getMailObjectLocal] File ');
	}

	#[DataProvider('posts')]
	public function testPostBadCsrf(string $action): void {
		[$response] = $this->post($action, false, 'forged');
		$this->assertTokenPage($response, self::ERROR);
		$id = $action === 'view' ? 'token_view' : 'token_release';
		$this->assertSame(["warning [QuarantineController] CSRF check failed on {$id} from 192.0.2.7"], $this->log->lines);
	}

	/** @return array<string, array{string}> */
	public static function posts(): array {
		return ['view' => ['view'], 'release' => ['release']];
	}

	#[DataProvider('posts')]
	public function testPostUnknownHandle(string $action): void {
		$request = self::session();
		$request->setMethod('POST');
		$request->query->set('_token', self::csrf($request, $action === 'view' ? 'token_view' : 'token_release'));
		$controller = $this->quarantine($request);
		$this->assertTokenPage($action === 'view' ? $controller->view('cd') : $controller->release('cd'), self::ERROR);
	}

	public function testRelease(): void {
		[$response] = $this->post('release', true);
		$this->assertTokenPage($response, 'The message has been released and should arrive in your mailbox shortly.');
		$this->assertCount(1, $this->sent);
		$this->assertSame(['alice@example.org'], array_map(fn (Address $a): string => $a->getAddress(), $this->sent[0]->getTo()));
		$this->assertSame([
			'info [QuarantineController] mail id 7 released to alice@example.org via notification link',
			"info Message Q7 released to 'alice@example.org' via notification link from '192.0.2.7' (sent by mx1)",
		], $this->log->lines);
		$this->assertContains(['delete from `mail_log_tokens` where `token_hash` = ?', [hash('sha256', self::TOKEN)]], $this->db->changes);
	}

	public function testReleaseWithoutMailer(): void {
		$this->setEnv('MAILER_DSN', null);
		[$response] = $this->post('release', true);
		$this->assertTokenPage($response, 'This message cannot be released at the moment. Please contact your administrator.');
		$this->assertSame(['error [QuarantineController] release of mail id 7 refused: MAILER_DSN or MAILER_FROM is not set in .env'], $this->log->lines);
		$this->assertSame([[], []], [$this->sent, $this->db->changes]);
	}

	public function testReleaseFails(): void {
		$this->mailerFails = true;
		[$response] = $this->post('release', true);
		$this->assertTokenPage($response, 'The message could not be released. Please contact your administrator.');
		$this->assertContains('error [QuarantineController] release of mail id 7 to alice@example.org failed', $this->log->lines);
		$this->assertNotContains(['delete from `mail_log_tokens` where `token_hash` = ?', [hash('sha256', self::TOKEN)]], $this->db->changes);
	}

	public function testReleaseMailGone(): void {
		unlink("{$this->dir}/Q7/Q7");
		[$response] = $this->post('release', true);
		$this->assertTokenPage($response, self::ERROR);
		$this->assertLogged("warning [QuarantineController] release failed for mail id 7: [MailLogService_getMailObjectLocal] File '{$this->dir}/Q7/Q7' not found");
	}
}
