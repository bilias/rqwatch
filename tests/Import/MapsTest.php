<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis
 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Blacklists and whitelists from contrib, with the hit credited to the map entry.
*/

namespace Tests\Import;

use PHPUnit\Framework\Attributes\TestDox;

final class MapsTest extends ImportTestCase
{
	// MapHitService::ENTRY_COMBINED
	private const ENTRY_COMBINED = 1;

	#[TestDox('IP blacklist (CIDR entry): rejected, hit credited to the entry')]
	public function testIpBlacklistRejects(): void {
		[, $reply, $row] = $this->import('clean', ip: '198.51.100.7');
		$this->assertSame('reject', $reply['action'], 'fixture: contrib multimap.conf');

		$this->assertQuarantined($row);
		$this->assertMapHit($row, 'RQWATCH_IP_BLACKLIST', 'ip_blacklist');
	}

	#[TestDox('SMTP From blacklist: discarded, hit credited to the entry')]
	public function testSmtpFromBlacklistDiscards(): void {
		[, $reply, $row] = $this->import('clean', from: 'smtp-bl@sender.test');
		$this->assertSame('discard', $reply['action'], 'fixture: contrib multimap.conf');

		$this->assertQuarantined($row);
		$this->assertMapHit($row, 'RQWATCH_SMTP_FROM_BLACKLIST', 'mail_from_blacklist');
	}

	#[TestDox('MIME From blacklist: discarded, hit credited to the entry')]
	public function testMimeFromBlacklistDiscards(): void {
		[, $reply, $row] = $this->import('clean', mimeFrom: 'Listed <mime-bl@sender.test>');
		$this->assertSame('discard', $reply['action'], 'fixture: contrib multimap.conf');

		$this->assertQuarantined($row);
		$this->assertMapHit($row, 'RQWATCH_MIME_FROM_BLACKLIST', 'mime_from_blacklist');
	}

	#[TestDox('SMTP From/RCPT To blacklist (Lua map): rejected, hit credited to the entry')]
	public function testSmtpFromRcptToBlacklistRejects(): void {
		[, $reply, $row] = $this->import('clean', from: 'pair-bl@sender.test', rcpts: [self::RCPT, 'bob@rqwatch.test']);
		$this->assertSame('reject', $reply['action'], 'fixture: contrib lua.local.d and groups.conf');

		$this->assertQuarantined($row);
		$this->assertMapHit($row, 'RQWATCH_SMTP_FROM_RCPT_TO_BLACKLIST', 'mail_from_rcpt_to_blacklist');
	}

	#[TestDox('SMTP From whitelist: spam accepted, not quarantined, hit credited')]
	public function testWhitelistAcceptsSpam(): void {
		[, $reply, $row] = $this->import('spam', from: 'smtp-wl@sender.test');
		$this->assertSame('no action', $reply['action'], 'fixture: contrib multimap.conf');

		$this->assertNotQuarantined($row);
		$this->assertMapHit($row, 'RQWATCH_SMTP_FROM_WHITELIST', 'mail_from_whitelist');
	}

	#[TestDox('SMTP From whitelist with a virus: still discarded by force_actions.conf')]
	public function testWhitelistedVirusIsDiscarded(): void {
		[, $reply, $row] = $this->import('virus', from: 'smtp-wl@sender.test');
		$this->assertSame('discard', $reply['action'], 'fixture: contrib force_actions.conf');

		$this->assertQuarantined($row);
		$this->assertVirus($row);
		$this->assertMapHit($row, 'RQWATCH_SMTP_FROM_WHITELIST', 'mail_from_whitelist');
	}

	/** @param array<string, mixed> $row */
	private function assertMapHit(array $row, string $symbol, string $map): void {
		$entries = self::query('SELECT id FROM maps_combined WHERE map_name = ?', [$map]);
		$this->assertCount(1, $entries, "fixture: one {$map} entry");
		$this->assertHit((int) $row['id'], $symbol, self::ENTRY_COMBINED, (int) $entries[0]['id']);
	}
}
