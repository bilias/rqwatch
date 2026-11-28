<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The mail pages, on RecordingDatabase, with a QUARANTINE_DIR of their own
 and MailerService's transport seam. The mails of all days, of one day,
 and the search results: the session's filters, plus ?date= (replacing
 any Date filter), ?month= (its first to last day, replacing them) and
 ?since= (listed first), each ignored when not a date. The quarantine by
 day and by month (a month that is not one said). The search page adds a
 filter (posted, back to a GET page), today's, the last 7 or 31 days, and
 deletes one (kept to edit and add again) or all. Reports: by a field of
 the report fields (an admin-only field refused to a user), and none
 when reports are off. A mail's detail; one not found goes home. Its
 release: to the original recipients (only a user's own, unless an
 admin) or to an alternate address, sent and logged; refused without a
 mailer, an empty or wrong choice. The mail shown with its inline styles
 allowed, and its attachments downloaded or opened inline (only safe
 types as themselves).
*/

namespace Tests\Unit\Controllers;

use App\Configuration\Config;
use App\Core\App;
use App\Core\AppContainer;
use App\Core\Cache\RedisCache;
use App\Controllers\MailLogController;
use App\Core\Routing\RouteName;
use App\Inventory\Migrations;
use App\Services\ApiClient;
use App\Services\MailerService;
use PHPUnit\Framework\Attributes\DataProvider;
use Redis;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class MailLogControllerTest extends ControllerTestCase
{
	private const string EML = "From: a@example.net\r\nTo: alice@example.org\r\nSubject: Invoice\r\nMIME-Version: 1.0\r\n"
		. "Content-Type: multipart/mixed; boundary=\"b1\"\r\n\r\n"
		. "--b1\r\nContent-Type: text/plain; charset=us-ascii\r\n\r\nPlease pay the held invoice\r\n"
		. "--b1\r\nContent-Type: application/pdf; name=\"inv.pdf\"\r\nContent-Disposition: attachment; filename=\"inv.pdf\"\r\nContent-Transfer-Encoding: base64\r\n\r\nUERG\r\n"
		. "--b1\r\nContent-Type: image/png; name=\"logo.png\"\r\nContent-Disposition: attachment; filename=\"logo.png\"\r\nContent-Transfer-Encoding: base64\r\n\r\niVBO\r\n"
		. "--b1--\r\n";

	private string $dir = '';

	/** @var array<string, mixed> the mail_logs row */
	private array $mail = [];

	/** @var list<Email> */
	private array $sent = [];

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->dir = sys_get_temp_dir() . '/rqwatch-ml-' . bin2hex(random_bytes(4));
		mkdir("{$this->dir}/Q41", 0777, true);
		file_put_contents("{$this->dir}/Q41/Q41", self::EML);
		$this->setEnv('QUARANTINE_DIR', $this->dir);
		$this->setEnv('QUARANTINE_DAYS', '30');
		$this->setEnv('MY_API_SERVER_ALIAS', 'mx1');
		$this->setEnv('MAILER_DSN', 'null://null');
		$this->setEnv('MAILER_FROM', 'rqwatch@example.org');
		$this->setEnv('WEB_HOST', 'rq.example.org');
		Config::set('release_mail_subject', 'Released');
		Config::set('dns_resolv', false);
		$this->mail = [
			'id' => 41, 'qid' => 'Q41', 'action' => 'reject', 'has_virus' => 0, 'mail_from' => 'a@example.net', 'mime_from' => 'a@example.net',
			'mime_to' => 'alice@example.org', 'subject' => 'Invoice', 'size' => 1200, 'score' => 15.5, 'server' => 'mx1', 'mail_stored' => 1,
			'mail_location' => "{$this->dir}/Q41/Q41", 'message_id' => '<41@example.net>', 'ip' => null, 'notified' => 0, 'notify_date' => null,
			'released' => 0, 'release_date' => null, 'notification_pending' => 0, 'created_at' => '2026-11-20 10:00:00',
			'updated_at' => '2026-11-20 10:00:00', 'created_day' => '2026-11-20', 'admin_notified' => null,
		];
		// a user's queries hold the recipients in a subquery: mail_logs first
		$this->answers = [
			['count(*) as aggregate', [['aggregate' => 1]]],
			['select `id` from `mail_logs`', fn (): array => [['id' => 41]]],
			['from `mail_logs`', fn (): array => [$this->mail]],
			['from `mail_log_recipients`', [['mail_log_id' => 41, 'recipient_email' => 'alice@example.org']]],
			['from `mail_log_data`', [['mail_log_id' => 41, 'symbols' => null, 'headers' => "Received: from x\r\n"]]],
		];
		$this->db->affect = fn (): int => 1;
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
		(new ReflectionProperty(ApiClient::class, 'httpClient'))->setValue(null, null);
		@unlink("{$this->dir}/Q41/Q41");
		@rmdir("{$this->dir}/Q41");
		@rmdir($this->dir);
		parent::tearDown();
	}

	private function record(SentMessage $message): void {
		$email = $message->getOriginalMessage();
		$this->assertInstanceOf(Email::class, $email);
		$this->sent[] = $email;
	}

	/** @param array<string, mixed> $parameters */
	private function url(RouteName $route, array $parameters = []): string {
		return $this->urls->generate($route->value, $parameters);
	}

	private function mails(Request $request): MailLogController {
		return $this->controller(new MailLogController(), $request);
	}

	private function html(Response $response): string {
		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());
		return (string) $response->getContent();
	}

	/**
	 * @param array<string, mixed> $session
	 * @param list<array{filter: string, choice: string, value: string}> $filters
	 * @return array<string, mixed>
	 */
	private static function withFilters(array $session, array $filters): array {
		return ['filters' => (string) json_encode($filters)] + $session;
	}

	public function testAll(): void {
		$request = self::request($this->url(RouteName::ADMIN_HOMEPAGE), self::ADMIN);
		$this->framed($request);
		$html = $this->html($this->mails($request)->showAll());
		$this->assertFramed($html, 'All');
		$this->assertStringContainsString('Invoice', $html);
	}

	public function testDay(): void {
		$request = self::request($this->url(RouteName::DAY_LOGS), self::USER);
		$html = $this->html($this->mails($request)->showDay('2026-11-20'));
		$this->assertStringContainsString('Invoice', $html);
		$this->assertContains('2026-11-20', $this->selected('count(*) as aggregate')[1] ?? []);
	}

	public function testToday(): void {
		$this->mails(self::request('/day', self::ADMIN))->showDay();
		$this->assertContains(date('Y-m-d'), $this->selected('count(*) as aggregate')[1] ?? []);
	}

	/**
	 * @param array<string, string> $query
	 * @param list<array{filter: string, choice: string, value: string}> $session
	 */
	private function results(array $query, array $session = []): string {
		$request = self::request('/admin/results', self::withFilters(self::ADMIN, $session), $query);
		return $this->html($this->mails($request)->showResults());
	}

	public function testResultsOfADate(): void {
		$html = $this->results(['date' => '2026-11-20'], [['filter' => 'Date', 'choice' => 'is greater than', 'value' => '2026-01-01'], ['filter' => 'Action', 'choice' => 'is equal to', 'value' => 'reject']]);
		$this->assertStringNotContainsString('2026-01-01', $html);
		$this->assertStringContainsString('2026-11-20', $html);
		$this->assertStringContainsString('reject', $html);
	}

	public function testResultsOfAMonth(): void {
		$html = $this->results(['month' => '2026-02']);
		$this->assertStringContainsString('2026-02-01', $html);
		$this->assertStringContainsString('2026-02-28', $html);
	}

	// ?month= replaces a Date filter set before, as ?date= does
	public function testResultsOfAMonthReplacesDate(): void {
		$html = $this->results(['month' => '2026-02'], [['filter' => 'Date', 'choice' => 'is greater than', 'value' => '2026-01-01']]);
		$this->assertStringNotContainsString('2026-01-01', $html);
		$this->assertStringContainsString('2026-02-28', $html);
	}

	// a report's drill-down: ?mail_from= is a filter, kept in the pages' links
	public function testResultsOfAReportValue(): void {
		$html = $this->results(['mail_from' => 'a@example.net']);
		$this->assertContains('a@example.net', $this->selected('select `id` from `mail_logs`')[1] ?? []);
		$this->assertStringContainsString('MAIL From', $html);
	}

	public function testResultsSince(): void {
		$this->assertStringContainsString('2026-11-01', $this->results(['since' => '2026-11-01']));
	}

	/** @return array<string, array{array<string, string>, string}> */
	public static function notDates(): array {
		return [
			'a month that is not one' => [['month' => '2026-13'], '2026-13'],
			'a month with a day' => [['month' => '2026-02-01'], '2026-02-01'],
			'since, not a date' => [['since' => 'yesterday'], 'yesterday'],
			'since, with more' => [['since' => '2026-11-01x'], '2026-11-01x'],
			'since, with more before' => [['since' => 'x2026-11-01'], 'x2026-11-01'],
			'since, with a line after' => [['since' => "2026-11-01\n"], '2026-11-01'],
			'a month with more before' => [['month' => 'x2026-11'], 'x2026-11'],
			'a month with a line after' => [['month' => "2026-11\n"], '2026-11-30'],
			'a month of the year 0' => [['month' => '0000-11'], '0000-11'],
		];
	}

	/** @param array<string, string> $query */
	#[DataProvider('notDates')]
	public function testResultsNotADate(array $query, string $value): void {
		$this->assertStringNotContainsString($value, $this->results($query));
	}

	public function testQuarantine(): void {
		array_splice($this->answers, 1, 0, [
			['as day, COUNT(*) as cnt', [['day' => '2026-11-20', 'cnt' => 3]]],
			['created_day AS day, COUNT(*) AS total', [['day' => '2026-11-20', 'total' => 9]]],
		]);
		$html = $this->html($this->mails(self::request('/admin/quarantine', self::ADMIN))->showQuarantine());
		$this->assertStringContainsString('2026-11-20', $html);
	}

	public function testQuarantinePerMonth(): void {
		array_splice($this->answers, 1, 0, [
			['AS month, COUNT(*) as cnt', [['month' => '2026-11', 'cnt' => 3]]],
			['created_day AS day, COUNT(*) AS total', [['day' => '2026-11-20', 'total' => 9]]],
		]);
		$html = $this->html($this->mails(self::request('/admin/quarantine/month', self::ADMIN))->showQuarantinePerMonth());
		$this->assertStringContainsString('2026-11', $html);
	}

	public function testQuarantineDay(): void {
		$html = $this->html($this->mails(self::request('/admin/quarantine/2026-11-20', self::ADMIN))->showQuarantineDay('2026-11-20'));
		$this->assertStringContainsString('Invoice', $html);
	}

	public function testQuarantineMonth(): void {
		$html = $this->html($this->mails(self::request('/admin/quarantine/month/2026-11', self::ADMIN))->showQuarantineMonth('2026-11'));
		$this->assertStringContainsString('Invoice', $html);
	}

	public function testQuarantineNotAMonth(): void {
		$request = self::request('/admin/quarantine/month/x', self::ADMIN);
		$this->assertRedirect($this->url(RouteName::ADMIN_QUARANTINE_PER_MONTH), $this->mails($request)->showQuarantineMonth('2026-13'));
		$this->assertArrayHasKey('error', self::flashes($request));
	}

	/** @return array<string, array{string, bool, bool}> page, refreshed, in quarantine */
	public static function mailLists(): array {
		return [
			'all mails' => ['showAll', true, false],
			'search results' => ['showResults', false, false],
			'a day' => ['showDay', true, false],
			'a quarantine day' => ['showQuarantineDay', false, true],
			'a quarantine month' => ['showQuarantineMonth', false, true],
			'a virus\'s mails' => ['showVirusMails', false, false],
		];
	}

	private function mailList(string $page): string {
		$mails = $this->mails(self::request('/', self::ADMIN, ['virus' => 'Eicar']));
		return $this->html(match ($page) {
			'showAll' => $mails->showAll(),
			'showResults' => $mails->showResults(),
			'showDay' => $mails->showDay('2026-11-20'),
			'showQuarantineDay' => $mails->showQuarantineDay('2026-11-20'),
			'showQuarantineMonth' => $mails->showQuarantineMonth('2026-11'),
			default => $mails->showVirusMails(),
		});
	}

	// the page settings reach every mail list; no privacy setting shows the subjects
	#[DataProvider('mailLists')]
	public function testMailListSettings(string $page, bool $refreshed, bool $quarantine): void {
		Config::set('items_per_page', 1);
		Config::set('max_items', 2);
		Config::set('refresh_rate', 60);
		Config::set('subject_privacy', null);
		$this->answers = array_merge([
			['count(*) as aggregate', [['aggregate' => 2]]],
			['select `id` from `mail_logs`', [['id' => 41], ['id' => 40]]],
			['from `mail_log_viruses`', [['mail_log_id' => 41], ['mail_log_id' => 40]]],
		], $this->answers);
		$html = $this->mailList($page);
		foreach (['/ Limited', '| Displaying 1/page', 'Invoice'] as $shown) {
			$this->assertStringContainsString($shown, $html);
		}
		$this->assertSame($refreshed, str_contains($html, '| Refreshing every 60 s'));
		$this->assertSame($quarantine, str_contains($html, ' in Quarantine'));
		// no ?page: the first
		$sql = $this->selected('limit 1 offset')[0] ?? null;
		if ($sql !== null) {
			$this->assertStringEndsWith('offset 0', $sql);
		}
	}

	/** @return array<string, array{string}> */
	public static function mailListPages(): array {
		return array_map(fn (array $list): array => [$list[0]], self::mailLists());
	}

	// a user's day pages are on her own list
	public function testUserDayPageLinks(): void {
		Config::set('items_per_page', 1);
		$this->answers = array_merge([['count(*) as aggregate', [['aggregate' => 2]]]], $this->answers);
		$html = $this->html($this->mails(self::request('/day', self::USER))->showDay('2026-11-20'));
		$this->assertStringContainsString('href="' . $this->url(RouteName::DAY_LOGS) . '?page=2', $html);
	}

	// a quarantine day's or month's pages stay on it, for an admin and a user
	public function testQuarantineDayAndMonthPageLinks(): void {
		Config::set('items_per_page', 1);
		$this->answers = array_merge([['count(*) as aggregate', [['aggregate' => 2]]], ['select `id` from `mail_logs`', [['id' => 41], ['id' => 40]]]], $this->answers);
		foreach ([[self::ADMIN, RouteName::ADMIN_QUARANTINE_DAY, RouteName::ADMIN_QUARANTINE_MONTH], [self::USER, RouteName::QUARANTINE_DAY, RouteName::QUARANTINE_MONTH]] as [$session, $day, $month]) {
			$html = $this->html($this->mails(self::request('/', $session))->showQuarantineDay('2026-10-05'));
			$this->assertStringContainsString($this->url($day, ['date' => '2026-10-05']) . '?page=2', $html);
			$html = $this->html($this->mails(self::request('/', $session))->showQuarantineMonth('2026-10'));
			$this->assertStringContainsString($this->url($month, ['month' => '2026-10']) . '?page=2', $html);
		}
	}

	#[DataProvider('mailListPages')]
	public function testMailListSubjectPrivacy(string $page): void {
		Config::set('subject_privacy', true);
		$this->answers = array_merge([['from `mail_log_viruses`', [['mail_log_id' => 41]]]], $this->answers);
		$this->assertStringNotContainsString('Invoice', $this->mailList($page));
	}

	/**
	 * Two quarantine days, 3 and 1 mails stored of 6 and 4; and two months,
	 * 3 and 1 of the 10 and 2 their days total. Past months: a month read
	 * wrong as now would not show.
	 */
	private function quarantineDays(): void {
		$this->answers = array_merge([
			['count(*) as aggregate', [['aggregate' => 2]]],
			['as day, COUNT(*) as cnt', [['day' => '2026-09-20', 'cnt' => 3], ['day' => '2026-09-19', 'cnt' => 1]]],
			['AS month, COUNT(*) as cnt', [['month' => '2026-09', 'cnt' => 3], ['month' => '2026-08', 'cnt' => 1]]],
			['created_day AS day, COUNT(*) AS total', [['day' => '2026-09-20', 'total' => 6], ['day' => '2026-09-19', 'total' => 4], ['day' => '2026-08-05', 'total' => 2]]],
		], $this->answers);
	}

	private static function chartData(string $html): string {
		return preg_match('#<script type="application/json"[^>]*>(.*?)</script>#s', $html, $m) === 1 ? $m[1] : '';
	}

	// the days' mails summed; the chart bars them, with the share of each day's total stored
	public function testQuarantineChart(): void {
		$this->quarantineDays();
		$html = $this->html($this->mails(self::request('/admin/quarantine', self::ADMIN))->showQuarantine());
		$this->assertStringContainsString('4 mails in page', $html);
		$this->assertStringContainsString('/js/chart.umd.min.js', $html);
		$chart = self::chartData($html);
		$this->assertStringContainsString('"data":[1,3]', $chart);
		$this->assertStringContainsString('"data":[25,50]', $chart);
		$this->assertSame(['2026-09-19', '2026-09-20'], $this->selected('created_day AS day, COUNT(*) AS total')[1] ?? null);
	}

	public function testQuarantinePerMonthChart(): void {
		$this->quarantineDays();
		$html = $this->html($this->mails(self::request('/admin/quarantine/month', self::ADMIN))->showQuarantinePerMonth());
		$this->assertStringContainsString('4 mails in page', $html);
		$this->assertStringContainsString('/js/chart.umd.min.js', $html);
		$chart = self::chartData($html);
		$this->assertStringContainsString('"data":[1,3]', $chart);
		$this->assertStringContainsString('"data":[50,30]', $chart);
		$this->assertSame(['2026-08-01', '2026-09-30'], $this->selected('created_day AS day, COUNT(*) AS total')[1] ?? null);
	}

	// days and months with no mail stored: no chart
	public function testQuarantineNoMailsNoChart(): void {
		$this->answers = array_merge([
			['count(*) as aggregate', [['aggregate' => 1]]],
			['as day, COUNT(*) as cnt', [['day' => '2026-11-20', 'cnt' => 0]]],
			['AS month, COUNT(*) as cnt', [['month' => '2026-11', 'cnt' => 0]]],
		], $this->answers);
		$this->assertStringNotContainsString('/js/chart.umd.min.js', $this->html($this->mails(self::request('/admin/quarantine', self::ADMIN))->showQuarantine()));
		$this->assertStringNotContainsString('/js/chart.umd.min.js', $this->html($this->mails(self::request('/admin/quarantine/month', self::ADMIN))->showQuarantinePerMonth()));
	}

	// q_items_per_page: the days (months) of a page, 31 when unset
	public function testQuarantinePageSize(): void {
		Config::set('q_items_per_page', null);
		$this->answers = array_merge([
			['count(*) as aggregate', [['aggregate' => 40]]],
			['as day, COUNT(*) as cnt', [['day' => '2026-11-20', 'cnt' => 3]]],
			['AS month, COUNT(*) as cnt', [['month' => '2026-11', 'cnt' => 3]]],
			['created_day AS day, COUNT(*) AS total', []],
		], $this->answers);
		$this->assertStringContainsString('| Displaying 31 days/page', $this->html($this->mails(self::request('/admin/quarantine', self::ADMIN))->showQuarantine()));
		Config::set('q_items_per_page', 1);
		$this->answers = array_merge([['AS month, COUNT(*) as cnt', [['month' => '2026-11', 'cnt' => 3], ['month' => '2026-10', 'cnt' => 1]]]], $this->answers);
		$this->assertStringContainsString('| Displaying 1 months/page', $this->html($this->mails(self::request('/admin/quarantine/month', self::ADMIN))->showQuarantinePerMonth()));
	}

	/** @return array<string, array{string, RouteName, RouteName}> page, the admin's url, a user's url */
	public static function pagedLists(): array {
		return [
			'all mails' => ['showAll', RouteName::ADMIN_HOMEPAGE, RouteName::HOMEPAGE],
			'search results' => ['showResults', RouteName::ADMIN_SEARCH_RESULTS, RouteName::SEARCH_RESULTS],
			'quarantine' => ['showQuarantine', RouteName::ADMIN_QUARANTINE, RouteName::QUARANTINE],
			'quarantine per month' => ['showQuarantinePerMonth', RouteName::ADMIN_QUARANTINE_PER_MONTH, RouteName::QUARANTINE_PER_MONTH],
		];
	}

	// one item a page and two of them: the second page's link is on the viewer's own list
	#[DataProvider('pagedLists')]
	public function testPagedListUrls(string $page, RouteName $admin, RouteName $user): void {
		Config::set('items_per_page', 1);
		Config::set('q_items_per_page', 1);
		$this->answers = array_merge([
			['count(*) as aggregate', [['aggregate' => 2]]],
			['select `id` from `mail_logs`', [['id' => 41], ['id' => 40]]],
			['as day, COUNT(*) as cnt', [['day' => '2026-11-20', 'cnt' => 3], ['day' => '2026-11-19', 'cnt' => 1]]],
			['AS month, COUNT(*) as cnt', [['month' => '2026-11', 'cnt' => 3], ['month' => '2026-10', 'cnt' => 1]]],
			['created_day AS day, COUNT(*) AS total', [['day' => '2026-11-20', 'total' => 9]]],
		], $this->answers);
		foreach ([[self::ADMIN, $admin], [self::USER, $user]] as [$session, $route]) {
			$mails = $this->mails(self::request('/', $session, ['date' => '2026-11-20']));
			$response = match ($page) {
				'showAll' => $mails->showAll(),
				'showResults' => $mails->showResults(),
				'showQuarantine' => $mails->showQuarantine(),
				default => $mails->showQuarantinePerMonth(),
			};
			$this->assertStringContainsString('href="' . $this->url($route) . '?page=2', $this->html($response), $route->value);
		}
	}

	public function testSearchPage(): void {
		$request = self::request($this->url(RouteName::ADMIN_SEARCH), self::withFilters(self::ADMIN, [['filter' => 'Action', 'choice' => 'is equal to', 'value' => 'reject']]));
		$this->framed($request);
		$html = $this->html($this->mails($request)->search());
		$this->assertFramed($html, 'Search');
		$this->assertStringContainsString('name="search_form[value]"', $html);
		$this->assertStringContainsString('reject', $html);
	}

	// the statistics' queries answered: 3 mails, the oldest from 2026-11-01
	private function stats(): void {
		$this->answers = array_merge([
			['COUNT(*) AS cnt, MIN(id) AS first_id', [['cnt' => 3, 'first_id' => 40, 'last_id' => 42]]],
			['select `created_at`, `id` from `mail_logs`', [['created_at' => '2026-11-01 10:00:00', 'id' => 40], ['created_at' => '2026-11-20 10:00:00', 'id' => 42]]],
			['action, COUNT(*) as cnt', [['action' => 'reject', 'cnt' => 3]]],
		], $this->answers);
	}

	/** @return array<string, array{array<string, mixed>, array<string, bool>, bool, bool}> session, config, statistics shown, chart shown */
	public static function searchStats(): array {
		$filtered = self::withFilters(self::ADMIN, [['filter' => 'Action', 'choice' => 'is equal to', 'value' => 'reject']]);
		return [
			'an admin' => [self::ADMIN, [], true, true],
			'statistics off' => [self::ADMIN, ['show_mail_stats' => false], false, false],
			'unfiltered statistics off' => [self::ADMIN, ['show_unfiltered_mail_stats' => false], false, false],
			'unfiltered statistics off, a filter set' => [$filtered, ['show_unfiltered_mail_stats' => false], true, true],
			'charts off' => [self::ADMIN, ['show_charts' => false], true, false],
			'a user' => [self::USER, [], true, false],
			'a user, user charts on' => [self::USER, ['show_user_charts' => true], true, true],
			'a user, user statistics off' => [self::USER, ['show_user_mail_stats' => false], false, false],
			'a user, unfiltered user statistics off' => [self::USER, ['show_unfiltered_user_mail_stats' => false], false, false],
		];
	}

	/**
	 * @param array<string, mixed> $session
	 * @param array<string, bool> $config
	 */
	#[DataProvider('searchStats')]
	public function testSearchStats(array $session, array $config, bool $shown, bool $chart): void {
		foreach ($config as $key => $value) {
			Config::set($key, $value);
		}
		$this->stats();
		$html = $this->html($this->mails(self::request('/search', $session))->search());
		$this->assertSame($shown, str_contains($html, 'Oldest Record:</span> 2026-11-01 10:00:00'));
		$this->assertSame($shown, $this->selected('COUNT(*) AS cnt, MIN(id) AS first_id') !== null);
		$this->assertSame($chart, str_contains($html, '<canvas id="chart-'));
	}

	// none counted: no chart
	public function testSearchStatsNoneNoChart(): void {
		$this->answers = array_merge([['COUNT(*) AS cnt, MIN(id) AS first_id', [['cnt' => 0, 'first_id' => null, 'last_id' => null]]]], $this->answers);
		$this->assertStringNotContainsString('<canvas id="chart-', $this->html($this->mails(self::request('/admin/search', self::ADMIN))->search()));
	}

	// an admin's report links: the local symbols and the viruses once migrated; the DNS cache
	// count with Redis (a stub: the config expires in 300s, no name cached)
	public function testSearchPageLinks(): void {
		$this->setEnv('REDIS_ENABLE', 'true');
		$redis = $this->createStub(Redis::class);
		$redis->method('ttl')->willReturn(300);
		$redis->method('scan')->willReturn(false);
		$cache = new RedisCache($this->log);
		(new ReflectionProperty(RedisCache::class, 'client'))->setValue($cache, $redis);
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, $this->db->capsule, $this->status, $cache, true));
		$this->stats();
		$html = $this->html($this->mails(self::request('/admin/search', self::ADMIN))->search());
		foreach (['Results and Reports', 'Top Local and Mapped Symbols', 'Top Viruses</a>', 'Flush DNS</a> (0 cached)'] as $shown) {
			$this->assertStringContainsString($shown, $html);
		}
	}

	public function testSearchPageLinksBeforeMigrations(): void {
		$this->status->setMigrationState(Migrations::MAP_HITS, Migrations::STATUS_RUNNING);
		$this->status->setMigrationState(Migrations::MAIL_LOG_VIRUSES, Migrations::STATUS_RUNNING);
		$this->stats();
		$html = $this->html($this->mails(self::request('/admin/search', self::ADMIN))->search());
		$this->assertStringNotContainsString('Top Local and Mapped Symbols', $html);
		$this->assertStringNotContainsString('Top Viruses</a>', $html);
	}

	public function testSearchPageReportsOff(): void {
		Config::set('show_mail_reports', false);
		$this->stats();
		$this->assertStringNotContainsString('Results and Reports', $this->html($this->mails(self::request('/admin/search', self::ADMIN))->search()));
	}

	// a failed statistics query: said, unlike statistics that are off
	public function testSearchStatsError(): void {
		$this->answers = array_merge([['COUNT(*) AS cnt, MIN(id) AS first_id', function (): array {
			throw new RuntimeException('gone away');
		}]], $this->answers);
		$request = self::request('/admin/search', self::ADMIN);
		$this->assertStringContainsString('Statistics unavailable - query error.', $this->html($this->mails($request)->search()));
	}

	public function testSearchStatsTimeout(): void {
		$timeout = new \PDOException('Query execution was interrupted (max_statement_time exceeded)');
		$timeout->errorInfo = ['70100', 1969, 'Query execution was interrupted (max_statement_time exceeded)'];
		$this->answers = array_merge([['COUNT(*) AS cnt, MIN(id) AS first_id', function () use ($timeout): array {
			throw new \Illuminate\Database\QueryException('default', 'select', [], $timeout);
		}]], $this->answers);
		$request = self::request('/admin/search', self::ADMIN);
		$html = $this->html($this->mails($request)->search());
		$this->assertStringContainsString('Statistics took too long and were stopped. Narrow the search with more filters, for example a date.', $html);
		$this->assertStringNotContainsString('<canvas id="chart-', $html);
	}

	public function testSearchAddsFilter(): void {
		$request = self::request('/admin/search', self::ADMIN);
		$request->setMethod('POST');
		$request->request->replace(['search_form' => ['filter' => 'Action', 'choice' => 'is equal to', 'value' => 'reject', '_token' => self::csrf($request, 'search_form')]]);
		$this->assertRedirect($this->url(RouteName::ADMIN_SEARCH), $this->mails($request)->search());
		$this->assertSame('[{"filter":"Action","choice":"is equal to","value":"reject"}]', $request->getSession()->get('filters'));
	}

	/** @return array<string, array{string, string, string}> action, choice, value */
	public static function dateFilters(): array {
		return [
			'today' => ['search_filter_add_day', 'is equal to', 'today'],
			'7 days' => ['search_filter_add_week', 'is greater than or equal', '-6 days'],
			'31 days' => ['search_filter_add_month', 'is greater than or equal', '-30 days'],
		];
	}

	#[DataProvider('dateFilters')]
	public function testDateFilter(string $action, string $choice, string $since): void {
		$request = self::request('/search', self::withFilters(self::USER, [['filter' => 'Action', 'choice' => 'is equal to', 'value' => 'reject']]));
		$mails = $this->mails($request);
		$response = match ($action) {
			'search_filter_add_day' => $mails->search_filter_add_day(),
			'search_filter_add_week' => $mails->search_filter_add_week(),
			default => $mails->search_filter_add_month(),
		};
		$this->assertRedirect($this->url(RouteName::SEARCH), $response);
		$this->assertSame(
			[['filter' => 'Action', 'choice' => 'is equal to', 'value' => 'reject'], ['filter' => 'Date', 'choice' => $choice, 'value' => date('Y-m-d', (int) strtotime($since))]],
			json_decode(self::sessionText($request, 'filters'), true)
		);
	}

	private static function sessionText(Request $request, string $key): string {
		/** @var mixed $value */
		$value = $request->getSession()->get($key);
		self::assertIsString($value);
		return $value;
	}

	public function testDeleteFilter(): void {
		$filters = [['filter' => 'Action', 'choice' => 'is equal to', 'value' => 'reject'], ['filter' => 'Score', 'choice' => 'is greater than', 'value' => '5']];
		$request = self::request('/search', self::withFilters(self::USER, $filters));
		$this->assertRedirect($this->url(RouteName::SEARCH), $this->mails($request)->search_filter_del(0));
		$this->assertSame('[{"filter":"Score","choice":"is greater than","value":"5"}]', $request->getSession()->get('filters'));
		// kept to edit and add again: the search form shows it
		$html = $this->html($this->mails($request)->search());
		$this->assertStringContainsString('value="reject"', $html);
	}

	public function testDeleteUnknownFilter(): void {
		$request = self::request('/search', self::withFilters(self::USER, [['filter' => 'Action', 'choice' => 'is equal to', 'value' => 'reject']]));
		$this->mails($request)->search_filter_del(5);
		$this->assertSame('[{"filter":"Action","choice":"is equal to","value":"reject"}]', $request->getSession()->get('filters'));
	}

	public function testDeleteAllFilters(): void {
		$request = self::request('/search', self::withFilters(self::USER, [['filter' => 'Action', 'choice' => 'is equal to', 'value' => 'reject']]));
		$this->mails($request)->search_filter_del();
		$this->assertSame('[]', $request->getSession()->get('filters'));
	}

	public function testReport(): void {
		$this->answers = array_merge([['as total', [['action' => 'reject', 'total' => 3, 'total_size' => 3000, 'total_stored' => 2]]]], $this->answers);
		$request = self::request('/admin/reports/action', self::withFilters(self::ADMIN, [['filter' => 'Date', 'choice' => 'is equal to', 'value' => '2026-11-20']]));
		$html = $this->html($this->mails($request)->showReports('action'));
		$this->assertStringContainsString('reject', $html);
	}

	/** @return array<string, array{array<string, mixed>, string}> */
	public static function badReports(): array {
		return [
			'not a report field' => [self::ADMIN, 'password'],
			'none' => [self::ADMIN, ''],
			'admin only, for a user' => [self::USER, 'server'],
		];
	}

	/** @param array<string, mixed> $session */
	#[DataProvider('badReports')]
	public function testBadReport(array $session, string $field): void {
		$request = self::request('/reports', $session);
		$this->assertRedirect($this->url($session === self::USER ? RouteName::SEARCH : RouteName::ADMIN_SEARCH), $this->mails($request)->showReports($field));
		$this->assertSame(['error' => ["Field '{$field}' does not exist"]], self::flashes($request));
	}

	public function testReportsOff(): void {
		Config::set('show_mail_reports', false);
		$request = self::request('/admin/reports/action', self::ADMIN);
		$this->assertRedirect($this->url(RouteName::ADMIN_DAY_LOGS), $this->mails($request)->showReports('action'));
		$this->assertSame(['warning' => ['Mail Reports are disabled']], self::flashes($request));
	}

	// a user needs a filter (config.php's default)
	public function testUserReportsUnfiltered(): void {
		$request = self::request('/reports/action', self::USER);
		$this->assertRedirect($this->url(RouteName::DAY_LOGS), $this->mails($request)->showReports('action'));
		$this->assertSame(['warning' => ['Mail Reports are disabled']], self::flashes($request));
	}

	/** @return array<string, array{array<string, mixed>, array<string, bool>}> */
	public static function reportsOff(): array {
		return [
			'a user, user reports off' => [self::withFilters(self::USER, [['filter' => 'Action', 'choice' => 'is equal to', 'value' => 'reject']]), ['show_user_mail_reports' => false]],
			'unfiltered reports off' => [self::ADMIN, ['show_unfiltered_mail_reports' => false]],
		];
	}

	/**
	 * @param array<string, mixed> $session
	 * @param array<string, bool> $config
	 */
	#[DataProvider('reportsOff')]
	public function testReportsOffFor(array $session, array $config): void {
		foreach ($config as $key => $value) {
			Config::set($key, $value);
		}
		$request = self::request('/reports/action', $session);
		$this->assertRedirect($this->url($session['is_admin'] === true ? RouteName::ADMIN_DAY_LOGS : RouteName::DAY_LOGS), $this->mails($request)->showReports('action'));
		$this->assertSame(['warning' => ['Mail Reports are disabled']], self::flashes($request));
	}

	/** @return array<string, array{array<string, mixed>, array<string, bool>}> */
	public static function reportsOn(): array {
		return [
			'an admin, user reports off' => [self::ADMIN, ['show_user_mail_reports' => false]],
			'unfiltered reports off, a filter set' => [self::withFilters(self::ADMIN, [['filter' => 'Action', 'choice' => 'is equal to', 'value' => 'reject']]), ['show_unfiltered_mail_reports' => false]],
		];
	}

	/**
	 * @param array<string, mixed> $session
	 * @param array<string, bool> $config
	 */
	#[DataProvider('reportsOn')]
	public function testReportsOnFor(array $session, array $config): void {
		foreach ($config as $key => $value) {
			Config::set($key, $value);
		}
		$this->answers = array_merge([['as total', [['action' => 'reject', 'total' => 3, 'total_size' => 3000, 'total_stored' => 2]]]], $this->answers);
		$this->html($this->mails(self::request('/admin/reports/action', $session))->showReports('action'));
	}

	public function testReportByDay(): void {
		Config::set('show_charts', true);
		$this->answers = array_merge([['created_day AS date', [['date' => '2026-11-20', 'total' => 3, 'total_size' => 3000, 'total_stored' => 2]]]], $this->answers);
		$html = $this->html($this->mails(self::request('/admin/reports/date/day', self::ADMIN))->showReports('date', 'day'));
		$this->assertStringContainsString('?date=2026-11-20', $html);
	}

	// the rows summed; a missing size is none; top 30 when top_reports is unset
	public function testReportTotals(): void {
		Config::set('top_reports', null);
		$this->answers = array_merge([['as total', [
			['action' => 'reject', 'total' => 3, 'total_size' => 300, 'total_stored' => 2],
			['action' => 'add header', 'total' => 4, 'total_size' => null, 'total_stored' => 1],
		]]], $this->answers);
		$html = $this->html($this->mails(self::request('/admin/reports/action', self::ADMIN))->showReports('action'));
		$this->assertMatchesRegularExpression("#<div class='cell day'>Total:</div>\\s*<div class='cell right cnt'>7</div>\\s*<div class='cell right cnt'>3</div>\\s*<div class='cell right cnt'>300B</div>#", $html);
		$this->assertStringContainsString('Top 30 ACTION', $html);
		$this->assertStringNotContainsString('/js/chart.umd.min.js', $html);
	}

	/** @return array<string, array{string, string, string, string}> mode, the row's key, the chart's link, its stored label */
	public static function dateCharts(): array {
		return [
			'by day' => ['day', '2026-11-20', '?date=2026-11-20', '"Stored'],
			'by month' => ['month', '2026-11', '?month=2026-11', '"Ever stored"'],
		];
	}

	// by day or by month: a chart, its bars linking the search of that day or month
	#[DataProvider('dateCharts')]
	public function testReportDateChart(string $mode, string $date, string $link, string $label): void {
		$this->answers = array_merge([['AS date, COUNT(*) AS total', [['date' => $date, 'total' => 3, 'total_size' => 3000, 'total_stored' => 2]]]], $this->answers);
		$html = $this->html($this->mails(self::request('/admin/reports/date/' . $mode, self::ADMIN))->showReports('date', $mode));
		$this->assertStringContainsString('/js/chart.umd.min.js', $html);
		$chart = self::chartData($html);
		$this->assertStringContainsString('"links":["\\/admin\\/results' . $link . '"]', $chart);
		$this->assertStringContainsString($label, $chart);
	}

	// the busiest days: a list, no chart
	public function testReportDateCountNoChart(): void {
		$this->answers = array_merge([['AS date, COUNT(*) AS total', [['date' => '2026-11-20', 'total' => 3, 'total_size' => 3000, 'total_stored' => 2]]]], $this->answers);
		$html = $this->html($this->mails(self::request('/admin/reports/date', self::ADMIN))->showReports('date'));
		$this->assertStringNotContainsString('/js/chart.umd.min.js', $html);
	}

	// the country is looked up for the hostname column only (a GeoIP database that is not there: logged)
	public function testReportCountryOnlyWithHosts(): void {
		Config::set('geoip_enable', true);
		Config::set('geoip_country_db', '/nonexistent/country.mmdb');
		$this->answers = array_merge([['as total', [['ip' => '8.8.8.8', 'total' => 3, 'total_size' => 3000, 'total_stored' => 2]]]], $this->answers);
		Config::set('dns_resolv', false);
		$this->html($this->mails(self::request('/admin/reports/ip', self::ADMIN))->showReports('ip'));
		$this->assertSame([], preg_grep('/GeoIP problem/', $this->log->lines));
	}

	// by address, with name lookups on: a hostname column
	public function testReportHosts(): void {
		Config::set('dns_resolv', true);
		$this->answers = array_merge([['as total', [['ip' => '127.0.0.1', 'total' => 3, 'total_size' => 3000, 'total_stored' => 2]]]], $this->answers);
		$html = $this->html($this->mails(self::request('/admin/reports/ip', self::ADMIN))->showReports('ip'));
		$this->assertStringContainsString('<div class="cell left host">Hostname</div>', $html);
		Config::set('dns_resolv', false);
		$html = $this->html($this->mails(self::request('/admin/reports/ip', self::ADMIN))->showReports('ip'));
		$this->assertStringNotContainsString('Hostname', $html);
	}

	// with the hostname column, each address's country is looked up (a GeoIP database that is
	// not there: logged); the name comes from the DNS cache
	public function testReportHostsCountry(): void {
		Config::set('dns_resolv', true);
		Config::set('geoip_enable', true);
		Config::set('geoip_country_db', '/nonexistent/country.mmdb');
		$this->cache->values[(Config::getString('dns_resolv_redis_key') ?? '') . ':ptr:8.8.8.8'] = 'dns.google';
		$this->answers = array_merge([['as total', [['ip' => '8.8.8.8', 'total' => 3, 'total_size' => 3000, 'total_stored' => 2]]]], $this->answers);
		$this->assertStringContainsString('dns.google', $this->html($this->mails(self::request('/admin/reports/ip', self::ADMIN))->showReports('ip')));
		$this->assertNotSame([], preg_grep('/GeoIP problem/', $this->log->lines));
	}

	// nothing in quarantine: no totals asked
	public function testQuarantineEmpty(): void {
		$this->answers = array_merge([['as day, COUNT(*) as cnt', []], ['AS month, COUNT(*) as cnt', []], ['count(*) as aggregate', [['aggregate' => 0]]]], $this->answers);
		$this->html($this->mails(self::request('/admin/quarantine', self::ADMIN))->showQuarantine());
		$this->html($this->mails(self::request('/admin/quarantine/month', self::ADMIN))->showQuarantinePerMonth());
		$this->assertNull($this->selected('created_day AS day, COUNT(*) AS total'));
	}

	// the months' totals read the whole history: off with the statistics
	public function testQuarantinePerMonthStatsOff(): void {
		Config::set('show_mail_stats', false);
		$this->answers = array_merge([['AS month, COUNT(*) as cnt', [['month' => '2026-11', 'cnt' => 3]]]], $this->answers);
		$this->html($this->mails(self::request('/admin/quarantine/month', self::ADMIN))->showQuarantinePerMonth());
		$this->assertNull($this->selected('created_day AS day, COUNT(*) AS total'));
	}

	public function testVirusMailsReportsOff(): void {
		Config::set('show_mail_reports', false);
		$request = self::request('/admin/reports/virus/mails', self::ADMIN, ['virus' => 'Eicar-Signature']);
		$this->assertRedirect($this->url(RouteName::ADMIN_VIRUS_REPORT), $this->mails($request)->showVirusMails());
		$this->assertSame([], $this->db->selects);
	}

	public function testResultsTimeout(): void {
		$timeout = new \PDOException('Query execution was interrupted (max_statement_time exceeded)');
		$timeout->errorInfo = ['70100', 1969, 'Query execution was interrupted (max_statement_time exceeded)'];
		$this->answers = array_merge([['select `id` from `mail_logs`', function () use ($timeout): array {
			throw new \Illuminate\Database\QueryException('default', 'select', [], $timeout);
		}]], $this->answers);
		$request = self::request('/admin/results', self::ADMIN, ['date' => '2026-11-20']);
		$this->assertRedirect($this->url(RouteName::ADMIN_SEARCH), $this->mails($request)->showResults());
		$this->assertSame(['error' => ['The search took too long and was stopped. Narrow it with more filters, for example a date.']], self::flashes($request));
	}

	private function symbolsReport(): void {
		array_splice($this->answers, 0, 0, [
			['SELECT CURDATE() - INTERVAL 6 DAY AS d', [['d' => '2026-11-21']]],
			['SELECT COUNT(*) AS n FROM', [['n' => 2]]],
			['AS name, ml.action, COUNT(*) AS mails', [['name' => 'BAYES_SPAM', 'action' => 'reject', 'mails' => 2, 'scored' => 2, 'score' => 10.0]]],
		]);
	}

	public function testSymbolsReport(): void {
		$this->symbolsReport();
		$html = $this->html($this->mails(self::request('/admin/reports/symbols', self::ADMIN, ['sort' => 'score', 'info' => '1']))->showSymbolsReport());
		$this->assertStringContainsString('2026-11-21', $html);
		$this->assertStringContainsString('BAYES_SPAM', $html);
		$this->assertStringContainsString('Rspamd symbols by average score', $html);
		$this->assertStringContainsString('Hide informational symbols', $html);
	}

	// by quantity, informational symbols hidden, the top 30 when top_reports is unset
	public function testSymbolsReportDefaults(): void {
		Config::set('top_reports', null);
		$this->symbolsReport();
		$html = $this->html($this->mails(self::request('/admin/reports/symbols', self::ADMIN))->showSymbolsReport());
		$this->assertStringContainsString('Top 30 Rspamd symbols by quantity', $html);
		$this->assertStringContainsString('Show informational symbols', $html);
	}

	public function testLocalSymbolsReport(): void {
		$this->symbolsReport();
		$html = $this->html($this->mails(self::request('/admin/reports/symbols', self::ADMIN, ['local' => '1']))->showSymbolsReport());
		$this->assertStringContainsString('local and mapped Rspamd symbols', $html);
	}

	public function testLocalSymbolsNeedMigration(): void {
		$this->status->setMigrationState(\App\Inventory\Migrations::MAP_HITS, \App\Inventory\Migrations::STATUS_RUNNING);
		$request = self::request('/admin/reports/symbols', self::ADMIN, ['local' => '1']);
		$this->assertRedirect($this->url(RouteName::ADMIN_SEARCH), $this->mails($request)->showSymbolsReport());
		$this->assertSame(['error' => ['Map hits need the map hits migration (db:migrate_map_hits)']], self::flashes($request));
	}

	public function testSymbolsReportError(): void {
		$request = self::request('/admin/reports/symbols', self::ADMIN);
		$this->assertRedirect($this->url(RouteName::ADMIN_SEARCH), $this->mails($request)->showSymbolsReport());
		$this->assertSame(['error' => ['Symbols report query error. Check logs.']], self::flashes($request));
		$this->assertMatchesRegularExpression('/^error \[MailLogController_showSymbolsReport\] query error: .+/', $this->log->lines[0] ?? '');
	}

	/** @return array<string, array{string}> */
	public static function reportPages(): array {
		return ['symbols' => ['showSymbolsReport'], 'viruses' => ['showVirusReport']];
	}

	#[DataProvider('reportPages')]
	public function testReportPageOff(string $action): void {
		Config::set('show_mail_reports', false);
		$request = self::request('/admin/reports', self::ADMIN);
		$mails = $this->mails($request);
		$this->assertRedirect($this->url(RouteName::ADMIN_DAY_LOGS), $action === 'showSymbolsReport' ? $mails->showSymbolsReport() : $mails->showVirusReport());
		$this->assertSame(['warning' => ['Mail Reports are disabled']], self::flashes($request));
	}

	public function testVirusReport(): void {
		$this->answers = array_merge([['AS fail, IF(v.name', [['fail' => 0, 'virus' => 'Eicar-Signature', 'engines' => 'CLAM_VIRUS', 'today' => 1, 'yesterday' => 0, 'week' => 2, 'month' => 3]]]], $this->answers);
		$html = $this->html($this->mails(self::request('/admin/reports/virus', self::ADMIN))->showVirusReport());
		$this->assertStringContainsString('Eicar-Signature', $html);
	}

	public function testVirusReportTop30(): void {
		Config::set('top_reports', null);
		$this->answers = array_merge([['AS fail, IF(v.name', []]], $this->answers);
		$this->assertStringContainsString('Top 30 viruses', $this->html($this->mails(self::request('/admin/reports/virus', self::ADMIN))->showVirusReport()));
	}

	public function testVirusReportNeedsMigration(): void {
		$this->status->setMigrationState(\App\Inventory\Migrations::MAIL_LOG_VIRUSES, \App\Inventory\Migrations::STATUS_RUNNING);
		$request = self::request('/admin/reports/virus', self::ADMIN);
		$this->assertRedirect($this->url(RouteName::ADMIN_SEARCH), $this->mails($request)->showVirusReport());
		$this->assertSame(['error' => ['The virus report needs the mail log viruses migration (db:migrate_mail_log_viruses)']], self::flashes($request));
	}

	public function testVirusReportError(): void {
		$this->answers = array_merge([['AS fail, IF(v.name', function (): array {
			throw new RuntimeException('gone away');
		}]], $this->answers);
		$request = self::request('/admin/reports/virus', self::ADMIN);
		$this->assertRedirect($this->url(RouteName::ADMIN_SEARCH), $this->mails($request)->showVirusReport());
		$this->assertSame(['error [MailLogController_showVirusReport] query error: gone away'], $this->log->lines);
		$this->assertSame(['error' => ['Virus report query error. Check logs.']], self::flashes($request));
	}

	public function testVirusMails(): void {
		$this->answers = array_merge([['from `mail_log_viruses`', [['mail_log_id' => 41]]]], $this->answers);
		$html = $this->html($this->mails(self::request('/admin/reports/virus/mails', self::ADMIN, ['virus' => 'Eicar', 'window' => 'week', 'fail' => '1']))->showVirusMails());
		$this->assertStringContainsString('Scan failure', $html);
		$this->assertStringContainsString('Invoice', $html);
		$this->assertStringContainsString('Date in "Last 7 days"', $html);
	}

	// the search's own filters are listed with the virus's
	public function testVirusMailsWithSearchFilters(): void {
		$this->answers = array_merge([['from `mail_log_viruses`', [['mail_log_id' => 41]]]], $this->answers);
		$session = self::withFilters(self::ADMIN, [['filter' => 'Action', 'choice' => 'is equal to', 'value' => 'reject']]);
		$html = $this->html($this->mails(self::request('/admin/reports/virus/mails', $session, ['virus' => 'Eicar']))->showVirusMails());
		$this->assertStringContainsString('Action is equal to "reject"', $html);
	}

	/** @return array<string, array{array<string, string>, string}> */
	public static function virusMailPages(): array {
		return [
			'a virus' => [['virus' => 'Eicar', 'window' => 'week'], '?page=2&amp;virus=Eicar&amp;window=week"'],
			'a scan failure' => [['virus' => 'Eicar', 'window' => 'week', 'fail' => '1'], '?page=2&amp;virus=Eicar&amp;window=week&amp;fail=1"'],
		];
	}

	// the pages keep the virus, its window and a scan failure
	/** @param array<string, string> $query */
	#[DataProvider('virusMailPages')]
	public function testVirusMailsPages(array $query, string $link): void {
		Config::set('items_per_page', 1);
		$this->answers = array_merge([['from `mail_log_viruses`', [['mail_log_id' => 41], ['mail_log_id' => 40]]]], $this->answers);
		$this->assertStringContainsString($link, $this->html($this->mails(self::request('/admin/reports/virus/mails', self::ADMIN, $query))->showVirusMails()));
	}

	// a name of 255 characters is taken, multibyte ones too
	public function testVirusMailsLongestName(): void {
		$this->answers = array_merge([['from `mail_log_viruses`', [['mail_log_id' => 41]]]], $this->answers);
		foreach ([str_repeat('v', 255), str_repeat('é', 255)] as $virus) {
			$this->html($this->mails(self::request('/admin/reports/virus/mails', self::ADMIN, ['virus' => $virus]))->showVirusMails());
		}
		$this->assertCount(2, array_filter($this->db->selects, fn (array $q): bool => str_contains($q[0], 'from `mail_log_viruses`')));
	}

	/** @return array<string, array{array<string, string>}> */
	public static function badVirusMails(): array {
		return [
			'no virus' => [['window' => 'week']],
			'a window not in the report' => [['virus' => 'Eicar', 'window' => 'year']],
			'too long' => [['virus' => str_repeat('v', 256)]],
		];
	}

	/** @param array<string, string> $query */
	#[DataProvider('badVirusMails')]
	public function testBadVirusMails(array $query): void {
		$response = $this->mails(self::request('/admin/reports/virus/mails', self::ADMIN, $query))->showVirusMails();
		$this->assertRedirect($this->url(RouteName::ADMIN_VIRUS_REPORT), $response);
		$this->assertSame([], $this->db->selects);
	}

	public function testVirusMailsError(): void {
		$this->answers = array_merge([['from `mail_log_viruses`', function (): array {
			throw new RuntimeException('gone away');
		}]], $this->answers);
		$request = self::request('/admin/reports/virus/mails', self::ADMIN, ['virus' => 'Eicar']);
		$this->assertRedirect($this->url(RouteName::ADMIN_VIRUS_REPORT), $this->mails($request)->showVirusMails());
		$this->assertSame(['error' => ['Virus report query error. Check logs.']], self::flashes($request));
		$this->assertSame(['error [MailLogController_showVirusMails] query error: gone away'], $this->log->lines);
	}

	/** @return array<string, array{\Closure(MailLogController): Response, array<string, string>}> */
	public static function pages(): array {
		return [
			'all' => [fn (MailLogController $c): Response => $c->showAll(), []],
			'results' => [fn (MailLogController $c): Response => $c->showResults(), []],
			'a report' => [fn (MailLogController $c): Response => $c->showReports('action'), []],
			'symbols' => [fn (MailLogController $c): Response => $c->showSymbolsReport(), []],
			'viruses' => [fn (MailLogController $c): Response => $c->showVirusReport(), []],
			'virus mails' => [fn (MailLogController $c): Response => $c->showVirusMails(), ['virus' => 'Eicar']],
			'a day' => [fn (MailLogController $c): Response => $c->showDay('2026-11-20'), []],
			'a quarantine day' => [fn (MailLogController $c): Response => $c->showQuarantineDay('2026-11-20'), []],
			'a quarantine month' => [fn (MailLogController $c): Response => $c->showQuarantineMonth('2026-11'), []],
			'the quarantine' => [fn (MailLogController $c): Response => $c->showQuarantine(), []],
			'the quarantine by month' => [fn (MailLogController $c): Response => $c->showQuarantinePerMonth(), []],
			'a detail' => [fn (MailLogController $c): Response => $c->detail('id', 41), []],
			'the search' => [fn (MailLogController $c): Response => $c->search(), []],
		];
	}

	/**
	 * @param \Closure(MailLogController): Response $page
	 * @param array<string, string> $query
	 */
	#[DataProvider('pages')]
	public function testQidBox(\Closure $page, array $query): void {
		$this->assertToQid($page($this->mails(self::qidPosted(self::ADMIN, $query))));
		$this->assertSame([], $this->db->selects);
	}

	/**
	 * every page's frame; the request's path is the search page's, so its menu entry is marked
	 * @param \Closure(MailLogController): Response $page
	 * @param array<string, string> $query
	 */
	#[DataProvider('pages')]
	public function testFramedPages(\Closure $page, array $query): void {
		$this->answers = array_merge([
			['AS fail, IF(v.name', [['fail' => 0, 'virus' => 'Eicar-Signature', 'engines' => 'CLAM_VIRUS', 'today' => 1, 'yesterday' => 0, 'week' => 2, 'month' => 3]]],
			['from `mail_log_viruses`', [['mail_log_id' => 41]]],
			['SELECT CURDATE() - INTERVAL 6 DAY AS d', [['d' => '2026-11-21']]],
			['SELECT COUNT(*) AS n FROM', [['n' => 2]]],
			['AS name, ml.action, COUNT(*) AS mails', [['name' => 'BAYES_SPAM', 'action' => 'reject', 'mails' => 2, 'scored' => 2, 'score' => 10.0]]],
			['count(*) as aggregate', [['aggregate' => 1]]],
			['as day, COUNT(*) as cnt', [['day' => '2026-11-20', 'cnt' => 3]]],
			['AS month, COUNT(*) as cnt', [['month' => '2026-11', 'cnt' => 3]]],
			['created_day AS day, COUNT(*) AS total', [['day' => '2026-11-20', 'total' => 9]]],
			['as total', [['action' => 'reject', 'total' => 3, 'total_size' => 3000, 'total_stored' => 2]]],
		], $this->answers);
		$request = self::request($this->url(RouteName::ADMIN_SEARCH), self::withFilters(self::ADMIN, [['filter' => 'Action', 'choice' => 'is equal to', 'value' => 'reject']]), $query);
		$this->framed($request);
		$this->assertFramed($this->html($page($this->mails($request))), 'Search');
	}

	public function testDetail(): void {
		$request = self::request('/admin/detail/id/41', self::ADMIN);
		$html = $this->html($this->mails($request)->detail('id', 41));
		$this->assertStringContainsString('Q41', $html);
		$this->assertStringContainsString('Invoice', $html);
		$this->assertStringContainsString('name="mail_release_form[email]"', $html);
	}

	/** @param array<string, mixed> $parameters */
	private function addUrl(RouteName $route, array $parameters): string {
		return htmlspecialchars($this->url($route, $parameters));
	}

	// "add to map" links prefilled from the mail: the sender, the MIME sender's address
	// lowercased (multibyte too), the recipients; back to the mail after
	public function testDetailAddToMapLinks(): void {
		$this->mail['mime_from'] = 'Ñame <ÑAME@Example.NET>';
		$html = $this->detailHtml();
		$this->assertStringContainsString($this->addUrl(RouteName::ADMIN_MAP_ADD_ENTRY, ['map' => 'mail_from_whitelist', 'return-mail' => 41, 'mail_from' => 'a@example.net']), $html);
		$this->assertStringContainsString($this->addUrl(RouteName::ADMIN_MAP_ADD_ENTRY, ['map' => 'mime_from_rcpt_to_whitelist', 'return-mail' => 41, 'mime_from' => 'ñame@example.net', 'rcpt_to' => 'alice@example.org']), $html);
	}

	public function testDetailAddToMapLinksForUser(): void {
		$html = $this->detailHtml(self::USER);
		$this->assertStringContainsString($this->addUrl(RouteName::MAP_ADD_ENTRY, ['map' => 'mail_from_rcpt_to_whitelist', 'return-mail' => 41, 'mail_from' => 'a@example.net', 'rcpt_to' => 'alice@example.org']), $html);
	}

	/** @return array<string, array{string|null}> */
	public static function noSenders(): array {
		return ['none' => [null], 'empty' => [''], 'zero' => ['0']];
	}

	// no sender: nothing to prefill
	#[DataProvider('noSenders')]
	public function testDetailAddToMapNoSender(?string $from): void {
		$this->mail['mail_from'] = $from;
		$this->assertStringContainsString($this->addUrl(RouteName::ADMIN_MAP_ADD_ENTRY, ['map' => 'mail_from_rcpt_to_whitelist', 'return-mail' => 41, 'rcpt_to' => 'alice@example.org']) . "'", $this->detailHtml());
	}

	// the release form: the mail's recipients, posting to the release; none once the mail is gone
	public function testDetailReleaseForm(): void {
		$html = $this->detailHtml();
		$this->assertMatchesRegularExpression('#<form[^>]*action="' . preg_quote($this->url(RouteName::ADMIN_RELEASEMAIL, ['id' => 41]), '#') . '"#', $html);
		$this->assertMatchesRegularExpression('#name="mail_release_form\[email\]"[^>]*value="alice@example.org"#', $html);
		$this->mail['mail_stored'] = 0;
		$this->assertStringNotContainsString('name="mail_release_form[email]"', $this->detailHtml());
	}

	// a type opens in the browser when it is one of the safe ones, read without parameters and case
	public function testInlineSafeTypes(): void {
		$safe = new \ReflectionMethod(MailLogController::class, 'isInlineSafeType');
		$controller = $this->mails(self::request('/', self::ADMIN));
		foreach (['Image/PNG ; name="a.png"' => true, 'text/plain' => true, 'application/pdf' => false] as $type => $inline) {
			$this->assertSame($inline, $safe->invoke($controller, $type), $type);
		}
	}

	// a user sees the path under QUARANTINE_DIR, never one outside it
	public function testDetailPathForUser(): void {
		$html = $this->html($this->mails(self::request('/detail/id/41', self::USER))->detail('id', 41));
		$this->assertStringContainsString('Q41/Q41', $html);
		$this->assertStringNotContainsString($this->dir, $html);
		$this->mail['mail_location'] = '/elsewhere/Q41';
		$html = $this->html($this->mails(self::request('/detail/id/41', self::USER))->detail('id', 41));
		$this->assertStringContainsString('Quarantined mail', $html);
		$this->assertStringNotContainsString('/elsewhere', $html);
	}

	// no local mailer: the release form is off, and that is logged
	public function testDetailWithoutMailer(): void {
		$this->setEnv('MAILER_DSN', null);
		$this->html($this->mails(self::request('/admin/detail/id/41', self::ADMIN))->detail('id', 41));
		$this->assertContains('error MAILER_DSN or MAILER_FROM is not set in .env', $this->log->lines);
	}

	// a learned mail: the other learned mails sharing its digest, with their list
	public function testDetailFuzzyShared(): void {
		$a = str_repeat('a1', 64);
		Config::set('fuzzy_learn_enable', true);
		Config::set('fuzzy_learn_flags', [1 => ['label' => 'Spam'], 2 => ['label' => 'Phish', 'weight' => 20]]);
		Config::set('API_SERVERS', ['mx1' => ['fuzzy_url' => 'http://127.0.0.1:11334']]);
		$this->answers = array_merge([
			['from `mail_log_fuzzy` where `mail_log_id` = ?', [['id' => 3, 'mail_log_id' => 41, 'qid' => 'Q41', 'api_server' => 'mx1', 'flag' => 1, 'weight' => 10,
				'hashes' => '["' . $a . '"]', 'hits' => 0, 'learned_by' => 'root', 'created_at' => '2026-11-01 10:00:00', 'updated_at' => '2026-11-01 10:00:00', 'last_hit_at' => null]]],
			['SELECT f.id, f.qid, f.mail_log_id, f.flag, j.h', [['id' => 3, 'qid' => 'Q41', 'mail_log_id' => 41, 'flag' => 1, 'h' => $a], ['id' => 5, 'qid' => 'Q5', 'mail_log_id' => 8, 'flag' => 2, 'h' => $a]]],
		], $this->answers);
		$html = $this->html($this->mails(self::request('/admin/detail/id/41', self::ADMIN))->detail('id', 41));
		$this->assertStringContainsString('Shared with: <a href="' . $this->url(RouteName::ADMIN_DETAIL, ['type' => 'id', 'value' => 8]) . '">Q5</a> (Phish)', $html);
	}

	/** @param array<string, mixed>|null $fuzzy the mail's mail_log_fuzzy row, null for none */
	private function fuzzyOn(?array $fuzzy): void {
		Config::set('fuzzy_learn_enable', true);
		Config::set('fuzzy_learn_flags', [1 => ['label' => 'Spam'], 2 => ['label' => 'Phish', 'weight' => 20]]);
		Config::set('API_SERVERS', ['mx1' => ['fuzzy_url' => 'http://127.0.0.1:11334']]);
		$row = $fuzzy === null ? [] : [$fuzzy + ['id' => 3, 'mail_log_id' => 41, 'qid' => 'Q41', 'api_server' => 'mx1', 'flag' => 1, 'weight' => 10,
			'hashes' => '[]', 'hits' => 4, 'learned_by' => 'root', 'created_at' => '2026-11-01 10:00:00', 'updated_at' => '2026-11-01 10:00:00', 'last_hit_at' => '2026-11-20 10:00:00']];
		$this->answers = array_merge([['from `mail_log_fuzzy` where `mail_log_id` = ?', $row]], $this->answers);
	}

	/** @param array<string, mixed> $session */
	private function detailHtml(array $session = self::ADMIN): string {
		return $this->html($this->mails(self::request('/detail/id/41', $session))->detail('id', 41));
	}

	// not learned, stored on a fuzzy server: learnable as each list
	public function testDetailFuzzyLearnable(): void {
		$this->fuzzyOn(null);
		$html = $this->detailHtml();
		$this->assertStringContainsString('Learn as Spam</button>', $html);
		$this->assertStringContainsString('Learn as Phish</button>', $html);
	}

	// not stored, or on a server without fuzzy: not learnable
	public function testDetailFuzzyNotLearnable(): void {
		$this->fuzzyOn(null);
		$this->mail['mail_stored'] = 0;
		$this->assertStringNotContainsString('Learn as', $this->detailHtml());
		$this->mail['mail_stored'] = 1;
		$this->mail['server'] = 'mx2';
		$this->assertStringNotContainsString('Learn as', $this->detailHtml());
	}

	// a user never sees the fuzzy row
	public function testDetailFuzzyNotForUsers(): void {
		$this->fuzzyOn(null);
		$this->assertStringNotContainsString("<div class='info'>Fuzzy:</div>", $this->detailHtml(self::USER));
	}

	// learned: weight can be added below 65535; its hits link the mails
	public function testDetailFuzzyLearned(): void {
		$this->fuzzyOn([]);
		$html = $this->detailHtml();
		$this->assertStringContainsString('Add weight</button>', $html);
		$this->assertStringContainsString('Hits: <a href="' . $this->url(RouteName::ADMIN_FUZZY_HITS, ['id' => 3]) . '">4</a>', $html);
	}

	public function testDetailFuzzyAtTheMostWeight(): void {
		$this->fuzzyOn(['weight' => 65535]);
		$this->assertStringNotContainsString('Add weight</button>', $this->detailHtml());
	}

	// the hits need their migration, and the link the hit mails' one
	public function testDetailFuzzyHitsNeedMigrations(): void {
		$this->fuzzyOn([]);
		$this->status->setMigrationState(Migrations::MAIL_LOG_FUZZY_HITS_MAILS, Migrations::STATUS_RUNNING);
		$this->assertStringContainsString('Hits: 4, last', $this->detailHtml());
		$this->status->setMigrationState(Migrations::MAIL_LOG_FUZZY_HITS, Migrations::STATUS_RUNNING);
		$this->assertStringNotContainsString('Hits:', $this->detailHtml());
	}

	private function notified(string $html): string {
		return preg_match("#<div class='info'>Notified:</div>\\s*<div class='detail'>\\s*(.*?)</div>#s", $html, $m) === 1 ? trim($m[1]) : '';
	}

	private function adminNotified(string $html): string {
		return preg_match("#<div class='info'>Admin notified:</div>\\s*<div class='detail'>\\s*(.*?)</div>#s", $html, $m) === 1 ? trim($m[1]) : '';
	}

	/** @return array<string, array{int, int|null, string}> notification_pending, notified, the Notified row */
	public static function recipientsOff(): array {
		return [
			'pending' => [1, 0, 'No (Disabled for all recipients)'],
			'skipped' => [0, null, 'No (Disabled for all recipients)'],
			'pending, never tried' => [1, null, 'No (Disabled for all recipients)'],
		];
	}

	// every recipient with notifications off
	#[DataProvider('recipientsOff')]
	public function testDetailNotificationsOffForAll(int $pending, ?int $notified, string $shown): void {
		$this->mail['notification_pending'] = $pending;
		$this->mail['notified'] = $notified;
		$this->answers = array_merge([['from `users` where `email` = ?', [['id' => 7, 'disable_notifications' => 1]]]], $this->answers);
		$this->assertSame($shown, $this->notified($this->detailHtml()));
	}

	// today's mail: within notification_days whatever the date the suite runs
	public function testDetailPending(): void {
		$this->mail['notification_pending'] = 1;
		$this->mail['created_day'] = date('Y-m-d');
		$this->assertSame('Pending', $this->notified($this->detailHtml()));
	}

	public function testDetailBlacklisted(): void {
		$this->mail['notification_pending'] = 1;
		$this->answers = array_merge([['from `mail_log_data`', [['mail_log_id' => 41, 'symbols' => '{"RQWATCH_IP_BL":{"name":"RQWATCH_IP_BL","score":10}}', 'headers' => '']]]], $this->answers);
		$this->assertSame('No (Blacklisted)', $this->notified($this->detailHtml()));
	}

	/** @return array<string, array{int, int, string}> notification_days, the mail's age in days, the Notified row */
	public static function notificationAges(): array {
		return [
			'past notification_days' => [5, 6, 'No (Expired)'],
			'on its last day' => [5, 5, 'Pending'],
			'no notification_days' => [0, 60, 'Pending'],
		];
	}

	#[DataProvider('notificationAges')]
	public function testDetailNotificationExpiry(int $days, int $age, string $shown): void {
		Config::set('notification_days', $days);
		$this->mail['notification_pending'] = 1;
		$this->mail['created_day'] = (new \DateTimeImmutable("-{$age} days"))->format('Y-m-d');
		$this->assertSame($shown, $this->notified($this->detailHtml()));
	}

	/** @return array<string, array{string, bool|null, int, string}> admin_notification_rcpt, admin_notified, the mail's age in days, the row */
	public static function adminNotifications(): array {
		return [
			'pending' => ['root@example.org', false, 1, 'Pending'],
			'past the admin days' => ['root@example.org', false, 4, 'No (Expired)'],
			'on the last day' => ['root@example.org', false, 3, 'Pending'],
			'skipped' => ['root@example.org', null, 4, 'No'],
			'no admin recipient' => ['', false, 1, 'No'],
		];
	}

	#[DataProvider('adminNotifications')]
	public function testDetailAdminNotified(string $rcpt, ?bool $notified, int $age, string $shown): void {
		Config::set('admin_notification_rcpt', $rcpt);
		Config::set('admin_notification_days', null);
		$this->mail['admin_notified'] = $notified === null ? null : (int) $notified;
		$this->mail['admin_notify_date'] = null;
		$this->mail['created_day'] = (new \DateTimeImmutable("-{$age} days"))->format('Y-m-d');
		$this->assertSame($shown, $this->adminNotified($this->detailHtml()));
	}

	// a virus matching the virus skip map: not notified, said with the map's pattern
	public function testDetailVirusSkipped(): void {
		$this->mail['has_virus'] = 1;
		$this->mail['notification_pending'] = 1;
		$this->answers = array_merge([
			['from `mail_log_data`', [['mail_log_id' => 41, 'symbols' => json_encode([['name' => 'CLAM_VIRUS', 'score' => 5.0, 'group' => 'antivirus', 'options' => ['Eicar-Test']]]), 'headers' => '']]],
			['select `pattern` from `maps_custom`', [['pattern' => 'eicar-*']]],
		], $this->answers);
		$this->assertSame('No (Virus skip map: eicar-*)', $this->notified($this->detailHtml()));
	}

	// a mail with a virus: the virus skip map is looked at (none configured here)
	public function testDetailVirus(): void {
		$this->mail['has_virus'] = 1;
		$html = $this->html($this->mails(self::request('/admin/detail/id/41', self::ADMIN))->detail('id', 41));
		$this->assertStringNotContainsString('Virus skip map', $html);
	}

	// before the map hits migration: no symbol links, nothing asked
	public function testDetailSymbolLinksNeedMigration(): void {
		$this->status->setMigrationState(Migrations::MAP_HITS, Migrations::STATUS_PENDING);
		$this->html($this->mails(self::request('/admin/detail/id/41', self::ADMIN))->detail('id', 41));
		$this->assertNull($this->selected('mail_log_symbol_hits'));
	}

	private function symbolHits(): void {
		array_splice($this->answers, 0, 0, [
			['from `mail_log_symbol_hits` as `h` inner join `symbol_names`', [
				['name' => 'RQWATCH_IP_BLACKLIST', 'entry_model' => 1, 'entry_id' => 6],
				['name' => 'RQWATCH_SMTP_FROM_RCPT_TO_WHITELIST', 'entry_model' => 1, 'entry_id' => 5],
				['name' => 'LOCAL_RULE', 'entry_model' => 0, 'entry_id' => 0],
			]],
			['select `map_name`, `id` from `maps_combined`', [['id' => 6, 'map_name' => 'ip_blacklist'], ['id' => 5, 'map_name' => 'mail_from_rcpt_to_whitelist']]],
			['from `maps_combined` where `map_name` = ?', fn (array $b): array => ($b[1] ?? null) === 5 ? [['id' => 5, 'map_name' => 'mail_from_rcpt_to_whitelist', 'rcpt_to' => 'alice@example.org', 'user_id' => 7]] : []],
		]);
	}

	// the maps a recorded hit belongs to, and its entries, as the viewer may see them
	public function testDetailSymbolLinks(): void {
		$this->symbolHits();
		$this->mail['has_virus'] = 0;
		$this->answers = array_merge([['from `mail_log_data`', [['mail_log_id' => 41, 'symbols' => json_encode([['name' => 'RQWATCH_IP_BLACKLIST', 'score' => 5.0], ['name' => 'RQWATCH_SMTP_FROM_RCPT_TO_WHITELIST', 'score' => -5.0], ['name' => 'LOCAL_RULE', 'score' => 1.0]]), 'headers' => '']]]], $this->answers);
		$html = html_entity_decode($this->html($this->mails(self::request('/detail/id/41', self::USER))->detail('id', 41)));
		$this->assertStringContainsString('/map/mail_from_rcpt_to_whitelist?entry=5', $html);
		$this->assertStringNotContainsString('ip_blacklist', $html);
		$admin = html_entity_decode($this->html($this->mails(self::request('/admin/detail/id/41', self::ADMIN))->detail('id', 41)));
		$this->assertStringContainsString('/admin/map/ip_blacklist?entry=6', $admin);
		$this->assertStringContainsString('/admin/map/mail_from_rcpt_to_whitelist?entry=5', $admin);
		$this->assertStringContainsString('<a href="/admin/map/ip_blacklist">[IP Blacklist]</a>', $admin);
	}

	/*
	 One symbol credited to three entries: one in a map the user cannot see,
	 then two in a map of hers, only the second of them hers. Its map is linked once,
	 its own entry, not the other; the map hits reports' symbol name links the
	 map with no entry credited.
	*/
	public function testDetailSymbolLinksOfOneSymbol(): void {
		array_splice($this->answers, 0, 0, [
			['from `mail_log_symbol_hits` as `h` inner join `symbol_names`', [
				['name' => 'LOCAL_RULE', 'entry_model' => 1, 'entry_id' => 6],
				['name' => 'LOCAL_RULE', 'entry_model' => 1, 'entry_id' => 7],
				['name' => 'LOCAL_RULE', 'entry_model' => 1, 'entry_id' => 5],
				['name' => 'RQWATCH_SMTP_FROM_RCPT_TO_WHITELIST', 'entry_model' => 0, 'entry_id' => 0],
			]],
			['select `map_name`, `id` from `maps_combined`', [['id' => 6, 'map_name' => 'ip_blacklist'], ['id' => 5, 'map_name' => 'mail_from_rcpt_to_whitelist'], ['id' => 7, 'map_name' => 'mail_from_rcpt_to_whitelist']]],
			['from `maps_combined` where `map_name` = ?', fn (array $b): array => ($b[1] ?? null) === 5 ? [['id' => 5, 'map_name' => 'mail_from_rcpt_to_whitelist', 'rcpt_to' => 'alice@example.org', 'user_id' => 7]] : []],
		]);
		$this->answers = array_merge([['from `mail_log_data`', [['mail_log_id' => 41, 'symbols' => json_encode([['name' => 'LOCAL_RULE', 'score' => 1.0], ['name' => 'RQWATCH_SMTP_FROM_RCPT_TO_WHITELIST', 'score' => -5.0]]), 'headers' => '']]]], $this->answers);
		$html = html_entity_decode($this->html($this->mails(self::request('/detail/id/41', self::USER))->detail('id', 41)));
		$this->assertStringContainsString('/map/mail_from_rcpt_to_whitelist?entry=5">[entry]</a>', $html);
		$this->assertStringNotContainsString('?entry=7', $html);
		$this->assertStringNotContainsString('?entry=6', $html);
		// once for each symbol: LOCAL_RULE's entries' map, the whitelist symbol's own
		$this->assertSame(2, substr_count($html, '<a href="/user/map/mail_from_rcpt_to_whitelist">[Mail From/RCPT_TO Whitelist]</a>'));
	}

	// no hit recorded: nothing else is read
	public function testDetailNoSymbolHits(): void {
		array_splice($this->answers, 0, 0, [['from `mail_log_symbol_hits` as `h` inner join `symbol_names`', []]]);
		$this->html($this->mails(self::request('/admin/detail/id/41', self::ADMIN))->detail('id', 41));
		$this->assertNull($this->selected('`symbols` is not null'));
	}

	public function testDetailSymbolLinksError(): void {
		$this->answers = array_merge([['from `mail_log_symbol_hits`', function (): array {
			throw new RuntimeException('gone away');
		}]], $this->answers);
		$this->html($this->mails(self::request('/admin/detail/id/41', self::ADMIN))->detail('id', 41));
		$this->assertContains('error [MailLogController_symbolLinks] map hits query error: gone away', $this->log->lines);
	}

	public function testDetailNotFound(): void {
		$this->answers = [];
		$request = self::request('/admin/detail/id/42', self::ADMIN);
		$this->assertRedirect($this->url(RouteName::ADMIN_DAY_LOGS), $this->mails($request)->detail('id', 42));
		$this->assertArrayHasKey('error', self::flashes($request));
	}

	/**
	 * @param array<string, mixed> $fields
	 * @param array<string, mixed> $session
	 * @return array{Response, Request}
	 */
	private function release(array $fields, array $session = self::ADMIN): array {
		$request = self::request('/release/41', $session);
		$request->setMethod('POST');
		$request->request->replace(['mail_release_form' => $fields + ['_token' => self::csrf($request, 'mail_release_form')]]);
		return [$this->mails($request)->releaseMail(41), $request];
	}

	private function detailUrl(bool $admin = true): string {
		return $this->url($admin ? RouteName::ADMIN_DETAIL : RouteName::DETAIL, ['type' => 'id', 'value' => 41]);
	}

	/** @return list<string> */
	private function sentTo(): array {
		$this->assertCount(1, $this->sent);
		return array_values(array_map(fn (Address $a): string => $a->getAddress(), $this->sent[0]->getTo()));
	}

	public function testRelease(): void {
		[$response, $request] = $this->release(['email' => 'alice@example.org', 'release' => '1']);
		$this->assertRedirect($this->detailUrl(), $response);
		$this->assertSame(['success' => ['Mail Q41 released to alice@example.org']], self::flashes($request));
		$this->assertSame(['alice@example.org'], $this->sentTo());
		// once in each log
		$this->assertCount(2, array_keys($this->log->lines, "info Message Q41 released to 'alice@example.org' by 'root@example.org' from '192.0.2.7' via web (sent by mx1)", true));
	}

	// the original and an alternate; one given twice is sent once
	public function testReleaseToBoth(): void {
		[, $request] = $this->release(['email' => 'alice@example.org', 'release' => '1', 'release_alt' => '1', 'email_alt' => 'bob@example.org, alice@example.org']);
		$this->assertSame(['success' => ['Mail Q41 released to alice@example.org, bob@example.org']], self::flashes($request));
		$this->assertSame(['alice@example.org', 'bob@example.org'], $this->sentTo());
	}

	// a user releasing a mail to two recipients, one hers by an alias
	public function testUserReleaseByAlias(): void {
		// in place: a user's mail query holds the recipients in a subquery
		$this->answers = array_map(fn (array $a): array => $a[0] === 'from `mail_log_recipients`'
			? [$a[0], [['mail_log_id' => 41, 'recipient_email' => 'alias@example.org'], ['mail_log_id' => 41, 'recipient_email' => 'bob@example.org']]] : $a, $this->answers);
		[, $request] = $this->release(['release' => '1'], ['email' => 'carol@example.org', 'user_aliases' => ['Alias@Example.org']] + self::USER);
		$this->assertSame(['success' => ['Mail Q41 released to alias@example.org, bob@example.org']], self::flashes($request));
	}

	public function testReleaseToAlternate(): void {
		[$response, $request] = $this->release(['email' => 'alice@example.org', 'release_alt' => '1', 'email_alt' => 'Bob@Example.org']);
		$this->assertSame(['success' => ['Mail Q41 released to bob@example.org']], self::flashes($request));
		$this->assertSame(['bob@example.org'], $this->sentTo());
	}

	public function testUserRelease(): void {
		[$response, $request] = $this->release(['email' => 'alice@example.org', 'release' => '1'], self::USER);
		$this->assertRedirect($this->detailUrl(false), $response);
		$this->assertSame(['success' => ['Mail Q41 released to alice@example.org']], self::flashes($request));
	}

	public function testUserReleaseNotTheirs(): void {
		[$response, $request] = $this->release(['email' => 'carol@example.org', 'release' => '1'], ['email' => 'carol@example.org', 'user_aliases' => []] + self::USER);
		$this->assertSame(['error' => ['Wrong personal recipient address. Contact admin']], self::flashes($request));
		$this->assertSame([], $this->sent);
		$this->assertSame(['error Mail release failed', 'error Mail release of Q41 by carol@example.org failed. Check rqwatch logs for details'], $this->log->lines);
		$this->assertSame(['user' => 'alice', 'user_mail' => 'carol@example.org', 'qid' => 'Q41', 'db_id' => 41, 'rcpt_to' => 'alice@example.org', 'release_to' => 'alice@example.org', 'is_admin' => false], $this->log->contexts[0] ?? null);
	}

	/** @return array<string, array{array<string, string>, string}> */
	public static function badReleases(): array {
		return [
			'nothing ticked' => [['email' => 'alice@example.org'], 'No recipient checked'],
			'alternate ticked, none given' => [['email' => 'alice@example.org', 'release_alt' => '1'], 'Cannot release to empty alternate recipient'],
			'alternate given, not ticked' => [['release' => '1', 'email_alt' => 'bob@example.org'], 'Alternate recipient checkbox not ticked'],
			'alternate not an address' => [['release_alt' => '1', 'email_alt' => 'bob'], 'Invalid alternate recipient address'],
		];
	}

	/** @param array<string, string> $fields */
	#[DataProvider('badReleases')]
	public function testBadRelease(array $fields, string $error): void {
		[$response, $request] = $this->release($fields);
		$this->assertRedirect($this->detailUrl(), $response);
		$this->assertSame(['error' => [$error]], self::flashes($request));
		$this->assertSame([], $this->sent);
	}

	/** @return array<string, array{list<array<string, mixed>>, string}> */
	public static function badOriginals(): array {
		return [
			'no original address' => [[], 'Original recipient address missing from mail'],
			'an unknown original, ticked' => [[['mail_log_id' => 41, 'recipient_email' => 'unknown']], 'Cannot release to unknown original recipient. Use alternate recipient'],
		];
	}

	// the original recipient is the mail's (the form's field is disabled), not what is posted
	/** @param list<array<string, mixed>> $recipients */
	#[DataProvider('badOriginals')]
	public function testBadOriginal(array $recipients, string $error): void {
		$this->answers = array_merge([['from `mail_log_recipients`', $recipients]], $this->answers);
		[$response, $request] = $this->release(['email' => 'alice@example.org', 'release' => '1']);
		$this->assertRedirect($this->detailUrl(), $response);
		$this->assertSame(['error' => [$error]], self::flashes($request));
		$this->assertSame([], $this->sent);
	}

	public function testReleaseFormInvalid(): void {
		$request = self::request('/release/41', self::ADMIN);
		$request->setMethod('POST');
		$request->request->replace(['mail_release_form' => ['email' => 'alice@example.org', 'release' => '1', '_token' => 'forged']]);
		$this->assertRedirect($this->detailUrl(), $this->mails($request)->releaseMail(41));
		$this->assertSame(['error' => ['Invalid release mail data']], self::flashes($request));
		$this->assertSame([], $this->sent);
	}

	/** @var list<string> the API requests made, method, url and body */
	private array $apiRequests = [];

	// a mail stored on mx2, whose release API answers $reply (ApiClient's seam)
	private function onMx2(MockResponse $reply): void {
		$this->mail['server'] = 'mx2';
		$this->setEnv('MAIL_API_USER', 'rqwatch-api');
		$this->setEnv('MAIL_API_PASS', 'k9-unit-test-pass');
		Config::set('API_SERVERS', ['mx2' => ['url' => 'https://mx2.example.org']]);
		$client = new MockHttpClient(function (string $method, string $url, array $options) use ($reply): MockResponse {
			/** @var mixed $body */
			$body = $options['body'] ?? '';
			$this->apiRequests[] = "{$method} {$url} " . (is_string($body) ? urldecode($body) : '');
			return $reply;
		});
		(new ReflectionProperty(ApiClient::class, 'httpClient'))->setValue(null, fn (array $options): HttpClientInterface => $client);
	}

	// released by the mail's own node, as the releasing admin; no mailer needed here
	public function testReleaseOnAnotherNode(): void {
		$this->setEnv('MAILER_DSN', null);
		$this->onMx2(new MockResponse('Message Released'));
		[$response, $request] = $this->release(['email' => 'alice@example.org', 'release' => '1']);
		$this->assertRedirect($this->detailUrl(), $response);
		$this->assertSame(['success' => ['Mail Q41 released to alice@example.org']], self::flashes($request));
		$this->assertSame(['POST https://mx2.example.org/api/release_mail.php id=41&email[0]=alice@example.org&remote_user=root@example.org'], $this->apiRequests);

		$this->assertSame([], $this->sent);
		$this->assertContains("info Message Q41 released to 'alice@example.org' by 'root@example.org' from '192.0.2.7' via web (sent by mx2)", $this->log->lines);
	}

	// the addresses as a list: one given twice leaves no gap
	public function testReleaseOnAnotherNodeOnce(): void {
		$this->onMx2(new MockResponse('Message Released'));
		$this->release(['email' => 'alice@example.org', 'release' => '1', 'release_alt' => '1', 'email_alt' => 'alice@example.org, bob@example.org']);
		$this->assertSame(['POST https://mx2.example.org/api/release_mail.php id=41&email[0]=alice@example.org&email[1]=bob@example.org&remote_user=root@example.org'], $this->apiRequests);
	}

	public function testReleaseOnAnotherNodeFails(): void {
		$this->onMx2(new MockResponse('Message Release Failed', ['http_code' => 500]));
		[$response, $request] = $this->release(['email' => 'alice@example.org', 'release' => '1']);
		$this->assertRedirect($this->detailUrl(), $response);
		$this->assertSame(['error' => ['Message failed to be released']], self::flashes($request));
		$this->assertContains('error Message Q41 failed to be released', $this->log->lines);
	}

	public function testReleaseWithoutMailer(): void {
		$this->setEnv('MAILER_DSN', null);
		[$response, $request] = $this->release(['email' => 'alice@example.org', 'release' => '1']);
		$this->assertSame(['error' => ['Mail cannot be released. Contact administrator']], self::flashes($request));
		$this->assertSame(['error MAILER_DSN or MAILER_FROM is not set in .env'], $this->log->lines);
	}

	public function testReleaseNotFound(): void {
		$this->answers = [];
		[$response, $request] = $this->release(['email' => 'alice@example.org', 'release' => '1'], self::USER);
		$this->assertRedirect($this->url(RouteName::DAY_LOGS), $response);
		$this->assertArrayHasKey('error', self::flashes($request));
		$this->assertContains('warning alice@example.org tried to release mail with id: 41. Either mail does not exist or user does not have access to it.', $this->log->lines);
		$this->assertContains(['email' => 'alice@example.org', 'is_admin' => false], $this->log->contexts);
	}

	public function testShowMail(): void {
		$response = $this->mails(self::request('/admin/mail/41', self::ADMIN))->showMail(41);
		$html = $this->html($response);
		$this->assertStringContainsString('Please pay the held invoice', $html);
		$this->assertStringContainsString('inv.pdf', $html);
		$this->assertStringContainsString("style-src 'self' 'unsafe-inline'", (string) $response->headers->get('Content-Security-Policy'));
		// the admin's rows: the server and the fuzzy row
		$this->assertStringContainsString("<div class='info'>Server:</div>", $html);
		// the image opens in the browser
		$this->assertStringContainsString('Open in Browser', $html);
	}

	// its symbols, its virus and, for an admin, the fuzzy actions
	public function testShowMailSymbolsAndVirus(): void {
		$this->fuzzyOn(null);
		$this->mail['has_virus'] = 1;
		$this->answers = array_merge([['from `mail_log_data`', [['mail_log_id' => 41, 'symbols' => json_encode([['name' => 'CLAM_VIRUS', 'score' => 5.0, 'group' => 'antivirus', 'options' => ['Eicar-Test']]]), 'headers' => '']]]], $this->answers);
		$html = $this->html($this->mails(self::request('/admin/mail/41', self::ADMIN))->showMail(41));
		$this->assertStringContainsString("<strong>Yes</strong>: [CLAM_VIRUS: Eicar-Test]", $html);
		$this->assertMatchesRegularExpression("#<div id='symbols' hidden>\\s*\\S*CLAM_VIRUS#", $html);
		$this->assertStringContainsString('Learn as Spam</button>', $html);
	}

	public function testShowMailForUser(): void {
		$html = $this->html($this->mails(self::request('/mail/41', self::USER))->showMail(41));
		$this->assertStringNotContainsString("<div class='info'>Server:</div>", $html);
	}

	/** @return array<string, array{array<string, mixed>, string}> */
	public static function viewers(): array {
		return [
			'admin' => [self::ADMIN, "Mail with ID '41' not stored"],
			'user' => [self::USER, 'Mail is not available'],
		];
	}

	/** @param array<string, mixed> $session */
	#[DataProvider('viewers')]
	public function testMailNotAvailable(array $session, string $error): void {
		$this->mail['mail_stored'] = 0;
		$request = self::request('/mail/41', $session);
		$this->assertRedirect($this->url($session === self::USER ? RouteName::DAY_LOGS : RouteName::ADMIN_DAY_LOGS), $this->mails($request)->showMail(41));
		$this->assertSame(['error' => [$error]], self::flashes($request));
		$this->assertSame("warning showMail() problem: Mail with ID '41' not stored", end($this->log->lines));
	}

	private function attachment(string $how, int $attach): Response {
		$mails = $this->mails(self::request('/mail/41/attach', self::ADMIN));
		return $how === 'save' ? $mails->saveAttachment(41, $attach) : $mails->openAttachment(41, $attach);
	}

	private static function streamed(Response $response): string {
		self::assertInstanceOf(StreamedResponse::class, $response);
		ob_start();
		$response->sendContent();
		return (string) ob_get_clean();
	}

	public function testSaveAttachment(): void {
		$response = $this->attachment('save', 0);
		$this->assertSame(['application/pdf', '3'], [$response->headers->get('Content-Type'), $response->headers->get('Content-Length')]);
		$this->assertMatchesRegularExpression("/^attachment; filename=[0-9a-f]{16}\\.pdf; filename\\*=utf-8''inv\\.pdf$/D", (string) $response->headers->get('Content-Disposition'));
		$this->assertSame('PDF', self::streamed($response));
	}

	/** @return array<string, array{int, string}> */
	public static function inline(): array {
		return [
			'an image, as itself' => [1, 'image/png'],
			'a pdf, as bytes' => [0, 'application/octet-stream'],
		];
	}

	#[DataProvider('inline')]
	public function testOpenAttachment(int $attach, string $type): void {
		$response = $this->attachment('open', $attach);
		$this->assertSame($type, $response->headers->get('Content-Type'));
		$this->assertStringStartsWith('inline; filename=', (string) $response->headers->get('Content-Disposition'));
	}

	public function testOpenAttachmentContent(): void {
		$response = $this->attachment('open', 0);
		$this->assertSame('PDF', self::streamed($response));
		$this->assertSame('3', $response->headers->get('Content-Length'));
	}

	// a report or headers part opens as plain text, never as itself
	public function testOpenAttachmentAsText(): void {
		file_put_contents("{$this->dir}/Q41/Q41", str_replace('Content-Type: application/pdf; name="inv.pdf"', 'Content-Type: message/delivery-status; name="inv.pdf"', self::EML));
		$this->assertSame('text/plain; charset=utf-8', $this->attachment('open', 0)->headers->get('Content-Type'));
	}

	/** @return array<string, array{string}> */
	public static function attachmentActions(): array {
		return ['save' => ['save'], 'open' => ['open']];
	}

	#[DataProvider('attachmentActions')]
	public function testAttachmentNotFound(string $how): void {
		$request = self::request('/mail/41/attach', self::ADMIN);
		$mails = $this->mails($request);
		$response = $how === 'save' ? $mails->saveAttachment(41, 7) : $mails->openAttachment(41, 7);
		$this->assertRedirect($this->url(RouteName::ADMIN_DAY_LOGS), $response);
		$this->assertSame(['warning' => ['Attachment not found']], self::flashes($request));
	}

	#[DataProvider('attachmentActions')]
	public function testAttachmentMailNotAvailable(string $how): void {
		$this->mail['mail_stored'] = 0;
		$request = self::request('/mail/41/attach', self::USER);
		$mails = $this->mails($request);
		$response = $how === 'save' ? $mails->saveAttachment(41, 0) : $mails->openAttachment(41, 0);
		$this->assertRedirect($this->url(RouteName::DAY_LOGS), $response);
		$this->assertSame(['error' => ['Mail is not available']], self::flashes($request));
		$this->assertSame("warning {$how}Attachment() problem: Mail with ID '41' not stored", end($this->log->lines));
	}
}
