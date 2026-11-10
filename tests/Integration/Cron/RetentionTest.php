<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The retention crons against the instance's database and quarantine:
 cron:quarantine (files older than QUARANTINE_DAYS), cron:cleanupdb (rows
 older than DATABASE_DAYS) and cron:fuzzy_cleanup (fuzzy records unchanged
 for $fuzzy_learn_expire_days). Each test inserts its own back-dated rows;
 the crons select only rows past their cutoff, so the scoping fixtures (dated
 today) are never candidates. Ages are picked well clear of the cutoffs.
*/

namespace Tests\Integration\Cron;

use PHPUnit\Framework\Attributes\TestDox;
use Tests\Integration\IntegrationTestCase;

final class RetentionTest extends IntegrationTestCase
{
	#[TestDox('cron:quarantine without -d deletes nothing')]
	public function testQuarantineDryRun(): void {
		$old = self::mail(self::quarantineDays() + 20);
		self::cli('cron:quarantine', '-s');
		$this->assertStored($old, true);
	}

	#[TestDox('cron:quarantine -d: files past QUARANTINE_DAYS go and mail_stored is cleared, the row and newer mail stay')]
	public function testQuarantineDeletesOldFiles(): void {
		$old = self::mail(self::quarantineDays() + 20);
		$recent = self::mail(10);
		self::cli('cron:quarantine', '-d');
		$this->assertStored($old, false);
		$this->assertSame(1, self::valueInt('SELECT COUNT(*) FROM mail_logs WHERE id = ?', $old['id']), 'row kept');
		$this->assertSame(1, self::valueInt('SELECT COUNT(*) FROM mail_log_recipients WHERE mail_log_id = ?', $old['id']), 'recipients kept');
		$this->assertStored($recent, true);
		$this->assertFileExists(self::root() . '/q/101.eml', 'scoping fixture');
	}

	#[TestDox('cron:quarantine -d never deletes outside a mail\'s own directory under QUARANTINE_DIR')]
	public function testQuarantineStaysInside(): void {
		$root = self::root();
		$days = self::quarantineDays() + 30;
		// a location outside the quarantine, and one directly in its root
		$outside = self::mail($days, location: "{$root}/outside-" . bin2hex(random_bytes(4)) . '/mail.eml');
		$inRoot = self::mail($days, location: "{$root}/q/" . bin2hex(random_bytes(4)) . '.eml');

		self::cli('cron:quarantine', '-d');

		$this->assertStored($outside, true);
		$this->assertStored($inRoot, true);
		$this->assertFileExists("{$root}/q/101.eml", 'quarantine root emptied');
	}

	#[TestDox('cron:quarantine -d -l leaves other servers\' mail; without -l it goes')]
	public function testQuarantineLocalOnly(): void {
		$other = self::mail(self::quarantineDays() + 40, server: 'mx2');
		self::cli('cron:quarantine', '-d', '-l');
		$this->assertStored($other, true);
		self::cli('cron:quarantine', '-d');
		$this->assertStored($other, false);
	}

	#[TestDox('cron:quarantine -d: rows sharing one file lose mail_stored together; this server\'s row whose directory is gone is cleared, another server\'s is not')]
	public function testQuarantineClearsRowsWithoutTheirFile(): void {
		$days = self::quarantineDays() + 30;
		// two rows naming one file, as a reused queue ID did before each mail got its own directory
		$first = self::mail($days);
		$second = ['id' => self::insert('RETSHARED', $days, 'mx1', $first['file']), 'file' => $first['file']];
		// directories already gone: this server's, and another server's (on its own disk)
		$gone = self::mail($days);
		$goneOther = self::mail($days, server: 'mx2');
		foreach ([$gone, $goneOther] as $mail) {
			unlink($mail['file']);
			rmdir(dirname($mail['file']));
		}

		$output = self::cli('cron:quarantine', '-d', '-v');

		$this->assertStored($first, false);
		$this->assertStored($second, false);
		$this->assertStored($gone, false);
		$this->assertStringContainsString("Already gone {$gone['id']}", $output);
		$this->assertSame(1, self::valueInt('SELECT mail_stored FROM mail_logs WHERE id = ?', $goneOther['id']),
			'another server\'s mail: its directory is absent here by nature');
		$this->assertStringContainsString("Not deleted {$goneOther['id']}", $output);
		self::db()->prepare('DELETE FROM mail_logs WHERE id = ?')->execute([$goneOther['id']]);
	}

	#[TestDox('cron:quarantine -d -p prunes date and unknown/ dirs left empty, keeps non-empty ones')]
	public function testQuarantinePrune(): void {
		$q = self::root() . '/q';
		$days = self::quarantineDays() + 50;
		$day = date('Y-m-d', (int) strtotime("-{$days} days"));
		$group = self::mail($days, location: "{$q}/{$day}/unknown/" . bin2hex(random_bytes(6)) . '/mail.eml');
		$keepDay = date('Y-m-d', (int) strtotime('-' . ($days + 1) . ' days'));
		mkdir("{$q}/{$keepDay}", 0750, true);
		file_put_contents("{$q}/{$keepDay}/stray.txt", 'not in the database');

		self::cli('cron:quarantine', '-d', '-p');

		$this->assertStored($group, false);
		$this->assertDirectoryDoesNotExist("{$q}/{$day}", 'emptied date dir pruned');
		$this->assertFileExists("{$q}/{$keepDay}/stray.txt", 'non-empty date dir kept');
	}

	#[TestDox('cron:quarantine -d -p removes old mail directories no row names; keeps named, recent and unexpected ones; -p -s only lists')]
	public function testQuarantineRemovesUnreferenced(): void {
		$q = self::root() . '/q';
		$days = self::quarantineDays() + 60;
		$day = date('Y-m-d', (int) strtotime("-{$days} days"));
		$tag = strtoupper(bin2hex(random_bytes(4)));
		// left by spooled mails never imported: no row names them
		$orphan = self::rawMailDir("{$q}/{$day}/RETORPHAN{$tag}");
		$orphanUnknown = self::rawMailDir("{$q}/{$day}/unknown/" . bin2hex(random_bytes(6)));
		// named by a row, even one no longer claiming it stored
		$named = self::mail($days);
		self::db()->prepare('UPDATE mail_logs SET mail_stored = 0 WHERE id = ?')->execute([$named['id']]);
		// not what store_raw_mail() makes
		$odd = self::rawMailDir("{$q}/{$day}/RETODD{$tag}");
		file_put_contents("{$odd}/notes.txt", 'not a mail directory');
		// within QUARANTINE_DAYS: may still be waiting in the spool
		$recent = self::rawMailDir($q . '/' . date('Y-m-d', (int) strtotime('-2 days')) . "/RETRECENT{$tag}");

		try {
			$listed = self::cli('cron:quarantine', '-p', '-s');
			$orphanReal = realpath($orphan);
			$this->assertIsString($orphanReal);
			$this->assertStringContainsString("would remove: {$orphanReal}", $listed);
			$this->assertDirectoryExists($orphan, 'kept without -d');

			self::cli('cron:quarantine', '-d', '-p');

			$this->assertDirectoryDoesNotExist($orphan);
			$this->assertDirectoryDoesNotExist($orphanUnknown);
			$this->assertFileExists($named['file'], 'named by a row');
			$this->assertDirectoryExists($odd, 'not a mail directory');
			$this->assertDirectoryExists($recent, 'within QUARANTINE_DAYS');
		} finally {
			foreach ([$named['file'], "{$odd}/notes.txt", "{$odd}/mail.eml", "{$recent}/mail.eml"] as $file) {
				@unlink($file);
			}
			foreach ([dirname($named['file']), $odd, $recent] as $dir) {
				@rmdir($dir);
			}
			self::db()->prepare('DELETE FROM mail_logs WHERE id = ?')->execute([$named['id']]);
		}
	}

	#[TestDox('cron:quarantine -d -p in one run: old rows\' files, then unreferenced ones (a reused qid\'s too), then the emptied date dir')]
	public function testQuarantineCleansSweepsThenPrunes(): void {
		$q = self::root() . '/q';
		$days = self::quarantineDays() + 70;
		$day = date('Y-m-d', (int) strtotime("-{$days} days"));
		$stored = self::mail($days);
		$orphan = self::rawMailDir("{$q}/{$day}/RETORPHAN" . strtoupper(bin2hex(random_bytes(4))));
		// store_raw_mail()'s directory for a reused qid
		$reused = self::rawMailDir("{$q}/{$day}/RETORPHAN" . strtoupper(bin2hex(random_bytes(4))) . '-' . bin2hex(random_bytes(6)));

		self::cli('cron:quarantine', '-d', '-p');

		$this->assertStored($stored, false);
		$this->assertDirectoryDoesNotExist($orphan);
		$this->assertDirectoryDoesNotExist($reused);
		$this->assertDirectoryDoesNotExist("{$q}/{$day}", 'pruned in the same run');
	}

	#[TestDox('cron:cleanupdb refuses to run when DATABASE_DAYS is not above QUARANTINE_DAYS')]
	public function testCleanupDbRefusesUnsafeDays(): void {
		$old = self::row(self::databaseDays() + 20);
		self::withEnv(['DATABASE_DAYS' => (string) self::quarantineDays()], function (): void {
			$this->assertStringContainsString('DATABASE_DAYS must by higher', self::cli('cron:cleanupdb', '-d'));
		});
		$this->assertSame(1, self::valueInt('SELECT COUNT(*) FROM mail_logs WHERE id = ?', $old));
	}

	#[TestDox('cron:cleanupdb -d: rows past DATABASE_DAYS go with their child rows, a fuzzy record is kept unlinked, newer rows stay')]
	public function testCleanupDbDeletesOldRows(): void {
		$days = self::databaseDays() + 20;
		$old = [self::row($days), self::row($days), self::row($days)];
		$kept = self::row(self::databaseDays() - 20);
		$fuzzy = self::fuzzy(10, mailLogId: $old[0]);

		self::cli('cron:cleanupdb', '-s');
		$this->assertSame(3, self::countRows($old), 'deleted without -d');

		// batch of 1: the loop must keep going until every old row is gone
		self::cli('cron:cleanupdb', '-d', '-b', '1');

		$this->assertSame(0, self::countRows($old), 'old rows');
		foreach (['mail_log_recipients', 'mail_log_data', 'mail_log_viruses'] as $table) {
			$this->assertSame(0, self::valueInt(
				"SELECT COUNT(*) FROM {$table} WHERE mail_log_id IN (?, ?, ?)", ...$old), "{$table} rows of deleted mail");
		}
		$this->assertSame(1, self::countRows([$kept]), 'newer row');
		$this->assertSame(1, self::valueInt('SELECT COUNT(*) FROM mail_log_recipients WHERE mail_log_id = ?', $kept));
		$this->assertSame([1, null], [
			self::valueInt('SELECT COUNT(*) FROM mail_log_fuzzy WHERE id = ?', $fuzzy),
			self::value('SELECT mail_log_id FROM mail_log_fuzzy WHERE id = ?', $fuzzy),
		], 'fuzzy record kept, unlinked');
	}

	#[TestDox('cron:cleanupdb -d -l leaves other servers\' rows')]
	public function testCleanupDbLocalOnly(): void {
		$other = self::row(self::databaseDays() + 30, server: 'mx2');
		self::cli('cron:cleanupdb', '-d', '-l');
		$this->assertSame(1, self::countRows([$other]));
		self::cli('cron:cleanupdb', '-d');
		$this->assertSame(0, self::countRows([$other]));
	}

	#[TestDox('cron:fuzzy_cleanup -d: records unchanged past $fuzzy_learn_expire_days go with their hits, -l keeps other servers\', fresh ones stay')]
	public function testFuzzyCleanup(): void {
		$expired = self::fuzzy(120);
		$otherServer = self::fuzzy(120, server: 'mx2');
		$fresh = self::fuzzy(10);
		$hits = 'SELECT COUNT(*) FROM mail_log_fuzzy_hits WHERE fuzzy_id = ?';

		self::cli('cron:fuzzy_cleanup', '-s');
		$this->assertSame(3, self::countFuzzy([$expired, $otherServer, $fresh]), 'deleted without -d');

		self::cli('cron:fuzzy_cleanup', '-d', '-l');
		$this->assertSame(0, self::countFuzzy([$expired]), 'expired');
		$this->assertSame(0, self::valueInt($hits, $expired), 'hits of the expired record');
		$this->assertSame(1, self::countFuzzy([$otherServer]), 'other server with -l');
		$this->assertSame(1, self::countFuzzy([$fresh]), 'fresh');
		$this->assertSame(1, self::valueInt($hits, $fresh), 'hits of the fresh record');

		self::cli('cron:fuzzy_cleanup', '-d');
		$this->assertSame(0, self::countFuzzy([$otherServer]), 'other server without -l');
		$this->assertSame(1, self::countFuzzy([$fresh]), 'fresh');
	}

	// a mail directory as store_raw_mail() makes it, with no row
	private static function rawMailDir(string $dir): string {
		mkdir($dir, 0750, true);
		file_put_contents("{$dir}/mail.eml", "Subject: unreferenced\r\n\r\nbody\r\n");
		return $dir;
	}

	private static function quarantineDays(): int {
		return self::envDays('QUARANTINE_DAYS');
	}

	private static function databaseDays(): int {
		return self::envDays('DATABASE_DAYS');
	}

	private static function envDays(string $key): int {
		$env = (string) file_get_contents(self::root() . '/.env');
		if (!preg_match('/^' . $key . '=(\d+)/m', $env, $m)) {
			self::fail("No {$key} in .env");
		}
		return (int) $m[1];
	}

	/**
	 * A stored mail $days old: a row and its file, by default where
	 * store_raw_mail() puts it (q/<day>/<qid>/mail.eml).
	 * @return array{id: int, file: string}
	 */
	private static function mail(int $days, string $server = 'mx1', ?string $location = null): array {
		$qid = 'RET' . strtoupper(bin2hex(random_bytes(5)));
		$day = date('Y-m-d', (int) strtotime("-{$days} days"));
		$location ??= self::root() . "/q/{$day}/{$qid}/mail.eml";
		if (!is_dir(dirname($location))) {
			mkdir(dirname($location), 0750, true);
		}
		file_put_contents($location, "Subject: {$qid}\r\n\r\nbody\r\n");
		$id = self::insert($qid, $days, $server, $location);
		return ['id' => $id, 'file' => $location];
	}

	/** A mail_logs row $days old, not stored, with recipient, data and virus rows. */
	private static function row(int $days, string $server = 'mx1'): int {
		return self::insert('RET' . strtoupper(bin2hex(random_bytes(5))), $days, $server, null);
	}

	private static function insert(string $qid, int $days, string $server, ?string $location): int {
		$db = self::db();
		$created = date('Y-m-d H:i:s', (int) strtotime("-{$days} days"));
		$db->prepare('INSERT INTO mail_logs (qid, server, subject, score, action, ip, mail_from, mime_from, rcpt_to, mime_to, size, mail_stored, mail_location, created_at)
			VALUES (?, ?, ?, 15, \'reject\', \'192.0.2.20\', \'old@example.net\', \'old@example.net\', \'carol@example.org\', \'carol@example.org\', 100, ?, ?, ?)')
			->execute([$qid, $server, $qid, $location !== null ? 1 : 0, $location, $created]);
		$id = (int) $db->lastInsertId();
		$db->prepare('INSERT INTO mail_log_recipients (mail_log_id, recipient_email) VALUES (?, \'carol@example.org\')')->execute([$id]);
		$db->prepare('INSERT INTO mail_log_data (mail_log_id, headers, symbols) VALUES (?, ?, \'{}\')')->execute([$id, "Subject: {$qid}"]);
		$db->prepare('INSERT INTO mail_log_viruses (mail_log_id, created_day, av, name) VALUES (?, ?, \'CLAM_VIRUS\', \'Eicar-Test-Signature\')')
			->execute([$id, substr($created, 0, 10)]);
		return $id;
	}

	/** A fuzzy record unchanged for $days, with one hit on scoping mail 101. */
	private static function fuzzy(int $days, string $server = 'mx1', ?int $mailLogId = null): int {
		$db = self::db();
		$at = date('Y-m-d H:i:s', (int) strtotime("-{$days} days"));
		$db->prepare('INSERT INTO mail_log_fuzzy (mail_log_id, qid, api_server, flag, weight, hashes, learned_by, created_at, updated_at)
			VALUES (?, ?, ?, 1, 1, \'[]\', \'retention-test\', ?, ?)')
			->execute([$mailLogId, 'RETFUZZY', $server, $at, $at]);
		$id = (int) $db->lastInsertId();
		$db->prepare('INSERT INTO mail_log_fuzzy_hits (fuzzy_id, mail_log_id) VALUES (?, 101)')->execute([$id]);
		return $id;
	}

	/** @param array{id: int, file: string} $mail */
	private function assertStored(array $mail, bool $stored): void {
		$this->assertSame($stored ? 1 : 0, self::valueInt('SELECT mail_stored FROM mail_logs WHERE id = ?', $mail['id']), 'mail_stored');
		if ($stored) {
			$this->assertFileExists($mail['file']);
		} else {
			$this->assertFileDoesNotExist($mail['file']);
			$this->assertDirectoryDoesNotExist(dirname($mail['file']), 'the mail\'s directory');
		}
	}

	/** @param list<int> $ids */
	private static function countRows(array $ids): int {
		$in = implode(',', array_fill(0, count($ids), '?'));
		return self::valueInt("SELECT COUNT(*) FROM mail_logs WHERE id IN ({$in})", ...$ids);
	}

	/** @param list<int> $ids */
	private static function countFuzzy(array $ids): int {
		$in = implode(',', array_fill(0, count($ids), '?'));
		return self::valueInt("SELECT COUNT(*) FROM mail_log_fuzzy WHERE id IN ({$in})", ...$ids);
	}
}
