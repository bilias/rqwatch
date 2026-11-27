<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 FuzzyService's learned mails on RecordingDatabase, rspamd's controller a
 MockHttpClient through ApiClient's seam. A stored mail matched the learned
 mails holding a digest of its fuzzy hashes, its own learn left out. The
 learned mails: by mail, those unchanged for n days (of one server), purged
 one by one and logged; listed newest first, or by last hit, searched in
 the QID, learner and server, the QIDs sharing a digest, and a hash prefix.
 Per learned mail, the others sharing a digest (oldest first), its digests
 others hold, the holders of a digest none of its own list holds (whose
 slot rspamd keeps), and the mails that hit it. Learning on this node: a
 stored mail not learned yet is added to rspamd and recorded, logged; when
 recording fails, a concurrent learn's record is kept, else the hashes no
 other learned mail holds are deleted from rspamd again, and an error
 names what happened. Unlearning deletes the digests no other learned mail
 holds and the record, logged, with a warning for a slot rspamd keeps.
 Adding weight adds the mail again and records the weight and the hashes,
 up to 65535 in all, for a stored mail whose record is still there.
*/

namespace Tests\Unit\Services;

use App\Configuration\Config;
use App\Core\App;
use App\Core\AppContainer;
use App\Core\Database\MigrationStatus;
use App\Core\Exception\FuzzyError;
use App\Core\Exception\FuzzyException;
use App\Models\MailLog;
use App\Models\MailLogFuzzy;
use App\Services\ApiClient;
use App\Services\FuzzyService;
use Closure;
use Illuminate\Database\Eloquent\Model;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Tests\Unit\RecordingDatabase;
use Tests\Unit\UnitTestCase;

final class FuzzyStoreTest extends UnitTestCase
{
	private const string A = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';
	private const string B = '0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f0f';

	private const string C = 'c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3';

	private const string BY_MAIL = 'select * from `mail_log_fuzzy` where `mail_log_id` = ? limit 1';
	private const string SHARED = 'select exists(select * from `mail_log_fuzzy` where JSON_CONTAINS(hashes, JSON_QUOTE(?))';
	private const string HOLDERS = "SELECT f.qid, f.flag, j.h FROM mail_log_fuzzy f, JSON_TABLE(f.hashes, '$[*]' COLUMNS (h VARCHAR(128) PATH '$')) j WHERE f.id <> ? AND j.h IN ";

	private RecordingDatabase $db;

	private string $dir = '';

	/** @var list<array<string, mixed>> the mail's mail_log_data row */
	private array $data = [];

	/** @var list<list<array<string, mixed>>> what each lookup of a learned mail by mail finds, in turn */
	private array $learned = [];

	/** @var list<string> the digests other learned mails hold */
	private array $shared = [];

	/** @var list<array<string, mixed>> what a JSON_TABLE lookup finds */
	private array $holders = [];

	/** @var list<array<string, mixed>> the learned mails any other lookup finds */
	private array $fuzzy = [];

	/** @var list<array<string, mixed>> the mail_logs rows */
	private array $mails = [];

	/** @var list<MockResponse> rspamd's replies, in order */
	private array $replies = [];

	/** @var list<array{string, array<string, list<string>>}> url, headers of each request */
	private array $requests = [];

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->db = new RecordingDatabase($this->createStub(PDO::class));
		$this->db->capsule->bootEloquent();
		$this->db->answer = $this->answer(...);
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, $this->db->capsule, new MigrationStatus(null, $this->log), null, false));
		$this->setEnv('MY_API_SERVER_ALIAS', 'mx1');
		$this->setEnv('RSPAMD_CONTROLLER_ENABLE_PASS', null);
		Config::set('fuzzy_learn_flags', [1 => ['label' => 'Spam'], 2 => ['label' => 'Phish', 'weight' => 20]]);
		Config::set('API_SERVERS', ['mx1' => ['fuzzy_url' => 'http://127.0.0.1:11334']]);
		(new ReflectionProperty(ApiClient::class, 'httpClient'))->setValue(null, $this->client(...));
		$this->dir = sys_get_temp_dir() . '/rqwatch-fs-' . bin2hex(random_bytes(4));
		mkdir($this->dir);
		file_put_contents("{$this->dir}/Q1", "Subject: spam\r\n\r\nBuy now\r\n");
		$this->setEnv('QUARANTINE_DIR', $this->dir);
		$this->mails = [['id' => 7, 'qid' => 'Q1', 'server' => 'mx1', 'mail_stored' => 1, 'mail_location' => "{$this->dir}/Q1"]];
	}

	#[\Override]
	protected function tearDown(): void {
		(new ReflectionProperty(ApiClient::class, 'httpClient'))->setValue(null, null);
		@unlink("{$this->dir}/Q1");
		@rmdir($this->dir);
		Model::unsetConnectionResolver();
		RecordingDatabase::forget();
		parent::tearDown();
	}

	/**
	 * @param list<mixed> $bindings
	 * @return list<array<string, mixed>>
	 */
	private function answer(string $sql, array $bindings): array {
		return match (true) {
			str_starts_with($sql, 'select count(*)') => [['aggregate' => count($this->fuzzy)]],
			str_contains($sql, 'from `mail_log_data`') => $this->data,
			str_starts_with($sql, self::SHARED) => [['exists' => in_array($bindings[0] ?? null, $this->shared, true) ? 1 : 0]],
			str_contains($sql, 'JSON_TABLE') => $this->holders,
			$sql === self::BY_MAIL => array_shift($this->learned) ?? [],
			str_contains($sql, 'from `mail_logs`') => $this->mails,
			str_contains($sql, 'from `mail_log_fuzzy_hits`') => [['mail_log_id' => '12'], ['mail_log_id' => 9]],
			str_contains($sql, 'from `mail_log_fuzzy`') => $this->fuzzy,
			default => throw new RuntimeException("Unexpected query: {$sql}"),
		};
	}

	private function client(): HttpClientInterface {
		return new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
			/** @var array<string, list<string>> $headers */
			$headers = $options['normalized_headers'] ?? [];
			$this->requests[] = [$url, $headers];
			return array_shift($this->replies) ?? throw new \LogicException("No reply queued for {$url}");
		});
	}

	private function reply(string $body, int $code = 200): void {
		$this->replies[] = new MockResponse($body, ['http_code' => $code]);
	}

	/** @param list<string> $hashes */
	private function added(array $hashes): void {
		$this->reply((string) json_encode(['success' => true, 'hashes' => $hashes]));
	}

	/** @param list<string> $hashes */
	private static function row(array $hashes = [self::A, self::B], int $flag = 1, int $weight = 10, ?string $qid = 'Q1'): MailLogFuzzy {
		return (new MailLogFuzzy())->newFromBuilder([
			'id' => 3, 'mail_log_id' => 7, 'qid' => $qid, 'api_server' => 'mx1', 'flag' => $flag, 'weight' => $weight,
			'hashes' => json_encode($hashes),
		]);
	}

	private function mail(): MailLog {
		return (new MailLog())->newFromBuilder($this->mails[0] ?? []);
	}

	/** @param Closure(): mixed $call */
	private function assertRefused(Closure $call, string $message, FuzzyError $error): void {
		try {
			$call();
		} catch (FuzzyException $e) {
			$this->assertSame([$message, $error], [$e->getMessage(), $e->error]);
			return;
		}
		$this->fail('No FuzzyException');
	}

	/** @return list<list<string>> the Hash headers of each /fuzzydelhash request */
	private function deleted(): array {
		$deleted = [];
		foreach ($this->requests as [$url, $headers]) {
			if (str_ends_with($url, '/fuzzydelhash')) {
				$deleted[] = array_map(fn (string $h): string => substr($h, 6), $headers['hash'] ?? []);
			}
		}
		return $deleted;
	}

	public function testMatchedBy(): void {
		$this->data = [['fuzzy_hashes' => json_encode([self::A, 'junk', self::B])]];
		$this->fuzzy = [['id' => 4, 'mail_log_id' => null, 'qid' => null, 'flag' => 1], ['id' => 6, 'mail_log_id' => 9, 'qid' => 'L6', 'flag' => '2']];
		$this->assertSame([
			['id' => 4, 'mail_log_id' => null, 'qid' => '', 'flag' => 1],
			['id' => 6, 'mail_log_id' => 9, 'qid' => 'L6', 'flag' => 2],
		], (new FuzzyService())->matchedBy(7));
		$this->assertSame([
			['select `fuzzy_hashes` from `mail_log_data` where `mail_log_id` = ? limit 1', [7]],
			['select `id`, `mail_log_id`, `qid`, `flag` from `mail_log_fuzzy` where (JSON_CONTAINS(hashes, JSON_QUOTE(?)) or JSON_CONTAINS(hashes, JSON_QUOTE(?)))'
				. ' and (`mail_log_id` is null or `mail_log_id` <> ?) order by `id` asc', [self::A, self::B, 7]],
		], $this->db->selects);
	}

	/** @return array<string, array{list<array<string, mixed>>}> */
	public static function noDigests(): array {
		return [
			'no data' => [[]],
			'no hashes' => [[['fuzzy_hashes' => null]]],
			'not JSON' => [[['fuzzy_hashes' => '{']]],
			'none a digest' => [[['fuzzy_hashes' => '["abc"]']]],
		];
	}

	// stored encoded twice: the text the cast gives decoded again
	public function testMatchedByEncodedTwice(): void {
		$this->data = [['fuzzy_hashes' => json_encode(json_encode([self::A]))]];
		(new FuzzyService())->matchedBy(7);
		$this->assertSame([self::A, 7], $this->db->selects[1][1] ?? null);
	}

	/** @param list<array<string, mixed>> $data */
	#[DataProvider('noDigests')]
	public function testMatchedByNothing(array $data): void {
		$this->data = $data;
		$this->assertSame([], (new FuzzyService())->matchedBy(7));
		$this->assertCount(1, $this->db->selects);
	}

	public function testGetByMailLogId(): void {
		$this->learned = [[['id' => 3, 'qid' => 'Q1']]];
		$service = new FuzzyService();
		$this->assertSame(3, $service->getByMailLogId(7)?->id);
		$this->assertNull($service->getByMailLogId(7));
		$this->assertSame([[self::BY_MAIL, [7]], [self::BY_MAIL, [7]]], $this->db->selects);
	}

	public function testGetExpired(): void {
		$service = new FuzzyService();
		$query = $service->getExpired(30, 'mx2');
		$this->assertSame(['select * from `mail_log_fuzzy` where updated_at < NOW() - INTERVAL ? DAY and `api_server` = ? order by `id` asc', [30, 'mx2']], [$query->toSql(), $query->getBindings()]);
		$query = $service->getExpired(7);
		$this->assertSame(['select * from `mail_log_fuzzy` where updated_at < NOW() - INTERVAL ? DAY order by `id` asc', [7]], [$query->toSql(), $query->getBindings()]);
	}

	public function testPurgeExpired(): void {
		$this->fuzzy = [
			['id' => 4, 'qid' => 'L4', 'api_server' => 'mx1', 'updated_at' => '2026-01-01 10:00:00', 'last_hit_at' => null],
			['id' => 6, 'qid' => 'L6', 'api_server' => 'mx2', 'updated_at' => '2026-02-01 10:00:00', 'last_hit_at' => '2026-01-20 08:00:00'],
		];
		$service = new FuzzyService();
		$query = $service->getExpired(30);
		$this->assertSame(2, $service->purgeExpired($query));
		$this->assertSame([['delete from `mail_log_fuzzy` where `id` = ?', [4]], ['delete from `mail_log_fuzzy` where `id` = ?', [6]]], $this->db->changes);
		$this->assertSame([
			'info L4 fuzzy record purged (unchanged since 2026-01-01 10:00:00, no hits, server mx1)',
			'info L6 fuzzy record purged (unchanged since 2026-02-01 10:00:00, last hit 2026-01-20 08:00:00, server mx2)',
		], $this->log->lines);
		// the query given is left as it was
		$this->assertSame('select * from `mail_log_fuzzy` where updated_at < NOW() - INTERVAL ? DAY order by `id` asc', $query->toSql());
	}

	public function testPurgeNothing(): void {
		$this->assertSame(0, (new FuzzyService())->purgeExpired((new FuzzyService())->getExpired(30)));
		$this->assertSame([[], []], [$this->db->changes, $this->log->lines]);
	}

	private const string SEARCH = "(`qid` like ? or `learned_by` like ? or `api_server` like ? or `id` in (select o.id from mail_log_fuzzy o,"
		. " JSON_TABLE(o.hashes, '$[*]' COLUMNS (h VARCHAR(128) PATH '$')) oj, mail_log_fuzzy m,"
		. " JSON_TABLE(m.hashes, '$[*]' COLUMNS (h VARCHAR(128) PATH '$')) mj where m.qid LIKE ? AND mj.h = oj.h)";

	// a hash prefix, 8 hex digits or more, is also looked for in the hashes, lower case
	public function testSearchLearnedHash(): void {
		$this->fuzzy = [['id' => 4, 'qid' => 'L4']];
		$learned = (new FuzzyService())->searchLearnedPaginated('Ab12cd34', '/fuzzy', 2, 10, 'hit');
		$like = array_fill(0, 4, '%Ab12cd34%');
		$this->assertSame([
			['select count(*) as aggregate from `mail_log_fuzzy` where ' . self::SEARCH . ' or `hashes` like ?)', [...$like, '%"ab12cd34%']],
			['select * from `mail_log_fuzzy` where ' . self::SEARCH . ' or `hashes` like ?) order by last_hit_at IS NULL, `last_hit_at` desc, `id` desc limit 10 offset 10',
				[...$like, '%"ab12cd34%']],
		], $this->db->selects);
		$this->assertSame([1, 10, '/fuzzy?q=Ab12cd34&sort=hit&page=1'], [$learned->total(), $learned->perPage(), $learned->url(1)]);
	}

	/** @return array<string, array{string}> */
	public static function notHashes(): array {
		return ['7 hex digits' => ['ab12cd3'], 'not hex' => ['ab12cd3g'], 'more after' => ['ab12cd34 x'], 'a newline after' => ["ab12cd34\n"], '129 digits' => [str_repeat('a', 129)]];
	}

	#[DataProvider('notHashes')]
	public function testSearchLearnedNotAHash(string $search): void {
		(new FuzzyService())->searchLearnedPaginated($search, '/fuzzy', 1, 10);
		$this->assertStringEndsWith('mj.h = oj.h))', $this->db->selects[0][0] ?? '');
	}

	// LIKE's wildcards and escape in the text are matched as themselves
	public function testSearchLearnedEscaped(): void {
		$this->fuzzy = [['id' => 4, 'qid' => 'L4']];
		$learned = (new FuzzyService())->searchLearnedPaginated('50%_a\\', '/fuzzy', 1, 10, 'learned');
		$this->assertSame([
			'select * from `mail_log_fuzzy` where ' . self::SEARCH . ') order by `id` desc limit 10 offset 0',
			array_fill(0, 4, '%50\\%\\_a\\\\%'),
		], $this->db->selects[1] ?? null);
		$this->assertSame('/fuzzy?q=50%25_a%5C&page=1', $learned->url(1));
	}

	/** @return array<string, array{string, string, string}> sort, order, the page's url */
	public static function sorts(): array {
		return [
			'learned' => ['learned', ' order by `id` desc', '/fuzzy?page=1'],
			'hit' => ['hit', ' order by last_hit_at IS NULL, `last_hit_at` desc, `id` desc', '/fuzzy?sort=hit&page=1'],
		];
	}

	// no search text: none in the page's url
	public function testSearchLearnedNothing(): void {
		$this->fuzzy = [['id' => 4, 'qid' => 'L4']];
		$this->assertSame('/fuzzy?page=1', (new FuzzyService())->searchLearnedPaginated('', '/fuzzy', 1, 10)->url(1));
	}

	#[DataProvider('sorts')]
	public function testGetLearned(string $sort, string $order, string $url): void {
		$this->fuzzy = [['id' => 4, 'qid' => 'L4']];
		$learned = (new FuzzyService())->getLearnedPaginated('/fuzzy', 3, 5, $sort);
		$this->assertSame([['select count(*) as aggregate from `mail_log_fuzzy`', []], ["select * from `mail_log_fuzzy`{$order} limit 5 offset 10", []]], $this->db->selects);
		$this->assertSame($url, $learned->url(1));
	}

	public function testSharedWith(): void {
		$this->holders = [
			['id' => 3, 'qid' => 'Q1', 'mail_log_id' => 7, 'flag' => 1, 'h' => self::A],
			['id' => 9, 'qid' => 'L9', 'mail_log_id' => null, 'flag' => '2', 'h' => self::A],
			['id' => 6, 'qid' => 'L6', 'mail_log_id' => 6, 'flag' => 1, 'h' => self::A],
			['id' => 4, 'qid' => null, 'mail_log_id' => '8', 'flag' => 1, 'h' => self::B],
			['id' => 9, 'qid' => 'L9', 'mail_log_id' => null, 'flag' => '2', 'h' => self::B],
			['id' => 5, 'qid' => 'Q5', 'mail_log_id' => 5, 'flag' => 1, 'h' => 'c'],
			['id' => 7, 'qid' => 'Q7', 'mail_log_id' => 7, 'flag' => 1, 'h' => 'd'],
			['id' => 8, 'qid' => 'Q8', 'mail_log_id' => 8, 'flag' => 1, 'h' => 'd'],
		];
		$alone = (new MailLogFuzzy())->newFromBuilder(['id' => 5, 'hashes' => '["c"]']);
		$paired = (new MailLogFuzzy())->newFromBuilder(['id' => 7, 'hashes' => '["d"]']);
		$this->assertSame([
			3 => [
				['id' => 4, 'qid' => null, 'mail_log_id' => 8, 'flag' => 1],
				['id' => 6, 'qid' => 'L6', 'mail_log_id' => 6, 'flag' => 1],
				['id' => 9, 'qid' => 'L9', 'mail_log_id' => null, 'flag' => 2],
			],
			7 => [['id' => 8, 'qid' => 'Q8', 'mail_log_id' => 8, 'flag' => 1]],
		], (new FuzzyService())->sharedWith([self::row(), $alone, $paired]));
		$this->assertSame([[
			"SELECT f.id, f.qid, f.mail_log_id, f.flag, j.h FROM mail_log_fuzzy f, JSON_TABLE(f.hashes, '$[*]' COLUMNS (h VARCHAR(128) PATH '$')) j WHERE j.h IN (?,?,?,?)",
			[self::A, self::B, 'c', 'd'],
		]], $this->db->selects);
	}

	public function testSharedWithNoHashes(): void {
		$service = new FuzzyService();
		$this->assertSame([[], []], [$service->sharedWith([]), $service->sharedWith([self::row([])])]);
		$this->assertSame([], $this->db->selects);
	}

	// a digest only another list holds keeps the slot; one held in the same list as well does not
	public function testOrphanedSlotHolders(): void {
		$this->holders = [
			['qid' => 'L4', 'flag' => 2, 'h' => self::A],
			['qid' => null, 'flag' => 3, 'h' => self::A],
			['qid' => 'L4', 'flag' => '2', 'h' => self::B],
			['qid' => 'L6', 'flag' => 1, 'h' => self::B],
			['qid' => 'L4', 'flag' => 2, 'h' => 'c'],
		];
		$this->assertSame([['qid' => 'L4', 'flag' => 2], ['qid' => '', 'flag' => 3]], (new FuzzyService())->orphanedSlotHolders(self::row([self::A, self::B, self::A, 'c'])));
		$this->assertSame([[self::HOLDERS . '(?,?,?)', [3, self::A, self::B, 'c']]], $this->db->selects);
	}

	public function testOrphanedSlotHoldersNone(): void {
		$service = new FuzzyService();
		$this->assertSame([[], []], [$service->orphanedSlotHolders(self::row([])), $service->orphanedSlotHolders(self::row([self::A]))]);
		$this->assertCount(1, $this->db->selects);
	}

	public function testSharedHashes(): void {
		$this->shared = [self::B];
		$this->assertSame([self::B], (new FuzzyService())->sharedHashes(self::row()));
		$this->assertSame([
			[self::SHARED . ' and `mail_log_fuzzy`.`id` != ?) as `exists`', [self::A, 3]],
			[self::SHARED . ' and `mail_log_fuzzy`.`id` != ?) as `exists`', [self::B, 3]],
		], $this->db->selects);
	}

	public function testGetHitMailIds(): void {
		$this->assertSame([12, 9], (new FuzzyService())->getHitMailIds(3, 5));
		$this->assertSame([['select `mail_log_id` from `mail_log_fuzzy_hits` where `fuzzy_id` = ? order by `mail_log_id` desc limit 5', [3]]], $this->db->selects);
	}

	// learned here: added to rspamd with the list's weight, recorded, logged
	public function testLearnLocal(): void {
		$this->added([self::A, self::B]);
		(new FuzzyService())->learn($this->mail(), 2, 'alice');
		$this->assertSame('http://127.0.0.1:11334/fuzzyadd', $this->requests[0][0] ?? null);
		$this->assertSame([['Flag: 2'], ['Weight: 20']], [$this->requests[0][1]['flag'] ?? null, $this->requests[0][1]['weight'] ?? null]);
		$this->assertSame([[
			'mail_log_id' => 7, 'qid' => 'Q1', 'api_server' => 'mx1', 'flag' => 2, 'weight' => 20, 'hashes' => json_encode([self::A, self::B]), 'learned_by' => 'alice',
		]], $this->db->rows('mail_log_fuzzy'));
		$msg = "info Q1 learned as fuzzy 'Phish' (flag 2, weight 20, 2 hashes) by 'alice'";
		$this->assertSame([$msg, $msg], $this->log->lines);
	}

	// the hashes recorded as a list, however rspamd keyed them
	public function testLearnLocalWeight(): void {
		$this->reply((string) json_encode(['hashes' => ['h' => self::A]]));
		$row = (new FuzzyService())->learnLocal($this->mail(), 1, 'alice', 30);
		$this->assertSame(['Q1', 30, [self::A]], [$row->qid, $row->weight, $row->hashes]);
		$this->assertSame(json_encode([self::A]), $this->db->rows('mail_log_fuzzy')[0]['hashes'] ?? null);
		$this->assertSame(['Weight: 30'], $this->requests[0][1]['weight'] ?? null);
	}

	// a weight out of range refused before rspamd is asked
	public function testLearnLocalBadWeight(): void {
		$this->assertRefused(fn () => (new FuzzyService())->learnLocal($this->mail(), 1, 'alice', 0), 'Invalid weight 0, must be 1-65535', FuzzyError::Conflict);
		$this->assertSame([], $this->requests);
	}

	public function testLearnLocalLearned(): void {
		$this->learned = [[['id' => 3, 'qid' => 'Q1']]];
		$this->assertRefused(fn () => (new FuzzyService())->learnLocal($this->mail(), 1, 'alice'), 'Mail Q1 is already learned', FuzzyError::Conflict);
		$this->assertSame([], $this->requests);
	}

	// not recorded: the hashes no other learned mail holds deleted again
	public function testLearnLocalNotRecorded(): void {
		$this->db->failInsert['Q1'] = new RuntimeException('server has gone away');
		$this->shared = [self::A];
		$this->added([self::A, self::B]);
		$this->reply('{"success":true}');
		$this->assertRefused(fn () => (new FuzzyService())->learnLocal($this->mail(), 1, 'alice'), 'Mail Q1 could not be learned (database error)', FuzzyError::Internal);
		$this->assertSame([[self::B]], $this->deleted());
		$this->assertSame(['Flag: 1'], $this->requests[1][1]['flag'] ?? null);
		$this->assertSame([
			'error [FuzzyService_learnLocal] Q1 learned but not recorded: server has gone away',
			'error [FuzzyService_learnLocal] Q1 rolling back unrecorded hashes: ' . self::B . ' (1 kept, shared)',
		], $this->log->lines);
	}

	public function testLearnLocalNotRecordedAllShared(): void {
		$this->db->failInsert['Q1'] = new RuntimeException('server has gone away');
		$this->shared = [self::A, self::B];
		$this->added([self::A, self::B]);
		$this->assertRefused(fn () => (new FuzzyService())->learnLocal($this->mail(), 1, 'alice'), 'Mail Q1 could not be learned (database error)', FuzzyError::Internal);
		$this->assertSame([], $this->deleted());
		$this->assertSame('error [FuzzyService_learnLocal] Q1 rolling back unrecorded hashes: none (2 kept, shared)', $this->log->lines[1] ?? null);
	}

	// a concurrent learn recorded the same mail: its hashes kept
	public function testLearnLocalConcurrent(): void {
		$this->db->failInsert['Q1'] = new RuntimeException('Duplicate entry');
		$this->learned = [[], [['id' => 3, 'qid' => 'Q1']]];
		$this->added([self::A]);
		$this->assertRefused(fn () => (new FuzzyService())->learnLocal($this->mail(), 1, 'alice'), 'Mail Q1 is already learned', FuzzyError::Conflict);
		$this->assertSame([[], ['error [FuzzyService_learnLocal] Q1 learned but not recorded: Duplicate entry']], [$this->deleted(), $this->log->lines]);
	}

	public function testLearnLocalNotRolledBack(): void {
		$this->db->failInsert['Q1'] = new RuntimeException('server has gone away');
		$this->added([self::A, self::B]);
		$this->reply('{"error":"Unauthorized"}', 403);
		$this->assertRefused(fn () => (new FuzzyService())->learnLocal($this->mail(), 1, 'alice'), 'Mail Q1 was learned but not recorded; see the log', FuzzyError::Internal);
		$this->assertSame(
			'critical [FuzzyService_learnLocal] Q1 learned but neither recorded nor rolled back, hashes ' . self::A . ',' . self::B . ": rspamd for 'mx1' refused the request (403)",
			$this->log->lines[count($this->log->lines) - 1] ?? null
		);
	}

	// the digests other learned mails hold stay; the slot another list keeps is warned of
	public function testUnlearnLocal(): void {
		$this->shared = [self::A];
		$this->holders = [['qid' => 'L4', 'flag' => 2, 'h' => self::A]];
		$this->reply('{"success":true}');
		(new FuzzyService())->unlearn(self::row(), 'alice');
		$this->assertSame([[self::B]], $this->deleted());
		$this->assertSame(['Flag: 1'], $this->requests[0][1]['flag'] ?? null);
		$this->assertSame([['delete from `mail_log_fuzzy` where `id` = ?', [3]]], $this->db->changes);
		$msg = "info Q1 unlearned from fuzzy 'Spam' (flag 1, weight 10), kept 1 hash(es) shared with other learned mails by 'alice'";
		$this->assertSame([$msg, $msg, "warning Q1 fuzzy 'Spam' slot stays active in rspamd: its hash is also learned in another list by L4"], $this->log->lines);
	}

	// a flag no longer configured is named by its number
	public function testUnlearnLocalAlone(): void {
		$this->reply('{"success":true}');
		(new FuzzyService())->unlearnLocal(self::row(flag: 9, qid: null), 'alice');
		$this->assertSame([[self::A, self::B]], $this->deleted());
		$msg = "info  unlearned from fuzzy 'flag 9' (flag 9, weight 10) by 'alice'";
		$this->assertSame([$msg, $msg], $this->log->lines);
	}

	// every digest shared: nothing to delete from rspamd
	public function testUnlearnLocalAllShared(): void {
		$this->shared = [self::A, self::B];
		(new FuzzyService())->unlearnLocal(self::row(), 'alice');
		$this->assertSame([[], 1], [$this->requests, count($this->db->changes)]);
		$this->assertStringEndsWith("kept 2 hash(es) shared with other learned mails by 'alice'", $this->log->lines[0] ?? '');
	}

	// the hashes recorded once each, as a list
	public function testAddWeightLocal(): void {
		$this->added([self::A, self::C]);
		$this->db->affect = fn (): int => 1;
		(new FuzzyService())->addWeight(self::row([self::A, self::B]), 5, 'alice');
		$this->assertSame([['Flag: 1'], ['Weight: 5']], [$this->requests[0][1]['flag'] ?? null, $this->requests[0][1]['weight'] ?? null]);
		$this->assertSame([[
			'update `mail_log_fuzzy` set `weight` = `weight` + 5, `hashes` = ? where `mail_log_fuzzy`.`id` = ?',
			[json_encode([self::A, self::B, self::C]), 3],
		]], $this->db->changes);
		$this->assertSame(['select * from `mail_logs` where `mail_logs`.`id` = ? limit 1', [7]], $this->db->selects[0] ?? null);
		$msg = "info Q1 fuzzy 'Spam' (flag 1) weight +5, now 15, by 'alice'";
		$this->assertSame([$msg, $msg], $this->log->lines);
	}

	// a record without a qid: named ''
	public function testAddWeightLocalWithoutQid(): void {
		$this->added([self::A]);
		$this->db->affect = fn (): int => 1;
		(new FuzzyService())->addWeightLocal(self::row([self::A], qid: null), 5, 'alice');
		$this->assertSame("info  fuzzy 'Spam' (flag 1) weight +5, now 15, by 'alice'", $this->log->lines[0] ?? null);
	}

	// a weight out of range refused before rspamd is asked
	public function testAddWeightLocalBadWeight(): void {
		$this->assertRefused(fn () => (new FuzzyService())->addWeightLocal(self::row(), 0, 'alice'), 'Invalid weight 0, must be 1-65535', FuzzyError::Conflict);
		$this->assertSame([], $this->requests);
	}

	// 65535 in all at most
	public function testAddWeightLocalUpToTheMost(): void {
		$this->added([self::A]);
		$this->db->affect = fn (): int => 1;
		(new FuzzyService())->addWeightLocal(self::row(weight: 65530), 5, 'alice');
		$this->assertStringEndsWith('now 65535, by \'alice\'', $this->log->lines[0] ?? '');
		$this->assertRefused(fn () => (new FuzzyService())->addWeightLocal(self::row(weight: 65530), 6, 'alice'), 'Mail Q1: total weight would exceed 65535', FuzzyError::Conflict);
		$this->assertCount(1, $this->requests);
	}

	/** @return array<string, array{list<array<string, mixed>>}> */
	public static function mailsGone(): array {
		return ['gone' => [[]], 'not stored' => [[['id' => 7, 'qid' => 'Q1', 'server' => 'mx1', 'mail_stored' => 0]]]];
	}

	/** @param list<array<string, mixed>> $mails */
	#[DataProvider('mailsGone')]
	public function testAddWeightLocalNotStored(array $mails): void {
		$this->mails = $mails;
		$this->assertRefused(fn () => (new FuzzyService())->addWeightLocal(self::row(), 5, 'alice'), 'Mail Q1 is not stored', FuzzyError::Conflict);
		$this->assertSame([], $this->requests);
	}

	// unlearned meanwhile: the weight stays in rspamd, recorded nowhere
	public function testAddWeightLocalRecordGone(): void {
		$this->added([self::A, self::B]);
		$this->assertRefused(fn () => (new FuzzyService())->addWeightLocal(self::row([self::A]), 5, 'alice'), 'Mail Q1 was unlearned meanwhile; see the log', FuzzyError::Conflict);
		$this->assertSame(['critical [FuzzyService_addWeightLocal] Q1 weight 5 added in rspamd but the fuzzy record is gone, hashes ' . self::A . ',' . self::B], $this->log->lines);
	}

	public function testAddWeightLocalNotRecorded(): void {
		$this->added([self::A]);
		$this->db->affect = fn (): never => throw new RuntimeException('server has gone away');
		$this->assertRefused(fn () => (new FuzzyService())->addWeightLocal(self::row([self::A]), 5, 'alice'), 'Mail Q1: weight added in rspamd but not recorded; see the log', FuzzyError::Internal);
		$this->assertSame(['critical [FuzzyService_addWeightLocal] Q1 weight 5 added in rspamd but not recorded: server has gone away'], $this->log->lines);
	}
}
