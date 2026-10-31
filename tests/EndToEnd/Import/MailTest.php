<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis
 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Verdicts, quarantine, qid sources and recipients.
*/

namespace Tests\EndToEnd\Import;

use PHPUnit\Framework\Attributes\TestDox;
use Tests\EndToEnd\EndToEndTestCase;

final class MailTest extends EndToEndTestCase
{
	#[TestDox('Clean mail: logged with UTF-8 subject and every recipient, not quarantined')]
	public function testCleanMailIsLoggedNotStored(): void {
		$subject = 'Καλημέρα κόσμε';
		[, $reply, $row] = $this->import('clean', '=?UTF-8?B?' . base64_encode($subject) . '?=',
			rcpts: [self::RCPT, 'Bob@RQWATCH.test']);
		$this->assertSame('no action', $reply['action'], 'fixture: rspamd verdict');

		$this->assertSame($subject, $row['subject']);
		$this->assertSame('alice@rqwatch.test, bob@rqwatch.test', $row['rcpt_to']);
		$this->assertSame(['alice@rqwatch.test', 'bob@rqwatch.test'], self::recipients((int) $row['id']));
		$this->assertSame(0, (int) $row['has_virus']);
		$this->assertNotQuarantined($row);
	}

	#[TestDox('Reject by score: quarantined byte for byte, LOCAL_* symbol recorded as a hit')]
	public function testRejectedMailIsQuarantined(): void {
		[, $reply, $row] = $this->import('spam');
		$this->assertSame('reject', $reply['action'], 'fixture: rspamd verdict');

		$this->assertSame(0, (int) $row['has_virus']);
		$this->assertQuarantined($row);
		$this->assertHit((int) $row['id'], 'LOCAL_IMPORT_TEST_SPAM', 0, 0, 50.0);
	}

	#[TestDox('Discard by score: contrib actions.conf threshold (12) below reject')]
	public function testScoreDiscardIsQuarantined(): void {
		[, $reply, $row] = $this->import('discard');
		$this->assertSame('discard', $reply['action'], 'fixture: contrib actions.conf');

		$this->assertQuarantined($row);
	}

	#[TestDox('Virus: discarded by contrib force_actions.conf, virus recorded')]
	public function testVirusIsDiscardedAndRecorded(): void {
		[, $reply, $row] = $this->import('virus');
		$this->assertSame('discard', $reply['action'], 'fixture: contrib force_actions.conf and actions.conf');

		$this->assertQuarantined($row);
		$this->assertVirus($row);
	}

	#[TestDox('No Queue-Id: the qid comes from the X-Rspamd-Queue-Id header')]
	public function testQidFromHeader(): void {
		[, , $row] = $this->import('spam', qid: self::QID_HEADER);

		$this->assertQuarantined($row);
		$this->assertStringContainsString("/{$row['qid']}/mail.eml", (string) $row['mail_location']);
	}

	#[TestDox('No qid at all: logged as unknown, quarantined under unknown/')]
	public function testUnknownQid(): void {
		[, , $row] = $this->import('spam', qid: self::QID_NONE);

		$this->assertQuarantined($row);
		$this->assertMatchesRegularExpression('#/\d{4}-\d{2}-\d{2}/unknown/[^/]+/mail\.eml$#', (string) $row['mail_location']);
	}

	#[TestDox('60 recipients: rcpt_to and mime_to truncated, every recipient listed')]
	public function testRecipientOverflow(): void {
		$rcpts = [];
		for ($i = 1; $i <= 60; $i++) {
			$rcpts[] = sprintf('recipient-%02d-with-a-long-local-part@rqwatch.test', $i);
		}
		[, , $row] = $this->import('clean', rcpts: $rcpts, to: array_slice($rcpts, 0, 10));

		$this->assertSame(mb_substr(implode(', ', $rcpts), 0, 1024), $row['rcpt_to']);
		$this->assertSame($rcpts, self::recipients((int) $row['id']));
	}
}
