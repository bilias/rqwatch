<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 MailerService. A node can send mail once MAILER_DSN and MAILER_FROM are
 both set ('' and '0' are not); without a DSN it cannot be built. A mail
 goes from the sender to every recipient with the subject and text given,
 or with the HTML of a Twig template rendered with its context (and the
 text given, else one made from the HTML). A quarantined file may go with
 it as a message/rfc822 attachment, 8bit, under the name given; a path
 outside QUARANTINE_DIR sends nothing. A mail that cannot be built or sent
 is logged and reported as not sent. A recording transport stands in for
 MAILER_DSN's. Without a Twig environment of its own, a mail is rendered
 with the application's views (the cron's: uncached).
*/

namespace Tests\Unit\Services;

use App\Services\MailerService;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Tests\Unit\UnitTestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class MailerServiceTest extends UnitTestCase
{
	private const string QUARANTINED = "From: a@example.org\r\nSubject: Held\r\n\r\nHeld body\r\n";

	/** @var list<SentMessage> */
	private array $sent = [];

	private ?RuntimeException $failure = null;

	private string $dir = '';

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->setEnv('MAILER_DSN', 'null://null');
		$this->dir = sys_get_temp_dir() . '/rqwatch-q-' . bin2hex(random_bytes(4));
		mkdir("{$this->dir}/q/2026", 0777, true);
		file_put_contents("{$this->dir}/q/2026/Q1", self::QUARANTINED);
		file_put_contents("{$this->dir}/outside", self::QUARANTINED);
		$this->setEnv('QUARANTINE_DIR', "{$this->dir}/q");
	}

	#[\Override]
	protected function tearDown(): void {
		foreach (["{$this->dir}/q/2026/Q1", "{$this->dir}/outside"] as $file) {
			@unlink($file);
		}
		@rmdir("{$this->dir}/q/2026");
		@rmdir("{$this->dir}/q");
		@rmdir($this->dir);
		parent::tearDown();
	}

	// the service, its mail going to $this->sent
	private function mailer(): MailerService {
		$service = new MailerService($this->log, new Environment(new ArrayLoader([
			'notify.html.twig' => '<p>Hello {{ name }}</p>',
		])));
		$transport = new class ($this->record(...)) extends AbstractTransport {
			/** @param Closure(SentMessage): void $record */
			public function __construct(private Closure $record) {
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
		(new ReflectionProperty(MailerService::class, 'mailer'))->setValue($service, new Mailer($transport));
		return $service;
	}

	private function record(SentMessage $message): void {
		if ($this->failure !== null) {
			throw $this->failure;
		}
		$this->sent[] = $message;
	}

	private function email(): Email {
		$this->assertCount(1, $this->sent);
		$email = $this->sent[0]->getOriginalMessage();
		$this->assertInstanceOf(Email::class, $email);
		return $email;
	}

	/**
	 * @param array<Address> $addresses
	 * @return list<string>
	 */
	private static function addresses(array $addresses): array {
		return array_values(array_map(fn (Address $address): string => $address->getAddress(), $addresses));
	}

	private function assertAttached(Email $email): void {
		$attachments = $email->getAttachments();
		$this->assertCount(1, $attachments);
		$part = $attachments[0];
		$this->assertSame(
			['held.eml', 'message/rfc822', '8bit', self::QUARANTINED],
			[
				$part->getFilename(),
				$part->getContentType(),
				$part->getPreparedHeaders()->getHeaderBody('Content-Transfer-Encoding'),
				$part->getBody(),
			]
		);
	}

	/** @return array<string, array{?string, ?string, bool}> MAILER_DSN, MAILER_FROM, configured */
	public static function configurations(): array {
		return [
			'both' => ['null://null', 'rqwatch@example.org', true],
			'no DSN' => [null, 'rqwatch@example.org', false],
			'an empty DSN' => ['', 'rqwatch@example.org', false],
			"'0' as DSN" => ['0', 'rqwatch@example.org', false],
			'no sender' => ['null://null', null, false],
			'an empty sender' => ['null://null', '', false],
			"'0' as sender" => ['null://null', '0', false],
		];
	}

	#[DataProvider('configurations')]
	public function testIsConfigured(?string $dsn, ?string $from, bool $configured): void {
		$this->setEnv('MAILER_DSN', $dsn);
		$this->setEnv('MAILER_FROM', $from);
		$this->assertSame($configured, MailerService::isConfigured());
	}

	/** @return array<string, array{?string}> */
	public static function noDsn(): array {
		return ['unset' => [null], 'empty' => [''], "'0'" => ['0']];
	}

	#[DataProvider('noDsn')]
	public function testNoDsn(?string $dsn): void {
		$this->setEnv('MAILER_DSN', $dsn);
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('MAILER_DSN is not set in .env');
		new MailerService($this->log, new Environment(new ArrayLoader([])));
	}

	public function testSend(): void {
		$this->assertTrue($this->mailer()->sendEmail('rqwatch@example.org', ['alice@example.org', 'bob@example.org'], 'Your mail', 'Released.'));
		$email = $this->email();
		$this->assertSame(
			[['rqwatch@example.org'], ['alice@example.org', 'bob@example.org'], 'Your mail', 'Released.', null, []],
			[self::addresses($email->getFrom()), self::addresses($email->getTo()), $email->getSubject(), $email->getTextBody(), $email->getHtmlBody(), $email->getAttachments()]
		);
		$this->assertSame([], $this->log->lines);
	}

	public function testSendWithTheQuarantinedMail(): void {
		$this->assertTrue($this->mailer()->sendEmail('rqwatch@example.org', ['alice@example.org'], 'Your mail', 'Attached.', "{$this->dir}/q/2026/Q1", 'held.eml'));
		$this->assertAttached($this->email());
		$this->assertSame([], $this->log->lines);
	}

	public function testSendTemplated(): void {
		$this->assertTrue($this->mailer()->sendTemplatedEmail('rqwatch@example.org', ['alice@example.org', 'bob@example.org'], 'Report', 'notify.html.twig', 'Plain report', ['name' => 'Alice']));
		$email = $this->email();
		$this->assertSame(
			[['rqwatch@example.org'], ['alice@example.org', 'bob@example.org'], 'Report', 'Plain report', '<p>Hello Alice</p>', []],
			[self::addresses($email->getFrom()), self::addresses($email->getTo()), $email->getSubject(), $email->getTextBody(), $email->getHtmlBody(), $email->getAttachments()]
		);
		$this->assertSame([], $this->log->lines);
	}

	// no text given: one made from the HTML
	public function testSendTemplatedWithoutText(): void {
		$this->assertTrue($this->mailer()->sendTemplatedEmail('rqwatch@example.org', ['alice@example.org'], 'Report', 'notify.html.twig', null, ['name' => 'Alice']));
		$this->assertSame('Hello Alice', trim((string) $this->email()->getTextBody()));
	}

	public function testSendTemplatedWithTheQuarantinedMail(): void {
		$this->assertTrue($this->mailer()->sendTemplatedEmail('rqwatch@example.org', ['alice@example.org'], 'Report', 'notify.html.twig', 'Plain', ['name' => 'Alice'], "{$this->dir}/q/2026/Q1", 'held.eml'));
		$this->assertAttached($this->email());
	}

	/** @return array<string, array{bool}> */
	public static function kinds(): array {
		return ['text' => [false], 'templated' => [true]];
	}

	private function send(MailerService $mailer, bool $templated, string $from = 'rqwatch@example.org', ?string $attachment = null): bool {
		return $templated
			? $mailer->sendTemplatedEmail($from, ['alice@example.org'], 'Report', 'notify.html.twig', 'Plain', ['name' => 'Alice'], $attachment, 'held.eml')
			: $mailer->sendEmail($from, ['alice@example.org'], 'Report', 'Plain', $attachment, 'held.eml');
	}

	// a file outside QUARANTINE_DIR is not attached, and nothing is sent
	#[DataProvider('kinds')]
	public function testAttachmentOutsideTheQuarantine(bool $templated): void {
		$this->assertFalse($this->send($this->mailer(), $templated, attachment: "{$this->dir}/outside"));
		$this->assertSame([[], ["warning Not a file under QUARANTINE_DIR: {$this->dir}/outside"]], [$this->sent, $this->log->lines]);
	}

	#[DataProvider('kinds')]
	public function testSendFails(bool $templated): void {
		$this->failure = new RuntimeException('Connection refused');
		$this->assertFalse($this->send($this->mailer(), $templated));
		$this->assertSame(['error [MailerService] Email failed: Connection refused'], $this->log->lines);
	}

	#[DataProvider('kinds')]
	public function testUnusableSender(bool $templated): void {
		$this->assertFalse($this->send($this->mailer(), $templated, 'not an address'));
		$this->assertSame([[], ['error [MailerService] Email failed: Email "not an address" does not comply with addr-spec of RFC 2822.']], [$this->sent, $this->log->lines]);
	}

	// the application's own views, as a cron renders them
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testApplicationViews(): void {
		define('CLI_MODE', true);
		$transport = new class ($this->record(...)) extends AbstractTransport {
			/** @param Closure(SentMessage): void $record */
			public function __construct(private Closure $record) {
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
		$this->assertTrue((new MailerService($this->log))->sendTemplatedEmail(
			from: 'rqwatch@example.org',
			recipients: ['alice@example.org'],
			subject: 'Released',
			template: 'mail/release.html.twig',
			text: 'Released',
			context: [
				'created_at' => '2026-11-26 10:00:00', 'subject' => 'Invoice', 'qid' => 'Q7', 'message_id' => '<m1@example.net>',
				'score' => 15.5, 'has_virus' => false, 'virus_name' => null, 'mime_from' => 'a@example.net',
				'rcpt_to' => 'alice@example.org', 'action' => 'reject', 'signature' => 'The postmaster',
			],
		));
		$html = (string) $this->email()->getHtmlBody();
		foreach (['Rqwatch', 'released from quarantine', 'a@example.net', "'Invoice'", 'Q7', '&lt;m1@example.net&gt;', '15.5', 'The postmaster'] as $part) {
			$this->assertStringContainsString($part, $html);
		}
		$this->assertSame([], $this->log->lines);
	}

	public function testUnknownTemplate(): void {
		$this->assertFalse($this->mailer()->sendTemplatedEmail('rqwatch@example.org', ['alice@example.org'], 'Report', 'missing.html.twig', 'Plain', []));
		$this->assertSame([[], ['error [MailerService] Email failed: Template "missing.html.twig" is not defined.']], [$this->sent, $this->log->lines]);
	}
}
