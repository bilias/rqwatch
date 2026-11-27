<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 MailTokenService on RecordingDatabase. Links are on with
 NOTIFICATION_RELEASE_LINKS, one-time unless notification_links_one_time
 is false. issueToken(): a new random token per call, only its sha256
 stored, for the recipient trimmed and in lower case (as the recipients'
 foreign key holds it); the row for the pair replaced, not a second one
 added. No mail id or no recipient: refused, nothing asked. A database
 error: logged, no token. findValidToken(): the token trimmed, anything
 else than 64 lower-case hex digits never reaches the query; found by its
 hash, only while its mail is stored, with the mail loaded. redeemToken():
 the hash replaced by a new random one, given only when this request
 changed the row. deleteToken(), deleteTokensForMails(): whether, and
 how many, rows went; nothing asked for no mails. Each database error is
 logged and answered as nothing found or done.
*/

namespace Tests\Unit\Services;

use App\Configuration\Config;
use App\Core\App;
use App\Core\AppContainer;
use App\Models\MailLog;
use App\Models\MailLogToken;
use App\Services\MailTokenService;
use Illuminate\Database\Eloquent\Model;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Unit\RecordingDatabase;
use Tests\Unit\UnitTestCase;

final class MailTokenServiceTest extends UnitTestCase
{
	private RecordingDatabase $db;

	private const string HEX = '/^[0-9a-f]{64}$/D';
	private const string TOKEN = '3f2a6c1e9b8d7f0a5c4e3d2b1a09f8e7d6c5b4a39281706f5e4d3c2b1a098f7e';

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->db = new RecordingDatabase($this->createStub(PDO::class));
		$this->db->capsule->bootEloquent();
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, $this->db->capsule, null, null, false));
	}

	#[\Override]
	protected function tearDown(): void {
		Model::unsetConnectionResolver();
		RecordingDatabase::forget();
		parent::tearDown();
	}

	private static function token(string $hash = 'a1b2'): MailLogToken {
		$token = new MailLogToken();
		$token->setRawAttributes(['token_hash' => $hash, 'mail_log_id' => 7, 'recipient_email' => 'alice@example.org'], true);
		$token->exists = true;
		return $token;
	}

	private function goneAway(): void {
		$this->db->answer = fn (): never => throw new RuntimeException('server has gone away');
		$this->db->affect = fn (): never => throw new RuntimeException('server has gone away');
	}

	public function testSettings(): void {
		$this->setEnv('NOTIFICATION_RELEASE_LINKS', null);
		$off = [MailTokenService::isEnabled(), MailTokenService::isOneTime()];
		$this->setEnv('NOTIFICATION_RELEASE_LINKS', 'true');
		Config::set('notification_links_one_time', false);
		$this->assertSame([[false, true], [true, false]], [$off, [MailTokenService::isEnabled(), MailTokenService::isOneTime()]]);
	}

	public function testIssue(): void {
		$service = new MailTokenService();
		// the first mail's id is 1
		$token = $service->issueToken(1, " Alice@Example.ORG\n");
		$this->assertIsString($token);
		$this->assertMatchesRegularExpression(self::HEX, $token);
		$this->assertSame(
			[['select * from `mail_log_tokens` where (`mail_log_id` = ? and `recipient_email` = ?) limit 1', [1, 'alice@example.org']]],
			$this->db->selects
		);
		$this->assertSame([['mail_log_id' => 1, 'recipient_email' => 'alice@example.org', 'token_hash' => hash('sha256', $token)]], $this->db->rows('mail_log_tokens'));
		$this->assertNotSame($token, $service->issueToken(1, 'alice@example.org'));
		$this->assertSame([], $this->log->lines);
	}

	// a re-notification: the pair's row gets the new hash
	public function testIssueAgain(): void {
		$this->db->answer = fn (): array => [['token_hash' => 'a1b2', 'mail_log_id' => 7, 'recipient_email' => 'alice@example.org']];
		$this->db->affect = fn (): int => 1;
		$token = (new MailTokenService())->issueToken(7, 'alice@example.org');
		$this->assertIsString($token);
		$this->assertSame([], $this->db->rows('mail_log_tokens'));
		$this->assertSame([['update `mail_log_tokens` set `token_hash` = ? where `token_hash` = ?', [hash('sha256', $token), 'a1b2']]], $this->db->changes);
	}

	/** @return array<string, array{int, string}> */
	public static function refused(): array {
		return [
			'no mail id' => [0, 'alice@example.org'],
			'a negative one' => [-1, 'alice@example.org'],
			'no recipient' => [7, " \t"],
		];
	}

	#[DataProvider('refused')]
	public function testIssueRefused(int $id, string $recipient): void {
		$this->assertNull((new MailTokenService())->issueToken($id, $recipient));
		$this->assertSame([[], []], [$this->db->selects, $this->db->rows('mail_log_tokens')]);
		$this->assertSame(["warning [MailTokenService] refusing to issue token for mail id '{$id}' and empty recipient"], $this->log->lines);
	}

	public function testIssueFailed(): void {
		$this->goneAway();
		$this->assertNull((new MailTokenService())->issueToken(7, 'alice@example.org'));
		$this->assertSame(['error [MailTokenService] failed issuing token for mail id 7: server has gone away'], $this->log->lines);
	}

	public function testFind(): void {
		$hash = hash('sha256', self::TOKEN);
		$this->db->answer = fn (string $sql): array => str_contains($sql, 'from `mail_log_tokens`')
			? [['token_hash' => $hash, 'mail_log_id' => 7, 'recipient_email' => 'alice@example.org']]
			: [['id' => 7, 'qid' => 'Q7', 'mail_stored' => 1]];
		$found = (new MailTokenService())->findValidToken(' ' . self::TOKEN . "\n");
		$this->assertInstanceOf(MailLogToken::class, $found);
		$this->assertSame([$hash, 'alice@example.org'], [$found->token_hash, $found->recipient_email]);
		$this->assertTrue($found->relationLoaded('mailLog'));
		$this->assertInstanceOf(MailLog::class, $found->mailLog);
		$this->assertSame('Q7', $found->mailLog->qid);
		$this->assertSame([
			['select * from `mail_log_tokens` where `token_hash` = ? and exists (select * from `mail_logs` where `mail_log_tokens`.`mail_log_id` = `mail_logs`.`id` and `mail_stored` = ?) limit 1', [$hash, 1]],
			['select * from `mail_logs` where `mail_logs`.`id` in (7)', []],
		], $this->db->selects);
	}

	public function testNotFound(): void {
		$this->assertNull((new MailTokenService())->findValidToken(self::TOKEN));
		$this->assertCount(1, $this->db->selects);
	}

	/** @return array<string, array{string}> */
	public static function malformed(): array {
		return [
			'empty' => [''],
			'short' => [substr(self::TOKEN, 1)],
			'long' => [self::TOKEN . 'a'],
			'upper case' => [strtoupper(self::TOKEN)],
			'not hex' => ['g' . substr(self::TOKEN, 1)],
			'two lines' => [self::TOKEN . "\n" . self::TOKEN],
		];
	}

	#[DataProvider('malformed')]
	public function testMalformed(string $token): void {
		$service = new MailTokenService();
		$this->assertSame([null, null], [$service->findValidToken($token), $service->findValidTokenByHash($token)]);
		$this->assertSame([], $this->db->selects);
	}

	// a hash ending in a newline is no hash, though trim() would take it
	public function testHashWithNewline(): void {
		$this->assertNull((new MailTokenService())->findValidTokenByHash(self::TOKEN . "\n"));
		$this->assertSame([], $this->db->selects);
	}

	public function testFindFailed(): void {
		$this->goneAway();
		$this->assertNull((new MailTokenService())->findValidTokenByHash(self::TOKEN));
		$this->assertSame(['error [MailTokenService] token lookup failed: server has gone away'], $this->log->lines);
	}

	public function testRedeem(): void {
		$this->db->affect = fn (): int => 1;
		$hash = (new MailTokenService())->redeemToken(self::token());
		$this->assertIsString($hash);
		$this->assertMatchesRegularExpression(self::HEX, $hash);
		$this->assertSame([['update `mail_log_tokens` set `token_hash` = ? where `token_hash` = ?', [$hash, 'a1b2']]], $this->db->changes);
	}

	// another request redeemed it first
	public function testRedeemedAlready(): void {
		$this->assertNull((new MailTokenService())->redeemToken(self::token()));
		$this->assertCount(1, $this->db->changes);
	}

	public function testRedeemFailed(): void {
		$this->goneAway();
		$this->assertNull((new MailTokenService())->redeemToken(self::token()));
		$this->assertSame(['error [MailTokenService] failed redeeming token for mail id 7: server has gone away'], $this->log->lines);
	}

	public function testDelete(): void {
		$service = new MailTokenService();
		$this->db->affect = fn (): int => 1;
		$deleted = $service->deleteToken(self::token());
		$this->db->affect = null;
		$this->assertSame([true, false], [$deleted, $service->deleteToken(self::token())]);
		$this->assertSame(array_fill(0, 2, ['delete from `mail_log_tokens` where `token_hash` = ?', ['a1b2']]), $this->db->changes);
	}

	public function testDeleteFailed(): void {
		$this->goneAway();
		$this->assertFalse((new MailTokenService())->deleteToken(self::token()));
		$this->assertSame(['error [MailTokenService] failed deleting token for mail id 7: server has gone away'], $this->log->lines);
	}

	public function testDeleteForMails(): void {
		$this->db->affect = fn (): int => 3;
		$service = new MailTokenService();
		$this->assertSame([3, 0], [$service->deleteTokensForMails([4, 9]), $service->deleteTokensForMails([])]);
		$this->assertSame([['delete from `mail_log_tokens` where `mail_log_id` in (?, ?)', [4, 9]]], $this->db->changes);
	}

	public function testDeleteForMailsFailed(): void {
		$this->goneAway();
		$this->assertSame(0, (new MailTokenService())->deleteTokensForMails([4]));
		$this->assertSame(['error [MailTokenService] failed deleting tokens: server has gone away'], $this->log->lines);
	}
}
