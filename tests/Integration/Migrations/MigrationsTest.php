<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Every migration, run with its own command in Migrations::MIGRATIONS
 order on a database of its own: the v1.6.10 schema with mock data. Each
 must exit 0, record itself completed and make its schema change, and
 after each one all the mock data must be what it should be at that
 point. At the end db:migrate has nothing left to do and the schema is a
 fresh install's.

 The commands run from a light copy of the app (app, bin and config,
 vendor linked) whose .env names that database: with DB_NAME already in
 the environment, dotenv would leave $_ENV['DB_NAME'] unset.
*/

namespace Tests\Integration\Migrations;

use App\Inventory\Migrations;
use PDO;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\TestDox;
use RuntimeException;
use Tests\Integration\IntegrationTestCase;

final class MigrationsTest extends IntegrationTestCase
{
	private const string DB = 'rqwatch_migrations';
	private const string FRESH = 'rqwatch_migrations_fresh';

	// migration => its command, in Migrations::MIGRATIONS order
	private const array COMMANDS = [
		Migrations::MAIL_RECIPIENTS => 'db:migrate_mail_recipients',
		Migrations::CREATED_DAY => 'db:migrate_created_day',
		Migrations::MAIL_LOG_DATA => 'db:migrate_mail_log_data',
		Migrations::ID_ACTION_INDEX => 'db:migrate_id_action_index',
		Migrations::MAIL_LOG_TOKENS => 'db:migrate_mail_log_tokens',
		Migrations::IP_CREATED_DAY_INDEX => 'db:migrate_ip_created_day_index',
		Migrations::DROP_MAIL_LOG_COLUMNS => 'db:migrate_drop_mail_log_columns',
		Migrations::DROP_MAIL_LOG_INDEXES => 'db:migrate_drop_mail_log_indexes',
		Migrations::MAP_USER_CONSTRAINTS => 'db:migrate_map_user_constraints',
		Migrations::USER_NOTIFICATIONS_DEFAULT => 'db:migrate_user_notifications_default',
		Migrations::ADMIN_NOTIFIED => 'db:migrate_admin_notified',
		Migrations::MAIL_LOG_FUZZY => 'db:migrate_mail_log_fuzzy',
		Migrations::MAIL_LOG_FUZZY_HITS => 'db:migrate_mail_log_fuzzy_hits',
		Migrations::EVER_STORED => 'db:migrate_ever_stored',
		Migrations::SESSION_VERSION => 'db:migrate_session_version',
		Migrations::VIRUS_SKIP_MAP => 'db:migrate_virus_skip_map',
		Migrations::MAP_HITS => 'db:migrate_map_hits',
		Migrations::MAIL_LOG_VIRUSES => 'db:migrate_mail_log_viruses',
		Migrations::MAIL_LOG_FUZZY_HITS_MAILS => 'db:migrate_mail_log_fuzzy_hits_mails',
	];

	// the mock data no migration changes, as loaded
	private const string MAIL_LOGS = 'SELECT id, qid, server, subject, score, action, has_virus, ip, mail_from, mime_from, rcpt_to,'
		. ' mime_to, size, mail_stored, mail_location, notified, released, message_id, created_at FROM mail_logs ORDER BY id';
	private const string USERS = 'SELECT id, username, email, is_admin, password FROM users ORDER BY id';
	private const string CUSTOM_MAPS = 'SELECT c.map_name, c.map_description, c.field_name, c.field_label, m.pattern'
		. ' FROM custom_map_config c JOIN maps_custom m ON m.map_name = c.map_name ORDER BY c.id';
	private const string MAPS_COMBINED = 'SELECT id, map_name, user_id, mail_from, rcpt_to FROM maps_combined ORDER BY id';
	private const string ALIASES = 'SELECT user_id, alias FROM mail_aliases ORDER BY user_id, alias';
	private const string LEGACY_DATA = 'SELECT id, headers, symbols, fuzzy_hashes FROM mail_logs ORDER BY id';
	private const string MAIL_LOG_DATA = 'SELECT mail_log_id, headers, symbols, fuzzy_hashes FROM mail_log_data ORDER BY mail_log_id';

	private static string $copy = '';
	private static ?PDO $pdo = null;
	/** @var array<string, list<string>> query => its rows as loaded */
	private static array $loaded = [];
	/** @var list<string> migrations run so far */
	private static array $applied = [];

	#[\Override]
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		// setUp() skips each test outside qa/integration-tests.sh
		if (getenv('INTEGRATION_BASE_URL') === false) {
			return;
		}
		foreach ([self::DB, self::FRESH] as $db) {
			self::db()->exec("DROP DATABASE IF EXISTS `{$db}`");
			self::db()->exec("CREATE DATABASE `{$db}` CHARACTER SET utf8mb4");
		}
		self::load(self::mdb(), __DIR__ . '/schema-1.6.10.sql');
		self::load(self::mdb(), __DIR__ . '/mock-1.6.10.sql');
		self::load(self::pdo(self::FRESH), self::root() . '/contrib/db-init.sql');

		foreach ([self::MAIL_LOGS, self::USERS, self::CUSTOM_MAPS, self::MAPS_COMBINED, self::ALIASES, self::LEGACY_DATA] as $query) {
			self::$loaded[$query] = self::rows($query);
		}

		self::$copy = sys_get_temp_dir() . '/rqwatch-migrations-' . bin2hex(random_bytes(6));
		$q = fn (string $path): string => escapeshellarg($path);
		self::shell("rm -rf {$q(self::$copy)} && mkdir {$q(self::$copy)}"
			. " && cp -a {$q(self::root() . '/app')} {$q(self::root() . '/bin')} {$q(self::root() . '/config')} {$q(self::$copy)}"
			. " && ln -s {$q(self::root() . '/vendor')} {$q(self::$copy . '/vendor')}");
		$env = file_get_contents(self::root() . '/.env');
		if ($env === false) {
			throw new RuntimeException('Cannot read the .env of ' . self::root());
		}
		// without exactly one DB_NAME line the migrations would run on the harness database
		$env = preg_replace('/^DB_NAME=.*$/m', 'DB_NAME=' . self::DB, $env, -1, $count);
		if ($env === null || $count !== 1) {
			throw new RuntimeException('Cannot point the copy\'s .env at ' . self::DB);
		}
		file_put_contents(self::$copy . '/.env', $env);
	}

	#[\Override]
	public static function tearDownAfterClass(): void {
		if (getenv('INTEGRATION_BASE_URL') !== false) {
			self::$pdo = null;
			foreach ([self::DB, self::FRESH] as $db) {
				self::db()->exec("DROP DATABASE IF EXISTS `{$db}`");
			}
			if (self::$copy !== '') {
				self::shell('rm -rf ' . escapeshellarg(self::$copy));
			}
		}
		parent::tearDownAfterClass();
	}

	#[TestDox('Every migration has a step here, in Migrations::MIGRATIONS order')]
	public function testEveryMigrationCovered(): void {
		$this->assertSame(Migrations::MIGRATIONS, array_keys(self::COMMANDS));
	}

	#[TestDox('The mock data loaded into the v1.6.10 schema')]
	public function testStart(): void {
		$this->assertNotContains('migrations', self::tables());
		$this->assertDataValid();
	}

	#[Depends('testStart')]
	#[TestDox('mail_recipients: mail_log_recipients from rcpt_to, lower-cased, once each, without unknown')]
	public function testMailRecipients(): void {
		$this->migrate(Migrations::MAIL_RECIPIENTS);
		$this->assertTableLikeFresh('mail_log_recipients');
		$this->assertDataValid();
	}

	#[Depends('testMailRecipients')]
	#[TestDox('created_day: DATE(created_at), stored, with its four indexes')]
	public function testCreatedDay(): void {
		$this->migrate(Migrations::CREATED_DAY);
		$this->assertLikeFresh('mail_logs', columns: ['created_day'],
			indexes: ['created_day_index', 'created_day_action_index', 'mail_stored_created_day_index', 'has_virus_created_day_index']);
		$this->assertDataValid();
	}

	#[Depends('testCreatedDay')]
	#[TestDox('mail_log_data: headers, symbols and fuzzy hashes copied out of mail_logs')]
	public function testMailLogData(): void {
		$this->migrate(Migrations::MAIL_LOG_DATA);
		$this->assertTableLikeFresh('mail_log_data');
		$this->assertDataValid();
	}

	#[Depends('testMailLogData')]
	#[TestDox('id_action_index: superseded, recorded without creating the index')]
	public function testIdActionIndex(): void {
		$this->migrate(Migrations::ID_ACTION_INDEX, 'is superseded by');
		$this->assertNotContains('id_action_index', self::indexes(self::DB, 'mail_logs'));
		$this->assertDataValid();
	}

	#[Depends('testIdActionIndex')]
	#[TestDox('mail_log_tokens: the release token table')]
	public function testMailLogTokens(): void {
		$this->migrate(Migrations::MAIL_LOG_TOKENS);
		$this->assertTableLikeFresh('mail_log_tokens');
		$this->assertDataValid();
	}

	#[Depends('testMailLogTokens')]
	#[TestDox('ip_created_day_index: added')]
	public function testIpCreatedDayIndex(): void {
		$this->migrate(Migrations::IP_CREATED_DAY_INDEX);
		$this->assertLikeFresh('mail_logs', indexes: ['ip_created_day_index']);
		$this->assertDataValid();
	}

	#[Depends('testIpCreatedDayIndex')]
	#[TestDox('drop_mail_log_columns: headers, symbols and fuzzy_hashes gone from mail_logs, kept in mail_log_data')]
	public function testDropMailLogColumns(): void {
		$this->migrate(Migrations::DROP_MAIL_LOG_COLUMNS);
		$this->assertSame([], array_intersect(['headers', 'symbols', 'fuzzy_hashes'], self::columns(self::DB, 'mail_logs')));
		$this->assertDataValid();
	}

	#[Depends('testDropMailLogColumns')]
	#[TestDox('drop_mail_log_indexes: the dead mail_logs indexes dropped')]
	public function testDropMailLogIndexes(): void {
		$this->assertContains('rcpt_to_index', self::indexes(self::DB, 'mail_logs'), 'v1.6.10 has it');
		$this->migrate(Migrations::DROP_MAIL_LOG_INDEXES);
		$this->assertSame([], array_intersect(['id_action_index', 'created_at_index', 'rcpt_to_index'], self::indexes(self::DB, 'mail_logs')));
		$this->assertDataValid();
	}

	#[Depends('testDropMailLogIndexes')]
	#[TestDox('map_user_constraints: duplicate alias and orphan map entry removed, unique key and foreign key added')]
	public function testMapUserConstraints(): void {
		$this->migrate(Migrations::MAP_USER_CONSTRAINTS);
		$this->assertTableLikeFresh('mail_aliases');
		$this->assertTableLikeFresh('maps_combined');
		$this->assertDataValid();
	}

	#[Depends('testMapUserConstraints')]
	#[TestDox('user_notifications_default: disable_notifications nullable, 0 becomes NULL')]
	public function testUserNotificationsDefault(): void {
		$this->migrate(Migrations::USER_NOTIFICATIONS_DEFAULT);
		$this->assertLikeFresh('users', columns: ['disable_notifications']);
		$this->assertDataValid();
	}

	#[Depends('testUserNotificationsDefault')]
	#[TestDox('admin_notified: admin_notified and admin_notify_date, NULL for every mail')]
	public function testAdminNotified(): void {
		$this->migrate(Migrations::ADMIN_NOTIFIED);
		$this->assertLikeFresh('mail_logs', columns: ['admin_notified', 'admin_notify_date']);
		$this->assertDataValid();
	}

	#[Depends('testAdminNotified')]
	#[TestDox('mail_log_fuzzy: the learned-mail table, short of the hit columns')]
	public function testMailLogFuzzy(): void {
		$this->migrate(Migrations::MAIL_LOG_FUZZY);
		$this->assertLikeFresh('mail_log_fuzzy',
			columns: ['id', 'mail_log_id', 'qid', 'api_server', 'flag', 'weight', 'hashes', 'learned_by', 'created_at'],
			indexes: self::indexes(self::FRESH, 'mail_log_fuzzy'), foreignKeys: true);
		$this->assertDataValid();
	}

	#[Depends('testMailLogFuzzy')]
	#[TestDox('mail_log_fuzzy_hits: hits, last_hit_at and updated_at on mail_log_fuzzy')]
	public function testMailLogFuzzyHits(): void {
		$this->migrate(Migrations::MAIL_LOG_FUZZY_HITS);
		$this->assertTableLikeFresh('mail_log_fuzzy');

		// mail 1 learned with its first hash and hit once since: the hit mails step links mail 4
		self::mdb()->exec("INSERT INTO mail_log_fuzzy (id, mail_log_id, qid, api_server, flag, weight, hashes, learned_by, created_at, hits, last_hit_at)"
			. " VALUES (1, 1, 'QID1', 'mx1', 1, 10, '[\"1:aaaa\"]', 'admin', '2026-01-05 10:00:00', 1, '2026-01-20 00:00:00')");
		$this->assertDataValid();
	}

	#[Depends('testMailLogFuzzyHits')]
	#[TestDox('ever_stored: stored now or once (mail_location), with its index')]
	public function testEverStored(): void {
		$this->migrate(Migrations::EVER_STORED);
		$this->assertLikeFresh('mail_logs', columns: ['ever_stored'], indexes: ['ever_stored_created_day_index']);
		$this->assertDataValid();
	}

	#[Depends('testEverStored')]
	#[TestDox('session_version: 0 for every user')]
	public function testSessionVersion(): void {
		$this->migrate(Migrations::SESSION_VERSION);
		$this->assertLikeFresh('users', columns: ['session_version']);
		$this->assertDataValid();
	}

	#[Depends('testSessionVersion')]
	#[TestDox('virus_skip_map: the rqwatch_virus_no_notify custom map, beside the existing one')]
	public function testVirusSkipMap(): void {
		$this->migrate(Migrations::VIRUS_SKIP_MAP);
		$this->assertDataValid();
	}

	#[Depends('testVirusSkipMap')]
	#[TestDox('map_hits: custom map symbols, set for the virus skip map, and the hit tables')]
	public function testMapHits(): void {
		$this->migrate(Migrations::MAP_HITS);
		$this->assertTableLikeFresh('custom_map_config');
		$this->assertTableLikeFresh('symbol_names');
		$this->assertTableLikeFresh('mail_log_symbol_hits');
		$this->assertDataValid();
	}

	#[Depends('testMapHits')]
	#[TestDox('mail_log_viruses: one row per antivirus symbol option of each virus mail')]
	public function testMailLogViruses(): void {
		$this->migrate(Migrations::MAIL_LOG_VIRUSES);
		$this->assertTableLikeFresh('mail_log_viruses');
		$this->assertDataValid();
	}

	#[Depends('testMailLogViruses')]
	#[TestDox('mail_log_fuzzy_hits_mails: a learned mail linked to the later mail carrying its hash')]
	public function testMailLogFuzzyHitsMails(): void {
		$this->migrate(Migrations::MAIL_LOG_FUZZY_HITS_MAILS);
		$this->assertTableLikeFresh('mail_log_fuzzy_hits');
		$this->assertDataValid();
	}

	#[Depends('testMailLogFuzzyHitsMails')]
	#[TestDox('Afterwards: db:migrate has nothing to do, and the schema is a fresh install\'s')]
	public function testFreshSchema(): void {
		$lines = array_filter(explode("\n", $this->command('db:migrate')), fn (string $line): bool => trim($line) !== '');
		$this->assertSame([], array_values(array_filter($lines, fn (string $line): bool => !str_contains($line, 'is already applied'))));
		$this->assertSame(self::schema(self::FRESH), self::schema(self::DB));
	}

	// runs $migration's command: it must exit 0, print $done, and leave exactly the migrations run so far completed
	private function migrate(string $migration, string $done = 'completed'): void {
		$output = $this->command(self::COMMANDS[$migration]);
		$this->assertStringContainsString("({$migration}) {$done}", $output);
		self::$applied[] = $migration;
		$expected = array_map(fn (string $m): string => "{$m}|completed", self::$applied);
		sort($expected);
		$this->assertSame($expected, self::rows('SELECT migration, status FROM migrations ORDER BY migration'));
	}

	private function command(string $command): string {
		exec('cd ' . escapeshellarg(self::$copy) . ' && ' . escapeshellarg(PHP_BINARY) . ' bin/cli.php '
			. escapeshellarg($command) . ' 2>&1', $out, $status);
		$output = implode("\n", $out);
		$this->assertSame(0, $status, "{$command}: {$output}");
		return $output;
	}

	// $table's columns, indexes and foreign keys, as a fresh install has them
	private function assertTableLikeFresh(string $table): void {
		$this->assertLikeFresh($table, self::columns(self::FRESH, $table), self::indexes(self::FRESH, $table), true);
	}

	/**
	 * these objects of $table defined as a fresh install defines them
	 * @param list<string> $columns
	 * @param list<string> $indexes
	 */
	private function assertLikeFresh(string $table, array $columns = [], array $indexes = [], bool $foreignKeys = false): void {
		$this->assertTrue($columns !== [] || $indexes !== [], "{$table}: nothing to compare");
		$this->assertSame(self::columnDefs(self::FRESH, $table, $columns), self::columnDefs(self::DB, $table, $columns), "{$table} columns");
		$this->assertCount(count($columns), self::columnDefs(self::DB, $table, $columns), "{$table} columns present");
		$this->assertSame(self::indexDefs(self::FRESH, $table, $indexes), self::indexDefs(self::DB, $table, $indexes), "{$table} indexes");
		$this->assertCount(count($indexes), self::indexDefs(self::DB, $table, $indexes), "{$table} indexes present");
		if ($foreignKeys) {
			$this->assertSame(self::foreignKeyDefs(self::FRESH, $table), self::foreignKeyDefs(self::DB, $table), "{$table} foreign keys");
		}
	}

	// the mock data, as it should be after the migrations run so far
	private function assertDataValid(): void {
		foreach ([self::MAIL_LOGS, self::USERS, self::CUSTOM_MAPS] as $query) {
			$this->assertSame(self::$loaded[$query], self::rows($query), $query);
		}

		$constrained = self::done(Migrations::MAP_USER_CONSTRAINTS);
		$this->assertSame(
			$constrained ? [self::$loaded[self::MAPS_COMBINED][0]] : self::$loaded[self::MAPS_COMBINED],
			self::rows(self::MAPS_COMBINED),
			'maps_combined',
		);
		$this->assertSame(
			$constrained ? ['2|alias-alice@example.org', '3|alias-bob@example.org'] : self::$loaded[self::ALIASES],
			self::rows(self::ALIASES),
			'mail_aliases',
		);
		$this->assertSame(
			self::done(Migrations::USER_NOTIFICATIONS_DEFAULT) ? ['admin|NULL', 'alice|1', 'bob|NULL'] : ['admin|0', 'alice|1', 'bob|0'],
			self::rows('SELECT username, disable_notifications FROM users ORDER BY id'),
			'disable_notifications',
		);

		if (self::done(Migrations::MAIL_RECIPIENTS)) {
			$this->assertSame(
				['1|alice@example.org', '1|bob@example.org', '3|bob@example.org', '4|alice@example.org'],
				self::rows('SELECT mail_log_id, recipient_email FROM mail_log_recipients ORDER BY mail_log_id, recipient_email'),
				'mail_log_recipients',
			);
		}
		if (self::done(Migrations::CREATED_DAY)) {
			$this->assertSame(
				['1|2026-01-05', '2|2026-02-01', '3|2025-12-31', '4|2026-01-10'],
				self::rows('SELECT id, created_day FROM mail_logs ORDER BY id'),
				'created_day',
			);
		}
		// in mail_logs until the columns are dropped, in mail_log_data from its migration on
		if (!self::done(Migrations::DROP_MAIL_LOG_COLUMNS)) {
			$this->assertSame(self::$loaded[self::LEGACY_DATA], self::rows(self::LEGACY_DATA), 'mail_logs headers, symbols, fuzzy_hashes');
		}
		if (self::done(Migrations::MAIL_LOG_DATA)) {
			$this->assertSame(self::$loaded[self::LEGACY_DATA], self::rows(self::MAIL_LOG_DATA), 'mail_log_data');
		}
		if (self::done(Migrations::ADMIN_NOTIFIED)) {
			$this->assertSame(['1|NULL|NULL', '2|NULL|NULL', '3|NULL|NULL', '4|NULL|NULL'],
				self::rows('SELECT id, admin_notified, admin_notify_date FROM mail_logs ORDER BY id'), 'admin_notified');
		}
		if (self::done(Migrations::MAIL_LOG_FUZZY_HITS)) {
			$this->assertSame(['1|1|QID1|1|2026-01-20 00:00:00'],
				self::rows('SELECT id, mail_log_id, qid, hits, last_hit_at FROM mail_log_fuzzy'), 'mail_log_fuzzy');
		}
		if (self::done(Migrations::EVER_STORED)) {
			$this->assertSame(['1|1', '2|0', '3|1', '4|1'], self::rows('SELECT id, ever_stored FROM mail_logs ORDER BY id'), 'ever_stored');
		}
		if (self::done(Migrations::SESSION_VERSION)) {
			$this->assertSame(['1|0', '2|0', '3|0'], self::rows('SELECT id, session_version FROM users ORDER BY id'), 'session_version');
		}
		if (self::done(Migrations::VIRUS_SKIP_MAP)) {
			$this->assertSame(
				['my_subject_regex|pattern', 'rqwatch_virus_no_notify|virus'],
				self::rows('SELECT map_name, field_name FROM custom_map_config ORDER BY map_name'),
				'custom_map_config',
			);
		}
		if (self::done(Migrations::MAP_HITS)) {
			$this->assertSame(
				['my_subject_regex|NULL', 'rqwatch_virus_no_notify|RQWATCH_VIRUS_NO_NOTIFY'],
				self::rows('SELECT map_name, symbols FROM custom_map_config ORDER BY map_name'),
				'custom_map_config symbols',
			);
		}
		if (self::done(Migrations::MAIL_LOG_VIRUSES)) {
			$this->assertSame(['1|2026-01-05|CLAM_VIRUS|Eicar-Signature'],
				self::rows('SELECT mail_log_id, created_day, av, name FROM mail_log_viruses'), 'mail_log_viruses');
		}
		if (self::done(Migrations::MAIL_LOG_FUZZY_HITS_MAILS)) {
			$this->assertSame(['1|4'], self::rows('SELECT fuzzy_id, mail_log_id FROM mail_log_fuzzy_hits'), 'mail_log_fuzzy_hits');
		}
	}

	private static function done(string $migration): bool {
		return in_array($migration, self::$applied, true);
	}

	private static function mdb(): PDO {
		return self::$pdo ??= self::pdo(self::DB);
	}

	private static function pdo(string $db): PDO {
		$pdo = new PDO(
			(string) preg_replace('/dbname=[^;]*/', "dbname={$db}", (string) getenv('INTEGRATION_DB_DSN')),
			(string) getenv('INTEGRATION_DB_USER'),
			(string) getenv('INTEGRATION_DB_PASS'),
			[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
		);
		// schema() concatenates whole tables' columns
		$pdo->exec('SET SESSION group_concat_max_len = 1048576');
		return $pdo;
	}

	// a db-init.sql shaped file: whole-line # and -- comments, statements ending a line with ;
	private static function load(PDO $pdo, string $file): void {
		$sql = file_get_contents($file);
		if ($sql === false) {
			throw new RuntimeException("Cannot read {$file}");
		}
		$lines = array_filter(explode("\n", $sql), fn (string $line): bool => preg_match('/^\s*(#|--)/', $line) !== 1
			&& preg_match('/^\s*(CREATE DATABASE|USE)\b/i', $line) !== 1);
		$statements = preg_split('/;\s*$/m', implode("\n", $lines));
		if ($statements === false) {
			throw new RuntimeException("Cannot split {$file}");
		}
		foreach ($statements as $statement) {
			if (trim($statement) !== '') {
				$pdo->exec($statement);
			}
		}
	}

	private static function shell(string $command): void {
		exec($command . ' 2>&1', $out, $status);
		if ($status !== 0) {
			throw new RuntimeException("{$command} exited {$status}: " . implode("\n", $out));
		}
	}

	/**
	 * each row's columns joined with |, NULL as NULL
	 * @param list<string> $params
	 * @return list<string>
	 */
	private static function rows(string $sql, array $params = []): array {
		$stmt = self::mdb()->prepare($sql);
		$stmt->execute($params);
		$rows = [];
		/** @var list<list<scalar|null>> $fetched each a row's column values */
		$fetched = $stmt->fetchAll(PDO::FETCH_NUM);
		foreach ($fetched as $row) {
			$rows[] = implode('|', array_map(fn (mixed $v): string => $v === null ? 'NULL' : (string) $v, $row));
		}
		return $rows;
	}

	/** @return list<string> */
	private static function tables(): array {
		return self::rows('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY 1', [self::DB]);
	}

	/** @return list<string> */
	private static function columns(string $db, string $table): array {
		return self::rows('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
			. ' ORDER BY ORDINAL_POSITION', [$db, $table]);
	}

	/** @return list<string> */
	private static function indexes(string $db, string $table): array {
		return self::rows('SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
			. ' ORDER BY 1', [$db, $table]);
	}

	/**
	 * @param list<string> $columns
	 * @return list<string>
	 */
	private static function columnDefs(string $db, string $table, array $columns): array {
		if ($columns === []) {
			return [];
		}
		return self::rows("SELECT CONCAT_WS(' ', COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, IFNULL(COLUMN_DEFAULT, '<null>'), EXTRA,"
			. " IFNULL(GENERATION_EXPRESSION, ''), IFNULL(COLLATION_NAME, '')) FROM information_schema.COLUMNS"
			. ' WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME IN (' . implode(', ', array_fill(0, count($columns), '?')) . ')'
			. ' ORDER BY COLUMN_NAME', [$db, $table, ...$columns]);
	}

	/**
	 * @param list<string> $indexes
	 * @return list<string>
	 */
	private static function indexDefs(string $db, string $table, array $indexes): array {
		if ($indexes === []) {
			return [];
		}
		return self::rows("SELECT CONCAT_WS(' ', INDEX_NAME, NON_UNIQUE, GROUP_CONCAT(CONCAT(COLUMN_NAME, IFNULL(CONCAT('(', SUB_PART, ')'), ''))"
			. ' ORDER BY SEQ_IN_INDEX)) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
			. ' AND INDEX_NAME IN (' . implode(', ', array_fill(0, count($indexes), '?')) . ')'
			. ' GROUP BY INDEX_NAME, NON_UNIQUE ORDER BY INDEX_NAME', [$db, $table, ...$indexes]);
	}

	/** @return list<string> */
	private static function foreignKeyDefs(string $db, string $table): array {
		return self::rows("SELECT CONCAT_WS(' ', rc.CONSTRAINT_NAME, rc.REFERENCED_TABLE_NAME, rc.UPDATE_RULE, rc.DELETE_RULE,"
			. " GROUP_CONCAT(CONCAT(k.COLUMN_NAME, '>', k.REFERENCED_COLUMN_NAME) ORDER BY k.ORDINAL_POSITION))"
			. ' FROM information_schema.REFERENTIAL_CONSTRAINTS rc JOIN information_schema.KEY_COLUMN_USAGE k'
			. ' ON k.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA AND k.CONSTRAINT_NAME = rc.CONSTRAINT_NAME AND k.TABLE_NAME = rc.TABLE_NAME'
			. ' WHERE rc.CONSTRAINT_SCHEMA = ? AND rc.TABLE_NAME = ?'
			. ' GROUP BY rc.CONSTRAINT_NAME, rc.REFERENCED_TABLE_NAME, rc.UPDATE_RULE, rc.DELETE_RULE ORDER BY rc.CONSTRAINT_NAME', [$db, $table]);
	}

	/** @return list<string> every table's definition in $db, comparable across databases */
	private static function schema(string $db): array {
		$schema = self::rows('SELECT CONCAT_WS(\' \', TABLE_NAME, ENGINE, TABLE_COLLATION) FROM information_schema.TABLES'
			. ' WHERE TABLE_SCHEMA = ? ORDER BY 1', [$db]);
		foreach (self::rows('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY 1', [$db]) as $table) {
			$schema[] = "{$table} columns: " . implode(', ', self::columns($db, $table));
			array_push($schema,
				...self::columnDefs($db, $table, self::columns($db, $table)),
				...self::indexDefs($db, $table, self::indexes($db, $table)),
				...self::foreignKeyDefs($db, $table));
		}
		return $schema;
	}
}
