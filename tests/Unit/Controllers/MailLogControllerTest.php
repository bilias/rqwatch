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
use App\Controllers\MailLogController;
use App\Core\Routing\RouteName;
use App\Services\MailerService;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

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

	public function testSearchPage(): void {
		$request = self::request($this->url(RouteName::ADMIN_SEARCH), self::withFilters(self::ADMIN, [['filter' => 'Action', 'choice' => 'is equal to', 'value' => 'reject']]));
		$this->framed($request);
		$html = $this->html($this->mails($request)->search());
		$this->assertFramed($html, 'Search');
		$this->assertStringContainsString('name="search_form[value]"', $html);
		$this->assertStringContainsString('reject', $html);
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

	public function testReportByDay(): void {
		Config::set('show_charts', true);
		$this->answers = array_merge([['created_day AS date', [['date' => '2026-11-20', 'total' => 3, 'total_size' => 3000, 'total_stored' => 2]]]], $this->answers);
		$html = $this->html($this->mails(self::request('/admin/reports/date/day', self::ADMIN))->showReports('date', 'day'));
		$this->assertStringContainsString('?date=2026-11-20', $html);
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
		$this->assertStringStartsWith('error [MailLogController_showSymbolsReport] query error: ', $this->log->lines[0] ?? '');
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
	}

	public function testVirusMails(): void {
		$this->answers = array_merge([['from `mail_log_viruses`', [['mail_log_id' => 41]]]], $this->answers);
		$html = $this->html($this->mails(self::request('/admin/reports/virus/mails', self::ADMIN, ['virus' => 'Eicar', 'window' => 'week', 'fail' => '1']))->showVirusMails());
		$this->assertStringContainsString('Scan failure', $html);
		$this->assertStringContainsString('Invoice', $html);
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
		$this->assertContains("info Message Q41 released to 'alice@example.org' by 'root@example.org' from '192.0.2.7' via web (sent by mx1)", $this->log->lines);
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
	}

	public function testShowMail(): void {
		$response = $this->mails(self::request('/admin/mail/41', self::ADMIN))->showMail(41);
		$html = $this->html($response);
		$this->assertStringContainsString('Please pay the held invoice', $html);
		$this->assertStringContainsString('inv.pdf', $html);
		$this->assertStringContainsString("style-src 'self' 'unsafe-inline'", (string) $response->headers->get('Content-Security-Policy'));
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
	}
}
