<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 cron:notifications on RecordingDatabase, MailerService's transport seam
 recording each mail sent. Only one run at a time. The admin pass first
 (with admin_notification_rcpt and the ADMIN_NOTIFIED migration done):
 each pending mail at or below notification_score, not blacklisted (unless
 -b; with -B at any score) and not matched by the virus skip map, goes to
 the admins; the rest are marked skipped when sending (-m). Then the users'
 pass: mails without a recipient, blacklisted ones (unless -b), those the
 virus skip map matches, those whose recipients all turned notifications
 off, and those over notification_score (unless -B and blacklisted) are
 left out, marked skipped when sending; the rest are listed with -s and
 sent with -m. With -l, this server's mails only. A virus skip map that
 cannot be read stops the run; so does a missing mailer. A mail that fails
 does not stop the others, but fails the run.
*/

namespace Tests\Unit\Console;

use App\Configuration\Config;
use App\Console\CronNotifications;
use App\Core\App;
use App\Core\AppContainer;
use App\Core\Database\MigrationStatus;
use App\Inventory\Migrations;
use App\Services\MailerService;
use Illuminate\Database\Eloquent\Model;
use PDO;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Tests\Unit\PrivateLock;
use Tests\Unit\RecordingDatabase;
use Tests\Unit\UnitTestCase;

// as a cron: a command line, each test in a process of its own
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CronNotificationsTest extends UnitTestCase
{
	private const string VIRUS = '{"name":"CLAM_VIRUS","score":5,"group":"antivirus","options":["Eicar-Test"]}';
	private const string BLACKLISTED = '{"name":"RQWATCH_IP_BL","score":5,"group":"local"}';

	private RecordingDatabase $db;

	private MigrationStatus $status;

	private string $dir = '';

	/** @var array<int, array{qid: string, score: float, symbols: string, recipients: list<string>}> id => the users' pending mails */
	private array $pending = [];

	/** @var array<int, array{qid: string, score: float, symbols: string, recipients: list<string>}> id => the admins' pending mails */
	private array $adminPending = [];

	/** @var list<string> addresses whose users turned notifications off */
	private array $off = [];

	/** @var list<string>|null the virus skip map's patterns; null: unreadable */
	private ?array $skipPatterns = [];

	/** @var list<array{list<string>, string}> each mail sent: to, subject */
	private array $sent = [];

	/** @var list<string> addresses a mail to fails */
	private array $failFor = [];

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		define('CLI_MODE', true);
		$this->db = new RecordingDatabase($this->createStub(PDO::class));
		$this->db->capsule->bootEloquent();
		// a mark changes each mail of its ids; any other update its one row
		$this->db->affect = fn (string $sql): int => preg_match('/`id` in \(([?, ]+)\)/', $sql, $in) === 1 ? substr_count($in[1], '?') : 1;
		$this->db->answer = $this->answer(...);
		$this->status = new MigrationStatus(null, $this->log);
		$this->status->setMigrationState(Migrations::ADMIN_NOTIFIED, Migrations::STATUS_COMPLETED);
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, $this->db->capsule, $this->status, null, false));
		$this->dir = sys_get_temp_dir() . '/rqwatch-cn-' . bin2hex(random_bytes(4));
		mkdir($this->dir);
		Config::set('notification_score', 10.0);
		Config::set('notification_days', 0);
		Config::set('blacklist_symbol_patterns', ['RQWATCH_*_BL']);
		Config::set('admin_notification_rcpt', null);
		Config::set('notify_mail_subject', 'Quarantined');
		Config::set('admin_notify_mail_subject', 'Quarantined (admin)');
		Config::set('mail_signature', 'The postmaster');
		$this->setEnv('MAILER_DSN', 'null://null');
		$this->setEnv('MAILER_FROM', 'rqwatch@example.org');
		$this->setEnv('WEB_HOST_NOTIFICATIONS', 'rq.example.org');
		$this->setEnv('WEB_SCHEME', 'https');
		$this->setEnv('WEB_BASE', '');
		$this->setEnv('MY_API_SERVER_ALIAS', 'mx1');
		$this->setEnv('NOTIFICATION_RELEASE_LINKS', null);
		$this->setEnv('DEBUG_SEARCH_SQL', null);
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

	#[\Override]
	protected function tearDown(): void {
		(new ReflectionProperty(MailerService::class, 'transport'))->setValue(null, null);
		$files = glob("{$this->dir}/*");
		foreach ($files === false ? [] : $files as $file) {
			unlink($file);
		}
		rmdir($this->dir);
		Model::unsetConnectionResolver();
		RecordingDatabase::forget();
		parent::tearDown();
	}

	/** @var list<string> the HTML body of each mail sent */
	private array $bodies = [];

	private function record(SentMessage $message): void {
		$email = $message->getOriginalMessage();
		$this->assertInstanceOf(Email::class, $email);
		$to = array_values(array_map(fn (Address $address): string => $address->getAddress(), $email->getTo()));
		if (array_intersect($to, $this->failFor) !== []) {
			throw new RuntimeException('550 rejected');
		}
		$this->sent[] = [$to, (string) $email->getSubject()];
		$body = $email->getHtmlBody();
		$this->bodies[] = is_string($body) ? $body : '';
	}

	/**
	 * @param list<mixed> $bindings
	 * @return list<array<string, mixed>>
	 */
	private function answer(string $sql, array $bindings): array {
		$mails = $this->pending + $this->adminPending;
		if (str_contains($sql, 'from `mail_logs`')) {
			$which = str_contains($sql, '`admin_notified` = ?') ? $this->adminPending : $this->pending;
			$rows = [];
			foreach ($which as $id => $mail) {
				$rows[] = [
					'id' => $id, 'qid' => $mail['qid'], 'score' => $mail['score'], 'server' => 'mx1', 'subject' => 'Invoice',
					'has_virus' => str_contains($mail['symbols'], 'antivirus') ? 1 : 0, 'mail_from' => 'bounce@example.net',
					'mime_from' => 'a@example.net', 'action' => 'reject', 'created_at' => '2026-11-26 10:00:00',
				];
			}
			return $rows;
		}
		if (str_contains($sql, 'from `mail_log_recipients`')) {
			$rows = [];
			foreach ($mails as $id => $mail) {
				foreach ($mail['recipients'] as $email) {
					$rows[] = ['mail_log_id' => $id, 'recipient_email' => $email];
				}
			}
			return $rows;
		}
		if (str_contains($sql, 'from `mail_log_data`')) {
			return array_map(fn (int $id): array => ['mail_log_id' => $id, 'symbols' => "[{$mails[$id]['symbols']}]"], array_keys($mails));
		}
		if (str_contains($sql, 'from `users`')) {
			return in_array($bindings[0] ?? null, $this->off, true) ? [['id' => 3, 'disable_notifications' => 1]] : [];
		}
		if (str_contains($sql, 'from `maps_custom`')) {
			if ($this->skipPatterns === null) {
				throw new RuntimeException('server has gone away');
			}
			return array_map(fn (string $pattern): array => ['pattern' => $pattern], $this->skipPatterns);
		}
		throw new RuntimeException("Unexpected query: {$sql}");
	}

	/**
	 * @param list<string> $recipients
	 * @return array{qid: string, score: float, symbols: string, recipients: list<string>}
	 */
	private static function mail(string $qid, float $score = 5.0, string $symbols = '', array $recipients = ['alice@example.org']): array {
		return ['qid' => $qid, 'score' => $score, 'symbols' => $symbols, 'recipients' => $recipients];
	}

	/**
	 * @param array<string, true> $options
	 * @return array{int, string}
	 */
	private function cron(array $options): array {
		$tester = new CommandTester(PrivateLock::give(new CronNotifications(), $this->dir));
		$status = $tester->execute($options, ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);
		return [$status, $tester->getDisplay()];
	}

	public function testNothingPending(): void {
		[$status, $display] = $this->cron([]);
		$this->assertSame(Command::SUCCESS, $status);
		$this->assertSame("No entries found for notification\n", $display);
		$this->assertSame(['debug cron:notifications No entries found for notification'], $this->log->lines);
		$this->assertCount(1, $this->db->selects);
	}

	// without -m: listed with -s, nothing sent or marked
	public function testShow(): void {
		$this->pending = [7 => self::mail('Q7', recipients: ['alice@example.org', 'bob@example.org'])];
		[$status, $display] = $this->cron(['-s' => true]);
		$this->assertSame(Command::SUCCESS, $status);
		$this->assertSame("1 entries found for notification\n1 entries remain with a valid recipient\nNotifications pending:\n"
			. "QID: Q7, to: alice@example.org, bob@example.org\nUse -m to send notification mails\n", $display);
		$this->assertSame([[], []], [$this->sent, $this->db->changes]);
	}

	public function testSend(): void {
		$this->pending = [7 => self::mail('Q7', recipients: ['alice@example.org', 'bob@example.org']), 8 => self::mail('Q8', 10.0)];
		[$status, $display] = $this->cron(['-m' => true]);
		$this->assertSame(Command::SUCCESS, $status);
		$this->assertSame([[['alice@example.org'], 'Quarantined'], [['bob@example.org'], 'Quarantined'], [['alice@example.org'], 'Quarantined']], $this->sent);
		$this->assertStringContainsString("Sent notification mail for QID: Q7 to alice@example.org, bob@example.org\nSent notification mail for QID: Q8 to alice@example.org\n", $display);
		$this->assertSame([7, 8], array_map(fn (array $change): mixed => $change[1][3] ?? null, $this->db->changes));
		$this->assertContains("info Sent notification mail for QID: Q7 to 'alice@example.org, bob@example.org'", $this->log->lines);
	}

	// every reason a mail is left out, each marked skipped once a run sends
	public function testLeftOut(): void {
		Config::set('notification_skip_virus_map', 'virus_skip');
		$this->status->setMigrationState(Migrations::VIRUS_SKIP_MAP, Migrations::STATUS_COMPLETED);
		$this->skipPatterns = ['eicar-*'];
		$this->off = ['carol@example.org'];
		$this->pending = [
			1 => self::mail('Q1', recipients: []),
			2 => self::mail('Q2', recipients: ['unknown']),
			3 => self::mail('Q3', symbols: self::BLACKLISTED),
			4 => self::mail('Q4', symbols: self::VIRUS),
			5 => self::mail('Q5', recipients: ['carol@example.org']),
			6 => self::mail('Q6', 10.5),
			7 => self::mail('Q7'),
		];
		[$status, $display] = $this->cron(['-m' => true]);
		$this->assertSame(Command::SUCCESS, $status);
		foreach ([
			"Empty `mail_recipients` for id: 1, disabling notification\n",
			"Empty `mail_recipients` for id: 2, disabling notification\n",
			"Blacklisted mail with id: 3, disabling notification\n",
			"Virus skip map entry 'eicar-*' matched id: 4, disabling notification\n",
			"Notifications disabled for recipient: carol@example.org (mail id: 5)\n",
			"Score higher than 10 for id: 6, disabling notification\n",
			"6 entries marked as not to be notified\n",
			"1 entries remain with a valid recipient\n",
		] as $line) {
			$this->assertStringContainsString($line, $display);
		}
		$this->assertSame([[['alice@example.org'], 'Quarantined']], $this->sent);
		$this->assertSame(['update `mail_logs` set `notified` = ?, `mail_logs`.`updated_at` = ? where `id` in (?, ?, ?, ?, ?, ?) and `notified` = ?'], array_column(array_slice($this->db->changes, 0, 1), 0));
		$bindings = $this->db->changes[0][1] ?? [];
		$this->assertSame([null, 1, 2, 3, 4, 5, 6, 0], [...array_slice($bindings, 0, 1), ...array_slice($bindings, 2)]);
		$this->assertContains("debug cron:notifications Virus skip map entry 'eicar-*' matched id: 4, disabling notification", $this->log->lines);
		$this->assertContains('debug cron:notifications 6 entries marked as not to be notified', $this->log->lines);
	}

	// without -m, nothing is marked; all left out: none remain
	public function testLeftOutNotMarked(): void {
		$this->pending = [3 => self::mail('Q3', symbols: self::BLACKLISTED), 6 => self::mail('Q6', 11.0)];
		[$status, $display] = $this->cron(['-s' => true]);
		$this->assertSame(Command::SUCCESS, $status);
		$this->assertStringEndsWith("No entries remain for notification\n", $display);
		$this->assertSame([], $this->db->changes);
		$this->assertContains('debug cron:notifications No entries remain for notification', $this->log->lines);
	}

	// -b: a blacklisted mail at or below notification_score is notified; -B: at any score
	public function testBlacklisted(): void {
		$this->pending = [3 => self::mail('Q3', symbols: self::BLACKLISTED), 6 => self::mail('Q6', 30.0, self::BLACKLISTED), 9 => self::mail('Q9', 30.0)];
		$this->cron(['-m' => true, '-b' => true]);
		$this->assertSame([[['alice@example.org'], 'Quarantined']], $this->sent);
		$this->sent = [];
		$this->cron(['-m' => true, '-B' => true]);
		$this->assertSame([[['alice@example.org'], 'Quarantined'], [['alice@example.org'], 'Quarantined']], $this->sent);
	}

	// -l: this server's mails, said in each message
	public function testLocal(): void {
		$this->pending = [7 => self::mail('Q7')];
		[, $display] = $this->cron(['-l' => true, '-s' => true]);
		$this->assertStringContainsString("1 entries found for notification on server: mx1\n", $display);
		$this->assertStringContainsString("Notifications pending on server: mx1:\n", $display);
		$this->assertStringContainsString("`server` = ?", $this->db->selects[0][0] ?? '');
		$this->assertSame('mx1', $this->db->selects[0][1][1] ?? null);
	}

	public function testVirusSkipMapUnreadable(): void {
		Config::set('notification_skip_virus_map', 'virus_skip');
		$this->status->setMigrationState(Migrations::VIRUS_SKIP_MAP, Migrations::STATUS_COMPLETED);
		$this->skipPatterns = null;
		$this->pending = [7 => self::mail('Q7')];
		[$status, $display] = $this->cron(['-m' => true, '-l' => true]);
		$this->assertSame(Command::FAILURE, $status);
		$this->assertStringContainsString("Could not read the virus skip map on server: mx1\n", $display);
		$this->assertSame([[], []], [$this->sent, $this->db->changes]);
		$this->assertContains('error cron:notifications Could not read the virus skip map on server: mx1', $this->log->lines);
	}

	public function testNoMailer(): void {
		$this->setEnv('MAILER_FROM', null);
		$this->pending = [7 => self::mail('Q7')];
		[$status, $display] = $this->cron(['-m' => true]);
		$this->assertSame(Command::FAILURE, $status);
		$this->assertStringEndsWith("MAILER_DSN or MAILER_FROM is not set in .env\n", $display);
		$this->assertSame([[], 'error MAILER_DSN or MAILER_FROM is not set in .env'], [$this->sent, $this->log->lines[count($this->log->lines) - 1] ?? null]);
	}

	// a failing mail does not stop the next, but fails the run
	public function testSendFails(): void {
		$this->failFor = ['alice@example.org'];
		$this->pending = [7 => self::mail('Q7'), 8 => self::mail('Q8', recipients: ['bob@example.org'])];
		[$status, $display] = $this->cron(['-m' => true]);
		$this->assertSame(Command::FAILURE, $status);
		$this->assertSame([[['bob@example.org'], 'Quarantined']], $this->sent);
		$this->assertStringContainsString("Sending notification mail with QID: Q7 to alice@example.org failed\n", $display);
		$this->assertStringContainsString("1 notification mail(s) failed\n", $display);
		$this->assertContains('error cron:notifications 1 notification mail(s) failed', $this->log->lines);
		$this->assertContains('error Sending notification mail with QID: Q7 to alice@example.org failed', $this->log->lines);
	}

	public function testAlreadyRunning(): void {
		$lock = (new LockFactory(new FlockStore($this->dir)))->createLock('cron:notifications');
		$this->assertTrue($lock->acquire());
		[$status, $display] = $this->cron([]);
		$this->assertSame([Command::FAILURE, "Already running in another process.\n"], [$status, $display]);
		$this->assertSame(['warning cron:notifications Already running in another process'], $this->log->lines);
		$lock->release();
	}

	// a virus no skip map entry matches: notified, named, to the user and the admins
	public function testVirusNamed(): void {
		Config::set('admin_notification_rcpt', 'admin@example.org');
		$this->pending = [4 => self::mail('Q4', symbols: self::VIRUS)];
		$this->adminPending = [4 => self::mail('Q4', symbols: self::VIRUS)];
		$this->cron(['-m' => true]);
		$this->assertCount(2, $this->bodies);
		foreach ($this->bodies as $body) {
			$this->assertStringContainsString('<td>Yes [CLAM_VIRUS: Eicar-Test]</td>', $body);
		}
	}

	// the admins get each pending mail fit to notify; the rest marked skipped (dated)
	public function testAdmins(): void {
		Config::set('admin_notification_rcpt', 'admin@example.org, not-an-address, root@example.org');
		Config::set('notification_skip_virus_map', 'virus_skip');
		$this->status->setMigrationState(Migrations::VIRUS_SKIP_MAP, Migrations::STATUS_COMPLETED);
		$this->skipPatterns = ['eicar-*'];
		$this->adminPending = [
			3 => self::mail('Q3', symbols: self::BLACKLISTED),
			4 => self::mail('Q4', symbols: self::VIRUS),
			6 => self::mail('Q6', 10.5),
			7 => self::mail('Q7'),
		];
		[$status, $display] = $this->cron(['-m' => true, '-s' => true]);
		$this->assertSame(Command::SUCCESS, $status);
		$this->assertSame([[['admin@example.org'], 'Quarantined (admin)'], [['root@example.org'], 'Quarantined (admin)']], $this->sent);
		$this->assertStringContainsString("3 entries marked as not to be admin notified\nAdmin notifications pending:\n"
			. "QID: Q7, to: admin@example.org, root@example.org\nSent admin notification for QID: Q7\n", $display);
		[$sql, $bindings] = $this->db->changes[0] ?? ['', []];
		$this->assertSame('update `mail_logs` set `admin_notified` = ?, `admin_notify_date` = ?, `mail_logs`.`updated_at` = ? where `id` in (?, ?, ?) and `admin_notified` = ?', $sql);
		$this->assertSame([null, 3, 4, 6, 0], [...array_slice($bindings, 0, 1), ...array_slice($bindings, 3)]);
		$this->assertContains("info Sent admin notification for QID: Q7 to 'admin@example.org, root@example.org'", $this->log->lines);
		$this->assertContains('info cron:notifications 3 entries marked as not to be admin notified', $this->log->lines);
		$this->assertStringContainsString('`admin_notified` = ?', $this->db->selects[0][0] ?? '');
	}

	// -B: a blacklisted mail at any score; -b: at or below notification_score
	public function testAdminsBlacklisted(): void {
		Config::set('admin_notification_rcpt', 'admin@example.org');
		$this->adminPending = [3 => self::mail('Q3', symbols: self::BLACKLISTED), 6 => self::mail('Q6', 30.0, self::BLACKLISTED)];
		$this->cron(['-m' => true, '-b' => true]);
		$this->cron(['-m' => true, '-B' => true]);
		// one with -b, both with -B
		$this->assertCount(3, $this->sent);
	}

	// without -m: listed, not sent, not marked; -l: this server's
	public function testAdminsShow(): void {
		Config::set('admin_notification_rcpt', 'admin@example.org');
		$this->adminPending = [6 => self::mail('Q6', 10.5), 7 => self::mail('Q7')];
		[$status, $display] = $this->cron(['-s' => true, '-l' => true]);
		$this->assertSame(Command::SUCCESS, $status);
		$this->assertStringStartsWith("Admin notifications pending on server: mx1:\nQID: Q7, to: admin@example.org\n", $display);
		$this->assertSame([[], []], [$this->sent, $this->db->changes]);
		$this->assertSame('mx1', $this->db->selects[0][1][2] ?? null);
	}

	// none pending, or none left: the users' pass goes on
	public function testAdminsNothing(): void {
		Config::set('admin_notification_rcpt', 'admin@example.org');
		[, $display] = $this->cron([]);
		$this->assertStringStartsWith("No entries found for admin notification\n", $display);
		$this->adminPending = [6 => self::mail('Q6', 10.5)];
		[, $display] = $this->cron(['-l' => true]);
		$this->assertStringStartsWith("No entries remain for admin notification on server: mx1\n", $display);
	}

	// no admins, or the migration not done: no admin pass
	public function testNoAdminPass(): void {
		$this->adminPending = [7 => self::mail('Q7')];
		Config::set('admin_notification_rcpt', 'not-an-address');
		$this->cron(['-m' => true]);
		Config::set('admin_notification_rcpt', 'admin@example.org');
		$this->status->setMigrationState(Migrations::ADMIN_NOTIFIED, Migrations::STATUS_RUNNING);
		$this->cron(['-m' => true]);
		$this->assertSame([], $this->sent);
		$this->assertCount(2, $this->db->selects);
	}

	// the admins' failures fail the run, the users' pass done all the same
	public function testAdminsFail(): void {
		Config::set('admin_notification_rcpt', 'admin@example.org');
		$this->failFor = ['admin@example.org'];
		$this->adminPending = [7 => self::mail('Q7')];
		$this->pending = [8 => self::mail('Q8')];
		[$status, $display] = $this->cron(['-m' => true]);
		$this->assertSame(Command::FAILURE, $status);
		$this->assertStringContainsString("Sending admin notification for QID: Q7 failed\n", $display);
		$this->assertSame([[['alice@example.org'], 'Quarantined']], $this->sent);
		$this->assertContains('error Sending admin notification for QID: Q7 failed', $this->log->lines);
	}

	// the users' pass failing nothing, an admin failure still fails the run
	public function testAdminsFailUsersNothing(): void {
		Config::set('admin_notification_rcpt', 'admin@example.org');
		$this->setEnv('MAILER_FROM', null);
		$this->adminPending = [7 => self::mail('Q7')];
		[$status, $display] = $this->cron(['-m' => true]);
		$this->assertSame(Command::FAILURE, $status);
		$this->assertStringStartsWith("MAILER_DSN or MAILER_FROM is not set in .env\nNo entries found for notification\n", $display);
		$this->pending = [6 => self::mail('Q6', 11.0)];
		$this->assertSame(Command::FAILURE, $this->cron(['-m' => true])[0]);
	}

	public function testAdminsVirusSkipMapUnreadable(): void {
		Config::set('admin_notification_rcpt', 'admin@example.org');
		Config::set('notification_skip_virus_map', 'virus_skip');
		$this->status->setMigrationState(Migrations::VIRUS_SKIP_MAP, Migrations::STATUS_COMPLETED);
		$this->skipPatterns = null;
		$this->adminPending = [7 => self::mail('Q7')];
		[$status, $display] = $this->cron(['-m' => true]);
		$this->assertSame(Command::FAILURE, $status);
		$this->assertStringStartsWith("Could not read the virus skip map, no admin notifications sent\n", $display);
		$this->assertContains('error cron:notifications Could not read the virus skip map, no admin notifications sent', $this->log->lines);
		$this->assertSame([], $this->sent);
	}

	public function testNoNotificationScore(): void {
		Config::set('notification_score', null);
		$this->pending = [7 => self::mail('Q7')];
		$this->expectExceptionObject(new RuntimeException('notification_score is not set in config'));
		$this->cron([]);
	}
}
