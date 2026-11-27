<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Releasing and notifying, on RecordingDatabase, with MailerService's
 transport seam recording the mail sent. A release sends the stored
 original, attached as Original Message.eml, to each address given (a
 comma-joined entry split, blanks and repeats dropped) from MAILER_FROM
 under release_mail_subject, with the mail's details in the release
 template and text; only a sent mail is marked released, with its date. No
 mailer, no stored file or no recipient sends nothing. A notification goes
 to each recipient of the mail alone, with only their own address and a
 link to the mail, or, with release links on, a token link (one-time as
 configured); an admin notification goes to each admin with every
 recipient. A mail is marked notified (or admin notified), with its date,
 once any copy is sent; the failed addresses are logged. Mails the cron
 skips get NULL in the notification column (admin skips dated), only
 where it is still 0, a thousand ids at a time. The cron reads mails
 pending notification within notification_days (any, when 0) or admin
 notification within admin_notification_days (3 by default), per server
 when given, and narrows each mail's recipients to those whose
 notifications are on.
*/

namespace Tests\Unit\Services;

use App\Configuration\Config;
use App\Core\App;
use App\Core\AppContainer;
use App\Core\Database\MigrationStatus;
use App\Models\MailLog;
use App\Models\MailLogData;
use App\Models\MailLogRecipient;
use App\Services\MailerService;
use App\Services\MailLogService;
use App\Services\UserService;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Tests\Unit\RecordingDatabase;
use Tests\Unit\UnitTestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class MailLogNotifyTest extends UnitTestCase
{
	private const string EML = "From: a@example.org\r\nSubject: Held\r\n\r\nHeld body\r\n";
	private const string DATE = '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/';

	private RecordingDatabase $db;

	private string $dir = '';

	/** @var list<Email> */
	private array $sent = [];

	/** @var list<string> recipients whose mail fails */
	private array $failFor = [];

	/** @var list<array{string, array<string, mixed>, int}> each link made: route, parameters, reference type */
	private array $links = [];

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->db = new RecordingDatabase($this->createStub(PDO::class));
		$this->db->capsule->bootEloquent();
		$this->db->affect = fn (): int => 1;
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, $this->db->capsule, new MigrationStatus(null, $this->log), null, false));
		$this->dir = sys_get_temp_dir() . '/rqwatch-mn-' . bin2hex(random_bytes(4));
		mkdir("{$this->dir}/q/Q7", 0777, true);
		file_put_contents("{$this->dir}/q/Q7/Q7", self::EML);
		$this->setEnv('QUARANTINE_DIR', "{$this->dir}/q");
		$this->setEnv('MAILER_DSN', 'null://null');
		$this->setEnv('MAILER_FROM', 'rqwatch@example.org');
		$this->setEnv('NOTIFICATION_RELEASE_LINKS', null);
		Config::set('release_mail_subject', 'Released');
		Config::set('notify_mail_subject', 'Quarantined');
		Config::set('admin_notify_mail_subject', 'Quarantined (admin)');
		Config::set('mail_signature', 'The postmaster');
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
		// without a warning: a template or text may lack any variable
		set_error_handler(fn (int $no, string $message): never => throw new \ErrorException($message, 0, $no));
	}

	#[\Override]
	protected function tearDown(): void {
		restore_error_handler();
		(new ReflectionProperty(MailerService::class, 'transport'))->setValue(null, null);
		@unlink("{$this->dir}/q/Q7/Q7");
		@rmdir("{$this->dir}/q/Q7");
		@rmdir("{$this->dir}/q");
		@rmdir($this->dir);
		Model::unsetConnectionResolver();
		RecordingDatabase::forget();
		parent::tearDown();
	}

	private function record(SentMessage $message): void {
		$email = $message->getOriginalMessage();
		$this->assertInstanceOf(Email::class, $email);
		$to = self::addresses($email->getTo());
		if (array_intersect($to, $this->failFor) !== []) {
			throw new RuntimeException('550 rejected ' . implode(', ', $to));
		}
		$this->sent[] = $email;
	}

	/**
	 * @param array<Address> $addresses
	 * @return list<string>
	 */
	private static function addresses(array $addresses): array {
		return array_values(array_map(fn (Address $address): string => $address->getAddress(), $addresses));
	}

	private static function twig(): Environment {
		return new Environment(new ArrayLoader([
			'mail/release.html.twig' => "{{ created_at|date('Y-m-d H:i') }}|{{ subject }}|{{ qid }}|{{ message_id }}|{{ score }}|{{ has_virus ? 'virus' : 'clean' }}|{{ virus_name }}|{{ mime_from }}|{{ rcpt_to }}|{{ action }}|{{ signature }}",
			'mail/notify.html.twig' => "{{ created_at|date('Y-m-d H:i') }}|{{ subject }}|{{ qid }}|{{ score }}|{{ has_virus ? 'virus' : 'clean' }}|{{ virus_name }}|{{ mime_from }}|{{ rcpt_to }}|{{ action }}|{{ detailurl }}|{{ link_one_time ? 'once' : 'reusable' }}|{{ signature }}",
			'mail/notify_admin.html.twig' => "{{ created_at|date('Y-m-d H:i') }}|{{ server }}|{{ mail_from }}|{{ mime_from }}|{{ rcpt_to }}|{{ subject }}|{{ qid }}|{{ score }}|{{ has_virus ? 'virus' : 'clean' }}|{{ virus_name }}|{{ action }}|{{ detailurl }}|{{ signature }}",
		]), ['autoescape' => false]);
	}

	private function urls(): UrlGeneratorInterface {
		$urls = $this->createStub(UrlGeneratorInterface::class);
		$urls->method('generate')->willReturnCallback(function (string $name, array $parameters = [], int $type = UrlGeneratorInterface::ABSOLUTE_PATH): string {
			/** @var array<string, mixed> $parameters */
			$this->links[] = [$name, $parameters, $type];
			return "https://rq.example.org/{$name}" . ($parameters === [] ? '' : '?' . http_build_query($parameters));
		});
		return $urls;
	}

	/**
	 * a stored mail with its recipients and symbols
	 * @param array<string, mixed> $attributes
	 * @param list<string> $recipients
	 */
	private function mail(array $attributes = [], array $recipients = ['alice@example.org', 'bob@example.org']): MailLog {
		$mail = new MailLog();
		$mail->setRawAttributes($attributes + [
			'id' => 7, 'qid' => 'Q7', 'server' => 'mx1', 'subject' => 'Invoice', 'message_id' => '<m1@example.net>',
			'score' => 15.5, 'has_virus' => 1, 'virus_name' => 'Eicar', 'mail_from' => 'bounce@example.net', 'mime_from' => 'a@example.net',
			'action' => 'reject', 'mail_stored' => 1, 'mail_location' => "{$this->dir}/q/Q7/Q7", 'created_at' => '2026-11-26 10:00:00',
		], true);
		$mail->exists = true;
		$mail->setRelation('recipients', new Collection(array_map(
			fn (string $email): MailLogRecipient => (new MailLogRecipient())->setRawAttributes(['mail_log_id' => 7, 'recipient_email' => $email]),
			$recipients
		)));
		$mail->setRelation('mailLogData', (new MailLogData())->setRawAttributes(['mail_log_id' => 7, 'symbols' => '[{"name":"CLAM_VIRUS","score":10,"group":"antivirus","options":["Eicar-Signature"]}]']));
		return $mail;
	}

	/**
	 * @param array<int, MailLog> $mails
	 * @return Collection<int, MailLog>
	 */
	private static function collection(array $mails): Collection {
		return new Collection($mails);
	}

	/** @return list<array{list<string>, string, string, string, string, list<array{?string, string}>}> each mail sent: to, from, subject, text, html, attachments */
	private function sentMails(): array {
		return array_map(fn (Email $email): array => [
			self::addresses($email->getTo()),
			self::addresses($email->getFrom())[0] ?? '',
			(string) $email->getSubject(),
			(string) $email->getTextBody(),
			(string) $email->getHtmlBody(),
			array_values(array_map(fn ($part): array => [$part->getFilename(), $part->getBody()], $email->getAttachments())),
		], $this->sent);
	}

	/** @param list<mixed> $bindings */
	private function assertDated(array $bindings, int $from, int $count): void {
		/** @var mixed $date */
		foreach (array_slice($bindings, $from, $count) as $date) {
			$this->assertIsString($date);
			$this->assertMatchesRegularExpression(self::DATE, $date);
		}
	}

	public function testRelease(): void {
		$mail = $this->mail();
		$this->assertTrue((new MailLogService())->releaseHtmlMail(['alice@example.org, bob@example.org', ' bob@example.org', '', 'carol@example.org '], $mail, self::twig()));
		$html = '2026-11-26 10:00|Invoice|Q7|<m1@example.net>|15.5|virus|[CLAM_VIRUS: Eicar-Signature]|a@example.net|alice@example.org, bob@example.org|reject|The postmaster';
		$mails = $this->sentMails();
		$this->assertCount(1, $mails);
		[$to, $from, $subject, $text, $sentHtml, $attached] = $mails[0];
		$this->assertSame(
			[['alice@example.org', 'bob@example.org', 'carol@example.org'], 'rqwatch@example.org', 'Released', $html, [['Original Message.eml', self::EML]]],
			[$to, $from, $subject, $sentHtml, $attached]
		);
		foreach (['Invoice', 'Q7', 'a@example.net', 'alice@example.org, bob@example.org', '[CLAM_VIRUS: Eicar-Signature]', 'The postmaster'] as $part) {
			$this->assertStringContainsString($part, $text);
		}
		$this->assertCount(1, $this->db->changes);
		[$sql, $bindings] = $this->db->changes[0];
		$this->assertSame(['update `mail_logs` set `released` = ?, `release_date` = ?, `mail_logs`.`updated_at` = ? where `id` = ?', 1, 7], [$sql, $bindings[0], $bindings[3]]);
		$this->assertDated($bindings, 1, 2);
		$this->assertSame([], $this->log->lines);
	}

	/** @return array<string, array{?string, ?string}> MAILER_DSN, MAILER_FROM */
	public static function noMailer(): array {
		return [
			'no MAILER_FROM' => ['null://null', null],
			'no MAILER_DSN' => [null, 'rqwatch@example.org'],
		];
	}

	#[DataProvider('noMailer')]
	public function testReleaseWithoutMailer(?string $dsn, ?string $from): void {
		$this->setEnv('MAILER_DSN', $dsn);
		$this->setEnv('MAILER_FROM', $from);
		$this->assertFalse((new MailLogService())->releaseHtmlMail(['alice@example.org'], $this->mail(), self::twig()));
		$this->assertSame([['error [releaseHtmlMail] Q7: MAILER_DSN or MAILER_FROM is not set in .env'], [], []], [$this->log->lines, $this->sent, $this->db->changes]);
	}

	/** @return array<string, array{array<string, mixed>, string}> attributes, the location logged */
	public static function notReleasable(): array {
		return [
			'not stored' => [['mail_stored' => 0], '{dir}/q/Q7/Q7'],
			'no location' => [['mail_location' => null], 'no location'],
			'no file' => [['mail_location' => '{dir}/q/Q7/Q8'], '{dir}/q/Q7/Q8'],
		];
	}

	/** @param array<string, mixed> $attributes */
	#[DataProvider('notReleasable')]
	public function testReleaseWithoutFile(array $attributes, string $location): void {
		$attributes = array_map(fn (mixed $v): mixed => is_string($v) ? str_replace('{dir}', $this->dir, $v) : $v, $attributes);
		$location = str_replace('{dir}', $this->dir, $location);
		$this->assertFalse((new MailLogService())->releaseHtmlMail(['alice@example.org'], $this->mail($attributes), self::twig()));
		$this->assertSame([["error [releaseHtmlMail] Q7: not in quarantine or file missing: {$location}"], [], []], [$this->log->lines, $this->sent, $this->db->changes]);
	}

	public function testReleaseWithoutRecipient(): void {
		$this->assertFalse((new MailLogService())->releaseHtmlMail([' ', ', ,'], $this->mail(), self::twig()));
		$this->assertSame([['error [releaseHtmlMail] Q7: no recipient'], [], []], [$this->log->lines, $this->sent, $this->db->changes]);
	}

	public function testReleaseNotSent(): void {
		$this->failFor = ['alice@example.org'];
		$this->assertFalse((new MailLogService())->releaseHtmlMail(['alice@example.org'], $this->mail(), self::twig()));
		$this->assertSame([['error [MailerService] Email failed: 550 rejected alice@example.org'], []], [$this->log->lines, $this->db->changes]);
	}

	// each recipient alone, with their own address and a link to the mail
	public function testNotify(): void {
		$mail = $this->mail([], [' alice@example.org ', 'bob@example.org', 'bob@example.org', '']);
		$this->assertTrue((new MailLogService())->notifyHtmlMail($mail, $this->urls(), self::twig()));
		$html = fn (string $to): string => "2026-11-26 10:00|Invoice|Q7|15.5|virus|Eicar|a@example.net|{$to}|reject|https://rq.example.org/detail?type=id&value=7|reusable|The postmaster";
		$mails = $this->sentMails();
		$this->assertSame(
			[
				[['alice@example.org'], 'rqwatch@example.org', 'Quarantined', $html('alice@example.org'), []],
				[['bob@example.org'], 'rqwatch@example.org', 'Quarantined', $html('bob@example.org'), []],
			],
			array_map(fn (array $m): array => [$m[0], $m[1], $m[2], $m[4], $m[5]], $mails)
		);
		foreach (['Invoice', 'Q7', 'a@example.net', 'Eicar', 'https://rq.example.org/detail?type=id&value=7', 'The postmaster'] as $part) {
			$this->assertStringContainsString($part, $mails[1][3]);
		}
		$this->assertStringContainsString('bob@example.org', $mails[1][3]);
		$this->assertStringNotContainsString('alice@example.org', $mails[1][3]);
		$this->assertSame([['detail', ['type' => 'id', 'value' => 7], UrlGeneratorInterface::ABSOLUTE_URL]], $this->links);
		[$sql, $bindings] = $this->db->changes[0];
		$this->assertSame(['update `mail_logs` set `notified` = ?, `notify_date` = ?, `mail_logs`.`updated_at` = ? where `mail_logs`.`id` = ?', 1, 7], [$sql, $bindings[0], $bindings[3]]);
		$this->assertDated($bindings, 1, 2);
		$this->assertCount(1, $this->db->changes);
		$this->assertSame([], $this->log->lines);
	}

	/** @return array<string, array{?bool, string}> notification_links_one_time, as the mail says it */
	public static function tokenLinks(): array {
		return [
			'one-time by default' => [null, 'once'],
			'reusable' => [false, 'reusable'],
		];
	}

	// a token link per recipient, the token only in the fragment
	#[DataProvider('tokenLinks')]
	public function testNotifyWithTokens(?bool $oneTime, string $said): void {
		$this->setEnv('NOTIFICATION_RELEASE_LINKS', 'true');
		Config::set('notification_links_one_time', $oneTime);
		$this->assertTrue((new MailLogService())->notifyHtmlMail($this->mail([], ['alice@example.org']), $this->urls(), self::twig()));
		$html = $this->sentMails()[0][4];
		$this->assertMatchesRegularExpression('~\|https://rq\.example\.org/token_link#([0-9a-f]{64})\|' . $said . '\|~', $html);
		preg_match('~#([0-9a-f]{64})~', $html, $token);
		$this->assertSame(
			[['mail_log_id' => 7, 'recipient_email' => 'alice@example.org', 'token_hash' => hash('sha256', $token[1] ?? '')]],
			array_map(fn (array $row): array => array_intersect_key($row, ['mail_log_id' => 0, 'recipient_email' => 0, 'token_hash' => 0]), $this->db->rows('mail_log_tokens'))
		);
		$this->assertSame([['detail', ['type' => 'id', 'value' => 7], UrlGeneratorInterface::ABSOLUTE_URL], ['token_link', [], UrlGeneratorInterface::ABSOLUTE_URL]], $this->links);
	}

	// no token issued: the plain link
	public function testNotifyWithoutToken(): void {
		$this->setEnv('NOTIFICATION_RELEASE_LINKS', 'true');
		$this->assertTrue((new MailLogService())->notifyHtmlMail($this->mail(['id' => 0], ['alice@example.org']), $this->urls(), self::twig()));
		$this->assertStringContainsString('|https://rq.example.org/detail?type=id&value=0|reusable|', $this->sentMails()[0][4]);
		$this->assertSame(["warning [MailTokenService] refusing to issue token for mail id '0' and empty recipient"], $this->log->lines);
	}

	public function testNotifyWithoutRecipients(): void {
		$this->assertFalse((new MailLogService())->notifyHtmlMail($this->mail([], [' ', '']), $this->urls(), self::twig()));
		$this->assertSame([['warning [notifyHtmlMail] no recipients for mail id 7'], [], [], []], [$this->log->lines, $this->sent, $this->db->changes, $this->links]);
	}

	public function testNotifyPartly(): void {
		$this->failFor = ['alice@example.org', 'carol@example.org'];
		$this->assertTrue((new MailLogService())->notifyHtmlMail($this->mail([], ['alice@example.org', 'bob@example.org', 'carol@example.org']), $this->urls(), self::twig()));
		$this->assertSame([['bob@example.org']], array_map(fn (array $m): array => $m[0], $this->sentMails()));
		$this->assertSame([
			'error [MailerService] Email failed: 550 rejected alice@example.org',
			'error [MailerService] Email failed: 550 rejected carol@example.org',
			'error [notifyHtmlMail] mail id 7 notification failed for: alice@example.org, carol@example.org',
		], $this->log->lines);
		$this->assertCount(1, $this->db->changes);
	}

	public function testNotifyNoneSent(): void {
		$this->failFor = ['alice@example.org'];
		$this->assertFalse((new MailLogService())->notifyHtmlMail($this->mail([], ['alice@example.org']), $this->urls(), self::twig()));
		$this->assertSame([
			'error [MailerService] Email failed: 550 rejected alice@example.org',
			'error [notifyHtmlMail] mail id 7 notification failed for: alice@example.org',
		], $this->log->lines);
		$this->assertSame([], $this->db->changes);
	}

	public function testNotifyAdmins(): void {
		$this->assertTrue((new MailLogService())->notifyAdminHtmlMail($this->mail(), $this->urls(), ['root@example.org', 'hostmaster@example.org'], self::twig()));
		$html = '2026-11-26 10:00|mx1|bounce@example.net|a@example.net|alice@example.org, bob@example.org|Invoice|Q7|15.5|virus|Eicar|reject|https://rq.example.org/detail?type=id&value=7|The postmaster';
		$mails = $this->sentMails();
		$this->assertSame(
			[
				[['root@example.org'], 'rqwatch@example.org', 'Quarantined (admin)', $html, []],
				[['hostmaster@example.org'], 'rqwatch@example.org', 'Quarantined (admin)', $html, []],
			],
			array_map(fn (array $m): array => [$m[0], $m[1], $m[2], $m[4], $m[5]], $mails)
		);
		foreach (['bounce@example.net', 'a@example.net', 'alice@example.org, bob@example.org', 'Invoice', 'Q7', 'Eicar', 'The postmaster'] as $part) {
			$this->assertStringContainsString($part, $mails[0][3]);
		}
		$this->assertSame([['detail', ['type' => 'id', 'value' => 7], UrlGeneratorInterface::ABSOLUTE_URL]], $this->links);
		[$sql, $bindings] = $this->db->changes[0];
		$this->assertSame(['update `mail_logs` set `admin_notified` = ?, `admin_notify_date` = ?, `mail_logs`.`updated_at` = ? where `mail_logs`.`id` = ?', 1, 7], [$sql, $bindings[0], $bindings[3]]);
		$this->assertDated($bindings, 1, 2);
		$this->assertCount(1, $this->db->changes);
	}

	public function testNotifyAdminsWithoutRecipients(): void {
		$this->assertTrue((new MailLogService())->notifyAdminHtmlMail($this->mail([], []), $this->urls(), ['root@example.org'], self::twig()));
		$this->assertStringContainsString('|a@example.net|(none)|Invoice|', $this->sentMails()[0][4]);
	}

	public function testNotifyAdminsPartly(): void {
		$this->failFor = ['root@example.org', 'abuse@example.org'];
		$this->assertTrue((new MailLogService())->notifyAdminHtmlMail($this->mail(), $this->urls(), ['root@example.org', 'hostmaster@example.org', 'abuse@example.org'], self::twig()));
		$this->assertSame([
			'error [MailerService] Email failed: 550 rejected root@example.org',
			'error [MailerService] Email failed: 550 rejected abuse@example.org',
			'error [notifyAdminHtmlMail] mail id 7 admin notification failed for: root@example.org, abuse@example.org',
		], $this->log->lines);
		$this->assertCount(1, $this->db->changes);
	}

	/** @return array<string, array{list<string>}> */
	public static function noAdminSent(): array {
		return [
			'all fail' => [['root@example.org']],
			'no admins' => [[]],
		];
	}

	/** @param list<string> $admins */
	#[DataProvider('noAdminSent')]
	public function testNotifyAdminsNoneSent(array $admins): void {
		$this->failFor = ['root@example.org'];
		$this->assertFalse((new MailLogService())->notifyAdminHtmlMail($this->mail(), $this->urls(), $admins, self::twig()));
		$this->assertSame([], $this->db->changes);
	}

	public function testMarkSkipped(): void {
		$this->db->affect = fn (string $sql, array $bindings): int => count($bindings) - 3;
		$ids = [...range(1, 1001), '5', 7, '1002'];
		$this->assertSame(1002, (new MailLogService())->markNotificationsSkipped($ids));
		$this->assertCount(2, $this->db->changes);
		[$sql, $bindings] = $this->db->changes[0];
		$this->assertSame(['update `mail_logs` set `notified` = ?, `mail_logs`.`updated_at` = ? where `id` in (' . implode(', ', array_fill(0, 1000, '?')) . ') and `notified` = ?', null, range(1, 1000), 0],
			[$sql, $bindings[0], array_slice($bindings, 2, 1000), $bindings[1002]]);
		$this->assertSame(['update `mail_logs` set `notified` = ?, `mail_logs`.`updated_at` = ? where `id` in (?, ?) and `notified` = ?', [null, 1001, 1002, 0]],
			[$this->db->changes[1][0], [$this->db->changes[1][1][0], ...array_slice($this->db->changes[1][1], 2)]]);
	}

	// an admin skip is dated
	public function testMarkAdminSkipped(): void {
		$this->assertSame(1, (new MailLogService())->markNotificationsSkipped([3], 'admin_notified'));
		[$sql, $bindings] = $this->db->changes[0];
		$this->assertSame(['update `mail_logs` set `admin_notified` = ?, `admin_notify_date` = ?, `mail_logs`.`updated_at` = ? where `id` in (?) and `admin_notified` = ?', null, 3, 0],
			[$sql, $bindings[0], $bindings[3], $bindings[4]]);
		$this->assertDated($bindings, 1, 2);
	}

	public function testMarkSkippedFails(): void {
		$this->db->affect = fn (string $sql, array $bindings): int => in_array(1001, $bindings, true) ? throw new RuntimeException('Deadlock found') : 1000;
		$this->assertSame(1000, (new MailLogService())->markNotificationsSkipped(range(1, 1001)));
		$this->assertSame(['error Failed to mark skipped notified: Deadlock found'], $this->log->lines);
	}

	public function testMarkSkippedRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Not a notification column: released');
		(new MailLogService())->markNotificationsSkipped([3], 'released');
	}

	public function testMarkNothingSkipped(): void {
		$this->assertSame(0, (new MailLogService())->markNotificationsSkipped([]));
		$this->assertSame([], $this->db->changes);
	}

	private static function daysAgo(int $days): string {
		return (new DateTimeImmutable())->sub(new DateInterval("P{$days}D"))->format('Y-m-d');
	}

	/** @return array<string, array{?int, ?string}> */
	public static function notificationDays(): array {
		return [
			'not set' => [null, null],
			'0: any' => [0, null],
			'7 days' => [7, 'P7D'],
			'1 day' => [1, 'P1D'],
		];
	}

	#[DataProvider('notificationDays')]
	public function testNotificationCutoff(?int $days, ?string $interval): void {
		Config::set('notification_days', $days);
		$this->assertSame($interval === null ? null : (new DateTimeImmutable())->sub(new DateInterval($interval))->format('Y-m-d'), MailLogService::notificationCutoff());
	}

	/** @return array<string, array{?int, int}> */
	public static function adminDays(): array {
		return [
			'not set: 3' => [null, 3],
			'0: 3' => [0, 3],
			'1 day' => [1, 1],
			'10 days' => [10, 10],
		];
	}

	#[DataProvider('adminDays')]
	public function testAdminNotificationCutoff(?int $days, int $used): void {
		Config::set('admin_notification_days', $days);
		$this->assertSame(self::daysAgo($used), MailLogService::adminNotificationCutoff());
	}

	private static function admin(): MailLogService {
		return new MailLogService(['is_admin' => true, 'email' => 'root@example.org']);
	}

	private static function fields(): string {
		return 'select `' . implode('`, `', MailLog::SELECT_FIELDS) . '` from `mail_logs`';
	}

	public function testUnnotified(): void {
		Config::set('notification_days', 7);
		$this->db->answer = fn (string $sql): array => str_contains($sql, 'from `mail_logs`') ? [['id' => 7, 'qid' => 'Q7']] : [];
		$mails = self::admin()->getUnnotified(null, 'mx2');
		$this->assertSame([7], $mails->pluck('id')->all());
		$this->assertSame([self::fields() . ' where `notification_pending` = ? and `created_day` >= ? and `server` = ?', [1, self::daysAgo(7), 'mx2']], $this->db->selects[0]);
	}

	// in the user's scope, every server, no day limit
	public function testUnnotifiedAll(): void {
		(new MailLogService(['is_admin' => false, 'email' => 'alice@example.org']))->getUnnotified();
		$this->assertSame([self::fields() . ' where `notification_pending` = ? and exists (select 1 from `mail_log_recipients` as `r` where `r`.`mail_log_id` = `mail_logs`.`id` and `r`.`recipient_email` in (?))', [1, 'alice@example.org']], $this->db->selects[0]);
	}

	public function testUnnotifiedDebug(): void {
		$this->setEnv('DEBUG_SEARCH_SQL', 'true');
		$service = self::admin();
		$service->getUnnotified();
		$output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
		$service->getUnnotified($output);
		$quiet = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL);
		$service->getUnnotified($quiet);
		$sql = self::fields() . ' where `notification_pending` = "1"';
		$this->assertSame([["info {$sql}"], "{$sql}\n", ''], [$this->log->lines, $output->fetch(), $quiet->fetch()]);
	}

	public function testAdminUnnotified(): void {
		Config::set('admin_notification_days', 2);
		$this->db->answer = fn (string $sql): array => str_contains($sql, 'from `mail_logs`') ? [['id' => 7, 'qid' => 'Q7']] : [];
		$this->assertSame([7], self::admin()->getAdminUnnotified('mx2')->pluck('id')->all());
		$this->assertSame([self::fields() . ' where `admin_notified` = ? and `created_day` >= ? and `server` = ? order by `id` asc', [0, self::daysAgo(2), 'mx2']], $this->db->selects[0]);
		self::admin()->getAdminUnnotified();
		$this->assertSame([self::fields() . ' where `admin_notified` = ? and `created_day` >= ? order by `id` asc', [0, self::daysAgo(2)]], array_values(array_filter($this->db->selects, fn (array $select): bool => str_contains($select[0], 'from `mail_logs`')))[1] ?? null);
	}

	// recipients with notifications off are dropped from the mail (a list again), listed in disabled_rcpt_to
	public function testFilterDisabledRecipients(): void {
		$users = new UserService();
		(new ReflectionProperty(UserService::class, 'notificationsDisabledCache'))->setValue($users, [
			'alice@example.org' => false,
			'bob@example.org' => true,
			'carol@example.org' => true,
		]);
		$first = $this->mail([], ['bob@example.org', ' Alice@Example.org', 'BOB@example.org', '', 'carol@example.org']);
		$second = $this->mail(['id' => 8], ['alice@example.org']);
		(new MailLogService())->filterDisabledRecipients(self::collection([$first, $second]), $users);
		$this->assertSame(
			[['bob@example.org, carol@example.org', [' Alice@Example.org']], ['', ['alice@example.org']]],
			[
				[$first->disabled_rcpt_to, array_map(fn (MailLogRecipient $r): mixed => $r->recipient_email, $first->recipients->all())],
				[$second->disabled_rcpt_to, $second->recipients->pluck('recipient_email')->all()],
			]
		);
	}
}
