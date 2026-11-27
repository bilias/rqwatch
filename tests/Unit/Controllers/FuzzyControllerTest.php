<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The fuzzy pages and actions, on RecordingDatabase, rspamd's controller a
 MockHttpClient through ApiClient's seam. Admin only, with fuzzy learning
 enabled; the actions need their CSRF token too. The list of learned
 mails, newest first or by last hit (once that migration is done), and a
 search of it as a GET page of ?q= that the search form posts to. The
 mails that hit a learned mail, said when fewer than its hits are left
 (needs the hit mails migration). Learning a mail as a flag, with the
 list's weight or one given (1-65535), back to its detail, said; a mail
 not found goes home; rspamd's refusal is said, any other failure logged
 and "Error. Contact admin". Unlearning a learned mail, back to its
 detail (or the list, from there); adding weight to it.
*/

namespace Tests\Unit\Controllers;

use App\Configuration\Config;
use App\Controllers\FuzzyController;
use App\Core\Routing\RouteName;
use App\Inventory\Migrations;
use App\Services\ApiClient;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class FuzzyControllerTest extends ControllerTestCase
{
	private const string A = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';

	private string $dir = '';

	/** @var list<MockResponse> */
	private array $replies = [];

	/** @var list<string> the urls asked */
	private array $requests = [];

	/** @var array<string, mixed> the learned mail */
	private array $fuzzy = ['id' => 3, 'mail_log_id' => 7, 'qid' => 'Q1', 'api_server' => 'mx1', 'flag' => 1, 'weight' => 10, 'hashes' => '["' . self::A . '"]', 'hits' => 4,
		'learned_by' => 'root', 'created_at' => '2026-11-01 10:00:00', 'updated_at' => '2026-11-01 10:00:00', 'last_hit_at' => '2026-11-20 10:00:00'];

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		Config::set('fuzzy_learn_enable', true);
		Config::set('fuzzy_learn_flags', [1 => ['label' => 'Spam'], 2 => ['label' => 'Phish', 'weight' => 20]]);
		Config::set('API_SERVERS', ['mx1' => ['fuzzy_url' => 'http://127.0.0.1:11334']]);
		$this->setEnv('MY_API_SERVER_ALIAS', 'mx1');
		$this->setEnv('RSPAMD_CONTROLLER_ENABLE_PASS', null);
		(new ReflectionProperty(ApiClient::class, 'httpClient'))->setValue(null, $this->client(...));
		$this->dir = sys_get_temp_dir() . '/rqwatch-fc-' . bin2hex(random_bytes(4));
		mkdir($this->dir);
		file_put_contents("{$this->dir}/Q1", "Subject: spam\r\n\r\nBuy now\r\n");
		$this->setEnv('QUARANTINE_DIR', $this->dir);
	}

	#[\Override]
	protected function tearDown(): void {
		(new ReflectionProperty(ApiClient::class, 'httpClient'))->setValue(null, null);
		@unlink("{$this->dir}/Q1");
		@rmdir($this->dir);
		parent::tearDown();
	}

	private function client(): HttpClientInterface {
		return new MockHttpClient(function (string $method, string $url): MockResponse {
			$this->requests[] = $url;
			return array_shift($this->replies) ?? throw new \LogicException("No reply queued for {$url}");
		});
	}

	/** @param list<string> $hashes */
	private function added(array $hashes = [self::A]): void {
		$this->replies[] = new MockResponse((string) json_encode(['success' => true, 'hashes' => $hashes]), ['http_code' => 200]);
	}

	/** @param array<string, mixed> $parameters */
	private function url(RouteName $route, array $parameters = []): string {
		return $this->urls->generate($route->value, $parameters);
	}

	private function fuzzyController(Request $request): FuzzyController {
		return $this->controller(new FuzzyController(), $request);
	}

	private function html(Response $response): string {
		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());
		return (string) $response->getContent();
	}

	private function learnedList(int $total = 1): void {
		$this->answers[] = ['count(*) as aggregate', [['aggregate' => $total]]];
		$this->answers[] = ['from `mail_log_fuzzy`', [$this->fuzzy]];
	}

	public function testList(): void {
		$this->learnedList();
		$request = self::request($this->url(RouteName::ADMIN_FUZZY), self::ADMIN);
		$this->framed($request);
		$html = $this->html($this->fuzzyController($request)->showAll());
		$this->assertFramed($html, 'Fuzzy');
		$this->assertStringContainsString('Fuzzy learned mails: 1', $html);
		$this->assertStringContainsString('sorted by learned date', $html);
		$this->assertStringContainsString('>Q1</a>', $html);
		$this->assertStringContainsString('name="fuzzy_search_form[search]"', $html);
		$this->assertSame('select * from `mail_log_fuzzy` order by `id` desc limit 50 offset 0', $this->selected('limit 50 offset')[0] ?? null);
	}

	public function testListByLastHit(): void {
		$this->learnedList();
		$request = self::request('/admin/fuzzy', self::ADMIN, ['sort' => 'hit', 'page' => 2]);
		$html = $this->html($this->fuzzyController($request)->showAll());
		$this->assertStringContainsString('sorted by last hit', $html);
		$this->assertSame('select * from `mail_log_fuzzy` order by last_hit_at IS NULL, `last_hit_at` desc, `id` desc limit 50 offset 50', $this->selected('limit 50 offset')[0] ?? null);
	}

	// before the hits migration there is no last hit to sort by
	public function testListByLastHitNeedsMigration(): void {
		$this->status->setMigrationState(Migrations::MAIL_LOG_FUZZY_HITS, Migrations::STATUS_RUNNING);
		$this->learnedList();
		$html = $this->html($this->fuzzyController(self::request('/admin/fuzzy', self::ADMIN, ['sort' => 'hit']))->showAll());
		$this->assertStringContainsString('sorted by learned date', $html);
	}

	public function testSearch(): void {
		$this->learnedList();
		$request = self::request('/admin/fuzzy/search', self::ADMIN, ['q' => ' Q1 ']);
		$html = $this->html($this->fuzzyController($request)->search());
		$this->assertStringContainsString("Fuzzy learned mails matching 'Q1': 1", $html);
		$this->assertStringContainsString('value="Q1"', $html);
		$this->assertContains('%Q1%', $this->selected('count(*) as aggregate')[1] ?? []);
	}

	/** @return array<string, array{string}> */
	public static function badSearches(): array {
		return [
			'none' => [''],
			'too long' => [str_repeat('a', 129)],
			'a space inside' => ['a b'],
		];
	}

	#[DataProvider('badSearches')]
	public function testBadSearch(string $q): void {
		$response = $this->fuzzyController(self::request('/admin/fuzzy/search', self::ADMIN, ['q' => $q]))->search();
		$this->assertRedirect($this->url(RouteName::ADMIN_FUZZY), $response);
		$this->assertSame([], $this->db->selects);
	}

	public function testSearchLongest(): void {
		$this->fuzzyController(self::request('/admin/fuzzy/search', self::ADMIN, ['q' => str_repeat('a', 128)]))->search();
		$this->assertNotNull($this->selected('count(*) as aggregate'));
	}

	/** @return array{Response, Request} */
	private function postSearch(string $search): array {
		$request = self::request('/admin/fuzzy/search', self::ADMIN);
		$request->setMethod('POST');
		$request->request->replace(['fuzzy_search_form' => ['search' => $search, '_token' => self::csrf($request, 'fuzzy_search_form')]]);
		return [$this->fuzzyController($request)->search(), $request];
	}

	public function testSearchForm(): void {
		[$response] = $this->postSearch('Q1');
		$this->assertRedirect($this->url(RouteName::ADMIN_FUZZY_SEARCH) . '?q=Q1', $response);
	}

	public function testSearchFormInvalid(): void {
		[$response, $request] = $this->postSearch('a b');
		$this->assertRedirect($this->url(RouteName::ADMIN_FUZZY), $response);
		$this->assertSame(['error' => ['The value can only contain letters, numbers and ._+-@']], self::flashes($request));
	}

	/** @return array<string, array{string}> */
	public static function pages(): array {
		return ['list' => ['showAll'], 'search' => ['search'], 'hit mails' => ['showHitMails']];
	}

	private function page(string $action, Request $request): Response {
		$controller = $this->fuzzyController($request);
		return match ($action) {
			'showAll' => $controller->showAll(),
			'search' => $controller->search(),
			default => $controller->showHitMails(3),
		};
	}

	#[DataProvider('pages')]
	public function testFramedPages(string $action): void {
		$this->fuzzyRow();
		$this->learnedList();
		$request = self::request($this->url(RouteName::ADMIN_FUZZY), self::ADMIN, ['q' => 'Q1']);
		$this->framed($request);
		$this->assertFramed($this->html($this->page($action, $request)), 'Fuzzy');
	}

	#[DataProvider('pages')]
	public function testQidBox(string $action): void {
		$this->fuzzyRow();
		$this->assertToQid($this->page($action, self::qidPosted(self::ADMIN)));
	}

	#[DataProvider('pages')]
	public function testPageUserRefused(string $action): void {
		$request = self::request('/', self::USER);
		$this->assertRedirect($this->url(RouteName::DAY_LOGS), $this->page($action, $request));
		$this->assertSame(['error' => ['Permission denied']], self::flashes($request));
		$this->assertSame(["warning 'alice' tried to show fuzzy learned mails without admin authorization"], $this->log->lines);
	}

	#[DataProvider('pages')]
	public function testPageDisabled(string $action): void {
		Config::set('fuzzy_learn_enable', false);
		$request = self::request('/', self::ADMIN);
		$this->assertRedirect($this->url(RouteName::ADMIN_DAY_LOGS), $this->page($action, $request));
		$this->assertSame(['error' => ['Fuzzy learning is disabled']], self::flashes($request));
	}

	public function testPageNeedsMigration(): void {
		$this->status->setMigrationState(Migrations::MAIL_LOG_FUZZY, Migrations::STATUS_RUNNING);
		$request = self::request('/', self::ADMIN);
		$this->assertRedirect($this->url(RouteName::ADMIN_DAY_LOGS), $this->page('showAll', $request));
	}

	private function fuzzyRow(): void {
		$this->answers[] = ['from `mail_log_fuzzy` where `mail_log_fuzzy`.`id` = ?', fn (array $bindings): array => ($bindings[0] ?? null) === 3 ? [$this->fuzzy] : []];
	}

	public function testHitMails(): void {
		$this->fuzzyRow();
		$this->answers[] = ['from `mail_log_fuzzy_hits`', [['mail_log_id' => 9]]];
		$request = self::request($this->url(RouteName::ADMIN_FUZZY_HITS, ['id' => 3]), self::ADMIN);
		$this->framed($request);
		$html = $this->html($this->fuzzyController($request)->showHitMails(3));
		$this->assertFramed($html, null);
		$this->assertStringContainsString("on &#039;Spam&#039; learned mail", $html);
		$this->assertStringContainsString('1 of 4 hits listed: the rest are on purged mails or were counted before the hits were linked', $html);
		$this->assertStringContainsString($this->url(RouteName::ADMIN_DETAIL, ['type' => 'id', 'value' => 7]), $html);
	}

	public function testHitMailsAllListed(): void {
		$this->fuzzy['hits'] = 1;
		$this->fuzzy['mail_log_id'] = null;
		$this->fuzzy['flag'] = 9;
		$this->fuzzyRow();
		$this->answers[] = ['from `mail_log_fuzzy_hits`', [['mail_log_id' => 9]]];
		$html = $this->html($this->fuzzyController(self::request('/admin/fuzzy/3/hits', self::ADMIN))->showHitMails(3));
		$this->assertStringNotContainsString('hits listed', $html);
		$this->assertStringContainsString("on &#039;flag 9&#039; learned mail", $html);
	}

	public function testHitMailsNeedMigration(): void {
		$this->status->setMigrationState(Migrations::MAIL_LOG_FUZZY_HITS_MAILS, Migrations::STATUS_RUNNING);
		$request = self::request('/', self::ADMIN);
		$this->assertRedirect($this->url(RouteName::ADMIN_FUZZY), $this->fuzzyController($request)->showHitMails(3));
		$this->assertSame(['error' => ['The fuzzy hit mails need the mail log fuzzy hit mails migration (db:migrate_mail_log_fuzzy_hits_mails)']], self::flashes($request));
	}

	public function testHitMailsNotFound(): void {
		$request = self::request('/', self::ADMIN);
		$this->assertRedirect($this->url(RouteName::ADMIN_FUZZY), $this->fuzzyController($request)->showHitMails(3));
		$this->assertSame(['error' => ['Fuzzy record 3 not found']], self::flashes($request));
	}

	public function testHitMailsQueryError(): void {
		$this->fuzzyRow();
		$this->answers[] = ['from `mail_log_fuzzy_hits`', function (): array {
			throw new RuntimeException('server has gone away');
		}];
		$request = self::request('/', self::ADMIN);
		$this->assertRedirect($this->url(RouteName::ADMIN_FUZZY), $this->fuzzyController($request)->showHitMails(3));
		$this->assertSame(['error' => ['Fuzzy hits query error. Check logs.']], self::flashes($request));
		$this->assertSame(['error [FuzzyController_showHitMails] query error: server has gone away'], $this->log->lines);
	}

	/**
	 * @param array<string, string> $query
	 * @param array<string, mixed> $session
	 * @return array{Response, Request}
	 */
	private function act(string $action, array $query = [], array $session = self::ADMIN, ?string $token = null): array {
		$request = self::request('/', $session, $query);
		$request->query->set('_token', $token ?? self::csrf($request, "fuzzy_{$action}"));
		$controller = $this->fuzzyController($request);
		$response = match ($action) {
			'learn' => $controller->learn(7, 2),
			'unlearn' => $controller->unlearn(3),
			default => $controller->boost(3),
		};
		return [$response, $request];
	}

	private function detail(int $id = 7): string {
		return $this->url(RouteName::ADMIN_DETAIL, ['type' => 'id', 'value' => $id]);
	}

	private function mail(): void {
		$this->answers[] = ['from `mail_logs`', [['id' => 7, 'qid' => 'Q1', 'server' => 'mx1', 'mail_stored' => 1, 'mail_location' => "{$this->dir}/Q1"]]];
	}

	public function testLearn(): void {
		$this->mail();
		$this->added();
		[$response, $request] = $this->act('learn');
		$this->assertRedirect($this->detail(), $response);
		$this->assertSame(['success' => ["Mail Q1 learned as fuzzy 'Phish' (weight 20)"]], self::flashes($request));
		$this->assertSame(['http://127.0.0.1:11334/fuzzyadd'], $this->requests);
		$this->assertSame(20, $this->db->rows('mail_log_fuzzy')[0]['weight'] ?? null);
	}

	public function testLearnWeight(): void {
		$this->mail();
		$this->added();
		[$response, $request] = $this->act('learn', ['weight' => '65535']);
		$this->assertSame(['success' => ["Mail Q1 learned as fuzzy 'Phish' (weight 65535)"]], self::flashes($request));
		$this->assertSame(65535, $this->db->rows('mail_log_fuzzy')[0]['weight'] ?? null);
	}

	/** @return array<string, array{string}> */
	public static function badWeights(): array {
		return ['zero' => ['0'], 'too much' => ['65536'], 'not a number' => ['5x'], 'negative' => ['-5']];
	}

	#[DataProvider('badWeights')]
	public function testLearnBadWeight(string $weight): void {
		[$response, $request] = $this->act('learn', ['weight' => $weight]);
		$this->assertRedirect($this->detail(), $response);
		$this->assertSame(['error' => ['Invalid weight, must be 1-65535']], self::flashes($request));
		$this->assertSame([[], []], [$this->db->selects, $this->requests]);
	}

	public function testLearnMailNotFound(): void {
		[$response, $request] = $this->act('learn');
		$this->assertRedirect($this->url(RouteName::ADMIN_DAY_LOGS), $response);
		$this->assertSame(['error'], array_keys(self::flashes($request)));
		$this->assertSame([], $this->requests);
	}

	public function testLearnRefused(): void {
		$this->mail();
		$this->answers[] = ['from `mail_log_fuzzy` where `mail_log_id` = ?', [$this->fuzzy]];
		[$response, $request] = $this->act('learn');
		$this->assertRedirect($this->detail(), $response);
		$this->assertSame(['error' => ['Mail Q1 is already learned']], self::flashes($request));
	}

	public function testLearnRspamdDown(): void {
		$this->mail();
		$this->replies[] = new MockResponse('down', ['http_code' => 500]);
		[$response, $request] = $this->act('learn');
		$this->assertRedirect($this->detail(), $response);
		$this->assertSame(['error' => ["rspamd for 'mx1' refused the request (500)"]], self::flashes($request));
	}

	public function testLearnError(): void {
		$this->mail();
		$this->answers = array_merge([['from `mail_log_fuzzy` where `mail_log_id` = ?', function (): array {
			throw new RuntimeException('gone away');
		}]], $this->answers);
		[$response, $request] = $this->act('learn');
		$this->assertRedirect($this->detail(), $response);
		$this->assertSame(['error' => ['Error. Contact admin']], self::flashes($request));
		$this->assertSame(['error [FuzzyController] learn of mail 7 as flag 2 failed: gone away'], $this->log->lines);
	}

	public function testUnlearn(): void {
		$this->fuzzyRow();
		$this->replies[] = new MockResponse((string) json_encode(['success' => true]), ['http_code' => 200]);
		[$response, $request] = $this->act('unlearn');
		$this->assertRedirect($this->detail(), $response);
		$this->assertSame(['success' => ["Mail Q1 unlearned from fuzzy 'Spam'"]], self::flashes($request));
		$this->assertSame(['http://127.0.0.1:11334/fuzzydelhash'], $this->requests);
	}

	public function testUnlearnFromList(): void {
		$this->fuzzyRow();
		$this->replies[] = new MockResponse((string) json_encode(['success' => true]), ['http_code' => 200]);
		[$response] = $this->act('unlearn', ['return' => 'list']);
		$this->assertRedirect($this->url(RouteName::ADMIN_FUZZY), $response);
	}

	public function testUnlearnRspamdDown(): void {
		$this->fuzzyRow();
		$this->replies[] = new MockResponse('down', ['http_code' => 500]);
		[$response, $request] = $this->act('unlearn');
		$this->assertRedirect($this->detail(), $response);
		$this->assertSame(['error' => ["rspamd for 'mx1' refused the request (500)"]], self::flashes($request));
	}

	public function testUnlearnError(): void {
		$this->fuzzyRow();
		$this->answers = array_merge([['JSON_TABLE', function (): array {
			throw new RuntimeException('gone away');
		}]], $this->answers);
		[$response, $request] = $this->act('unlearn');
		$this->assertSame(['error' => ['Error. Contact admin']], self::flashes($request));
		$this->assertSame(['error [FuzzyController] unlearn of record 3 failed: gone away'], $this->log->lines);
	}

	public function testUnlearnNotFound(): void {
		[$response, $request] = $this->act('unlearn');
		$this->assertRedirect($this->url(RouteName::ADMIN_DAY_LOGS), $response);
		$this->assertSame(['error' => ['Fuzzy record 3 not found']], self::flashes($request));
	}

	public function testBoost(): void {
		$this->fuzzyRow();
		$this->mail();
		$this->added();
		$this->db->affect = fn (): int => 1;
		[$response, $request] = $this->act('boost', ['weight' => '5']);
		$this->assertRedirect($this->detail(), $response);
		$this->assertSame(['success' => ["Mail Q1: weight +5 added to fuzzy 'Spam', now 15"]], self::flashes($request));
	}

	public function testBoostTooMuch(): void {
		$this->fuzzyRow();
		[$response, $request] = $this->act('boost', ['weight' => '65530']);
		$this->assertRedirect($this->detail(), $response);
		$this->assertSame(['error' => ['Mail Q1: total weight would exceed 65535']], self::flashes($request));
		$this->assertSame([], $this->requests);
	}

	public function testBoostError(): void {
		$this->fuzzyRow();
		$this->answers = array_merge([['from `mail_logs`', function (): array {
			throw new RuntimeException('gone away');
		}]], $this->answers);
		[$response, $request] = $this->act('boost', ['weight' => '5']);
		$this->assertSame(['error' => ['Error. Contact admin']], self::flashes($request));
		$this->assertSame(['error [FuzzyController] add weight to record 3 failed: gone away'], $this->log->lines);
	}

	#[DataProvider('badWeights')]
	public function testBoostBadWeight(string $weight): void {
		$this->fuzzyRow();
		[$response, $request] = $this->act('boost', ['weight' => $weight]);
		$this->assertRedirect($this->detail(), $response);
		$this->assertSame(['error' => ['Invalid weight, must be 1-65535']], self::flashes($request));
		$this->assertSame([], $this->requests);
	}

	public function testBoostNotFound(): void {
		[$response, $request] = $this->act('boost', ['weight' => '5']);
		$this->assertRedirect($this->url(RouteName::ADMIN_DAY_LOGS), $response);
		$this->assertSame(['error' => ['Fuzzy record 3 not found']], self::flashes($request));
	}

	/** @return array<string, array{string}> */
	public static function actions(): array {
		return ['learn' => ['learn'], 'unlearn' => ['unlearn'], 'boost' => ['boost']];
	}

	#[DataProvider('actions')]
	public function testActionUserRefused(string $action): void {
		[$response, $request] = $this->act($action, [], self::USER);
		$this->assertRedirect($this->url(RouteName::DAY_LOGS), $response);
		$this->assertSame(['error' => ['Permission denied']], self::flashes($request));
		$this->assertSame(["warning 'alice' tried fuzzy_{$action} without admin authorization"], $this->log->lines);
	}

	#[DataProvider('actions')]
	public function testActionBadToken(string $action): void {
		[$response, $request] = $this->act($action, [], self::ADMIN, 'forged');
		$this->assertRedirect($this->url(RouteName::ADMIN_DAY_LOGS), $response);
		$this->assertSame(['error' => ['Invalid or expired request. Please try again.']], self::flashes($request));
		$this->assertSame(["warning CSRF check failed on fuzzy_{$action} from 192.0.2.7"], $this->log->lines);
	}

	#[DataProvider('actions')]
	public function testActionDisabled(string $action): void {
		Config::set('fuzzy_learn_enable', false);
		[$response, $request] = $this->act($action);
		$this->assertRedirect($this->url(RouteName::ADMIN_DAY_LOGS), $response);
		$this->assertSame(['error' => ['Fuzzy learning is disabled']], self::flashes($request));
		$this->assertSame([], $this->db->selects);
	}
}
