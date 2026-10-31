<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis
 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Fuzzy learn, hit and unlearn through the fuzzy API.
*/

namespace Tests\EndToEnd\Fuzzy;

use PHPUnit\Framework\Attributes\TestDox;
use RuntimeException;
use Tests\EndToEnd\EndToEndTestCase;

final class FuzzyTest extends EndToEndTestCase
{
	#[TestDox('Fuzzy: learned through the fuzzy API, a later copy hits it, the hit is recorded')]
	public function testFuzzyLearnAndHit(): void {
		$body = self::fuzzyBody('learn-and-hit');
		[, , $learned] = $this->import('spam', body: $body);
		$this->assertSame('Message learned', self::fuzzyApi('learn', (int) $learned['id'], 11));

		$records = self::query('SELECT id, flag, hashes FROM mail_log_fuzzy WHERE mail_log_id = ?', [(int) $learned['id']]);
		$this->assertCount(1, $records, 'mail_log_fuzzy record');
		$this->assertSame(11, (int) $records[0]['flag']);
		$hashes = json_decode((string) $records[0]['hashes'], true, 512, JSON_THROW_ON_ERROR);
		$this->assertNotEmpty($hashes, 'learned hashes');

		self::waitForFuzzy($body, true);
		[, $reply, $copy] = $this->import('clean', body: $body);
		$this->assertArrayHasKey('RQWATCH_FUZZY_DENIED', $reply['symbols'], 'fixture: contrib fuzzy_check.conf');

		$data = self::query('SELECT fuzzy_hashes FROM mail_log_data WHERE mail_log_id = ?', [(int) $copy['id']]);
		$matched = json_decode((string) $data[0]['fuzzy_hashes'], true, 512, JSON_THROW_ON_ERROR);
		$this->assertNotEmpty(array_intersect($hashes, $matched), 'the copy carries a learned hash');

		$record = self::query('SELECT hits, last_hit_at FROM mail_log_fuzzy WHERE id = ?', [(int) $records[0]['id']]);
		$this->assertSame(1, (int) $record[0]['hits']);
		$this->assertNotNull($record[0]['last_hit_at']);
		$this->assertSame(
			[(int) $copy['id']],
			array_map('intval', array_column(self::query(
				'SELECT mail_log_id FROM mail_log_fuzzy_hits WHERE fuzzy_id = ?', [(int) $records[0]['id']]
			), 'mail_log_id')),
			'hit mails'
		);
	}

	#[TestDox('Fuzzy: unlearned through the fuzzy API, record gone and copies no longer hit')]
	public function testFuzzyUnlearn(): void {
		$body = self::fuzzyBody('unlearn');
		[, , $learned] = $this->import('spam', body: $body);
		$this->assertSame('Message learned', self::fuzzyApi('learn', (int) $learned['id'], 11));
		$id = (int) self::query('SELECT id FROM mail_log_fuzzy WHERE mail_log_id = ?', [(int) $learned['id']])[0]['id'];
		self::waitForFuzzy($body, true);

		$this->assertSame('Message unlearned', self::fuzzyApi('unlearn', $id));
		$this->assertSame([], self::query('SELECT id FROM mail_log_fuzzy WHERE id = ?', [$id]), 'record deleted');
		self::waitForFuzzy($body, false);
	}

	/** Text of over 1 KB, which rspamd needs to hash a mail; distinct per seed. */
	private static function fuzzyBody(string $seed): string {
		$words = [];
		for ($i = 0; count($words) < 300; $i++) {
			$words[] = substr(sha1("{$seed}-{$i}"), 0, 4 + $i % 5);
		}
		return wordwrap(implode(' ', $words), 72, "\r\n");
	}

	/** Calls this instance's fuzzy API as a web node would; returns the reply body, fails unless 200. */
	private static function fuzzyApi(string $action, int $id, ?int $flag = null): string {
		$fields = ['remote_user' => 'import-test', 'action' => $action, 'id' => (string) $id];
		if ($flag !== null) {
			$fields['flag'] = (string) $flag;
		}
		$ch = curl_init(rtrim((string) getenv('E2E_WEB_URL'), '/') . '/api/fuzzy_mail.php');
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POSTFIELDS => http_build_query($fields),
			CURLOPT_USERPWD => getenv('E2E_MAIL_API_USER') . ':' . getenv('E2E_MAIL_API_PASS'),
			CURLOPT_TIMEOUT => 30,
		]);
		$body = (string) curl_exec($ch);
		$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		if ($status !== 200) {
			throw new RuntimeException("fuzzy API {$action} of {$id} answered {$status}: {$body}");
		}
		return $body;
	}

	/**
	 * The fuzzy storage applies changes in batches (sync = 1s in the test):
	 * probe until a copy of $body does, or no longer does, hit the learned hash.
	 */
	private static function waitForFuzzy(string $body, bool $hits): void {
		$deadline = microtime(true) + self::WAIT_SECONDS;
		do {
			$qid = 'PROBE' . strtoupper(bin2hex(random_bytes(4)));
			$headers = self::scanHeaders($qid, self::IP, self::MAIL_FROM, [self::RCPT]);
			$headers[] = 'Settings: {symbols_disabled = ["EXPORT_METADATA_RQWATCH"]}';
			$reply = self::scan(self::message($qid, 'Probe', 'clean', self::MIME_FROM, body: $body), $headers);
			if (isset($reply['symbols']['RQWATCH_FUZZY_DENIED']) === $hits) {
				return;
			}
			usleep(500000);
		} while (microtime(true) < $deadline);

		throw new RuntimeException('Fuzzy storage did not ' . ($hits ? 'learn' : 'forget') . ' the hash within ' . self::WAIT_SECONDS . 's');
	}
}
