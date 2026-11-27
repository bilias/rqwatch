<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The release and get-mail APIs past their checks, on RecordingDatabase,
 MailerService's transport seam recording the mail sent. Release: a mail
 not found is a 400 "Message not found"; no mailer is a 503 "Mail release
 not configured"; the stored mail released to the addresses given (trimmed)
 is "Message Released", logged with who asked and the addresses; a release
 that fails is a 500. Get: a mail not found is a 400 "Message not found";
 one whose file is not a file under QUARANTINE_DIR a 400 "File not found";
 else the file is the answer, logged.
*/

namespace Tests\Unit\Api;

use App\Api\GetMailApi;
use App\Api\ReleaseMailApi;
use App\Configuration\Config;
use App\Core\App;
use App\Core\AppContainer;
use App\Core\Database\MigrationStatus;
use App\Services\MailerService;
use Illuminate\Database\Eloquent\Model;
use PDO;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Tests\Unit\RecordingDatabase;

final class MailApiStoreTest extends ApiTestCase
{
	private RecordingDatabase $db;

	private string $dir = '';

	/** @var list<array<string, mixed>> the mail_logs rows found */
	private array $mails = [];

	/** @var list<list<string>> the addresses of each mail sent */
	private array $sent = [];

	private bool $failSend = false;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->db = new RecordingDatabase($this->createStub(PDO::class));
		$this->db->capsule->bootEloquent();
		$this->db->affect = fn (): int => 1;
		$this->db->answer = fn (string $sql): array => match (true) {
			str_contains($sql, 'from `mail_logs`') => $this->mails,
			str_contains($sql, 'from `mail_log_recipients`') => [['mail_log_id' => 7, 'recipient_email' => 'alice@example.org']],
			default => [],
		};
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, $this->db->capsule, new MigrationStatus(null, $this->log), null, false));
		$this->dir = sys_get_temp_dir() . '/rqwatch-mas-' . bin2hex(random_bytes(4));
		mkdir("{$this->dir}/Q7", 0777, true);
		file_put_contents("{$this->dir}/Q7/Q7", "Subject: Held\r\n\r\nHeld body\r\n");
		$this->setEnv('QUARANTINE_DIR', $this->dir);
		$this->setEnv('MAILER_DSN', 'null://null');
		$this->setEnv('MAILER_FROM', 'rqwatch@example.org');
		Config::set('release_mail_subject', 'Released');
		Config::set('mail_signature', 'The postmaster');
		$this->mails = [[
			'id' => 7, 'qid' => 'Q7', 'server' => 'mx1', 'subject' => 'Held', 'score' => 5.0, 'has_virus' => 0, 'mail_from' => 'b@example.net',
			'mime_from' => 'a@example.net', 'action' => 'reject', 'mail_stored' => 1, 'mail_location' => "{$this->dir}/Q7/Q7", 'created_at' => '2026-11-26 10:00:00',
		]];
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
		@unlink("{$this->dir}/Q7/Q7");
		@rmdir("{$this->dir}/Q7");
		@rmdir($this->dir);
		Model::unsetConnectionResolver();
		RecordingDatabase::forget();
		parent::tearDown();
	}

	private function record(SentMessage $message): void {
		if ($this->failSend) {
			throw new RuntimeException('550 rejected');
		}
		$email = $message->getOriginalMessage();
		$this->assertInstanceOf(Email::class, $email);
		$this->sent[] = array_values(array_map(fn (Address $address): string => $address->getAddress(), $email->getTo()));
	}

	private function release(): Response {
		$post = ['remote_user' => 'alice@example.org', 'id' => '7', 'email' => [' bob@example.org', 'carol@example.org ']];
		return $this->answer(fn () => (new ReleaseMailApi(Request::create('/api/release_mail.php', 'POST', $post)))->handle());
	}

	private function get(): Response {
		$post = ['remote_user' => 'alice@example.org', 'id' => '7'];
		return $this->answer(fn () => (new GetMailApi(Request::create('/api/get_mail.php', 'POST', $post)))->handle());
	}

	public function testRelease(): void {
		$response = $this->release();
		$this->assertAnswer(200, 'Message Released', $response);
		$this->assertSame([['bob@example.org', 'carol@example.org']], $this->sent);
		$this->assertStringStartsWith("info Message Q7 released to 'bob@example.org, carol@example.org' via ReleaseMailApi from 'alice@example.org' by '192.0.2.7' | ", $this->log->lines[0] ?? '');
		$this->assertCount(2, array_filter($this->log->lines, fn (string $line): bool => str_starts_with($line, 'info Message Q7 released')));
	}

	public function testReleaseNotFound(): void {
		$this->mails = [];
		$this->assertAnswer(400, 'Message not found', $this->release());
		$this->assertContains('warning [ReleaseMailApi] alice@example.org via 192.0.2.7 requested release of mail with id 7 which does not exist', $this->log->lines);
	}

	public function testReleaseWithoutMailer(): void {
		$this->setEnv('MAILER_FROM', null);
		$this->assertAnswer(503, 'Mail release not configured', $this->release());
		$this->assertContains('error [ReleaseMailApi] Message Q7 release refused via API by 192.0.2.7: MAILER_DSN or MAILER_FROM is not set in .env', $this->log->lines);
		$this->assertSame([], $this->sent);
	}

	public function testReleaseFails(): void {
		$this->failSend = true;
		$this->assertAnswer(500, 'Message Release Failed', $this->release());
		$failed = array_values(array_filter($this->log->lines, fn (string $line): bool => str_starts_with($line, 'error [ReleaseMailApi] Message Q7 failed to be released via API by 192.0.2.7 | ')));
		$this->assertCount(2, $failed);
	}

	public function testGet(): void {
		$response = $this->get();
		$this->assertInstanceOf(BinaryFileResponse::class, $response);
		$this->assertSame([200, realpath("{$this->dir}/Q7/Q7")], [$response->getStatusCode(), $response->getFile()->getRealPath()]);
		$line = "info [GetMailApi] 'alice@example.org' via '192.0.2.7' requested mail Q7";
		$this->assertSame([$line, $line], $this->log->lines);
	}

	public function testGetNotFound(): void {
		$this->mails = [];
		$this->assertAnswer(400, 'Message not found', $this->get());
		$line = 'warning [GetMailApi] alice@example.org via 192.0.2.7 requested mail with id 7 which does not exist';
		$this->assertSame([$line, $line], array_slice($this->log->lines, -2));
	}

	// a file outside the quarantine, or none: not served
	public function testGetNotAQuarantinedFile(): void {
		$this->mails[0]['mail_location'] = '/etc/passwd';
		$this->assertAnswer(400, 'File not found', $this->get());
		$this->assertContains("critical [GetMailApi] alice@example.org via 192.0.2.7 requested mail Q7 where local file '/etc/passwd' is not a file under QUARANTINE_DIR", $this->log->lines);
	}
}
