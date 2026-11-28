<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The map hits pages, on RecordingDatabase. Admin only, but for the mails
 of one map entry a user may see; all need the map hits migration. The
 report: per map, distinct mails today, in 7 and in 31 days and the last
 hit, the busiest first and idle maps last by name, then the recorded
 symbols no map is credited with; a query error goes back to the maps,
 logged. The mails behind a count: of a map (with the outcome filters
 ?action=, ?released=1, ?notified=1 and one ?day=), of an uncredited
 ?symbol=, or of one ?entry= of a map, in ?days= (a report window, or 0
 for every recorded day); anything else back to the report (the user's
 home for a user). A map's outcome: its mails per action, released and
 notified, with the totals, and the daily chart when charts are on.
*/

namespace Tests\Unit\Controllers;

use App\Configuration\Config;
use App\Controllers\MapHitController;
use App\Core\Routing\RouteName;
use App\Inventory\Migrations;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class MapHitControllerTest extends ControllerTestCase
{
	private const array MAIL = [
		'id' => 41, 'qid' => 'QID41', 'action' => 'reject', 'has_virus' => 0, 'mail_from' => 'spam@example.net',
		'mime_from' => 'spam@example.net', 'mime_to' => 'alice@example.org', 'subject' => 'Cheap pills', 'size' => 1200,
		'score' => 15.5, 'server' => 'mx1', 'mail_stored' => 0, 'mail_location' => null, 'message_id' => '<41@example.net>',
		'ip' => '192.0.2.50', 'notified' => 0, 'notify_date' => null, 'released' => 0, 'release_date' => null,
		'notification_pending' => 0, 'created_at' => '2026-11-20 10:00:00', 'updated_at' => '2026-11-20 10:00:00', 'created_day' => '2026-11-20',
	];

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
	}

	/** @param array<string, mixed> $parameters */
	private function url(RouteName $route, array $parameters = []): string {
		return $this->urls->generate($route->value, $parameters);
	}

	/**
	 * @param array<string, mixed> $query
	 * @param array<string, mixed> $session
	 * @return array{Response, Request}
	 */
	private function call(string $action, array $query = [], array $session = self::ADMIN, bool $framed = false): array {
		$request = self::request($this->url(RouteName::ADMIN_MAP_HITS), $session, $query);
		if ($framed) {
			$this->framed($request);
		}
		$controller = $this->controller(new MapHitController(), $request);
		$response = match ($action) {
			'showReport' => $controller->showReport(),
			'showMails' => $controller->showMails(),
			default => $controller->showOutcome(),
		};
		return [$response, $request];
	}

	private function html(Response $response): string {
		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());
		return (string) $response->getContent();
	}

	public function testReport(): void {
		$this->answers = [
			['group by `sn`.`name`', [
				['name' => 'LOCAL_RULE', 'd1' => 0, 'd7' => 1, 'd31' => 2, 'last' => '2026-11-25'],
				['name' => 'OTHER_RULE', 'd1' => 1, 'd7' => 4, 'd31' => 7, 'last' => '2026-11-27'],
			]],
			['AS d1, COUNT', function (array $bindings): array {
				// the map's name, wherever the symbol ids put it
				$map = current(array_filter($bindings, 'is_string'));
				return match ($map === false ? null : $map) {
					'ip_blacklist' => [['d1' => 1, 'd7' => 3, 'd31' => 5]],
					'mail_from_blacklist' => [['d1' => 0, 'd7' => 2, 'd31' => 5]],
					'mime_from_whitelist' => [['d1' => 0, 'd7' => 0, 'd31' => 9]],
					default => [['d1' => 0, 'd7' => 0, 'd31' => 0]],
				};
			}],
			['MAX(t.created_day) AS last', fn (array $bindings): array => [['last' => in_array('ip_whitelist', $bindings, true) ? '2026-09-01' : null]]],
			['from `symbol_names` where `name` in', fn (array $bindings): array => count($bindings) > 3 && in_array('RQWATCH_IP_BLACKLIST', $bindings, true) ? [['id' => 3, 'name' => 'RQWATCH_IP_BLACKLIST']] : []],
		];
		[$response] = $this->call('showReport');
		$html = $this->html($response);
		// busiest first; of the same count, by name; idle maps by name
		$previous = 0;
		foreach (['mime_from_whitelist', 'ip_blacklist', 'mail_from_blacklist', 'ip_whitelist', 'mail_from_rcpt_to_blacklist'] as $map) {
			$at = strpos($html, '<br/>' . $map);
			$this->assertNotFalse($at, $map);
			$this->assertGreaterThan($previous, $at, $map);
			$previous = $at;
		}
		$this->assertStringContainsString('?map=ip_blacklist&amp;days=7">3</a>', $html);
		$this->assertStringContainsString('2026-09-01 (idle)', $html);
		$this->assertStringContainsString('never (idle)', $html);
		$this->assertStringContainsString('RQWATCH_IP_BLACKLIST', $html);
		$this->assertLessThan(strpos($html, 'LOCAL_RULE'), strpos($html, 'OTHER_RULE'));
		$this->assertStringContainsString('?symbol=OTHER_RULE&amp;days=31">7</a>', $html);
		$this->assertStringContainsString('IP Blacklist', $html);
		// the maps' own symbols are left out of the unmapped ones
		$unmapped = $this->selected('group by `sn`.`name`');
		$this->assertNotNull($unmapped);
		$this->assertStringContainsString('`h`.`symbol_id` not in (?)', $unmapped[0]);
		$this->assertContains(3, $unmapped[1]);
	}

	/** @return array<string, array{string, array<string, mixed>}> */
	public static function pages(): array {
		return [
			'report' => ['showReport', []],
			'mails' => ['showMails', ['map' => 'ip_blacklist']],
			'outcome' => ['showOutcome', ['map' => 'ip_blacklist']],
		];
	}

	/** @param array<string, mixed> $query */
	#[DataProvider('pages')]
	public function testFramed(string $action, array $query): void {
		$this->answers = [['SELECT CURDATE() AS d', [['d' => '2026-11-27']]]];
		[$response] = $this->call($action, $query, self::ADMIN, true);
		$this->assertFramed($this->html($response), 'Maps');
	}

	/** @param array<string, mixed> $query */
	#[DataProvider('pages')]
	public function testQidBox(string $action, array $query): void {
		$controller = $this->controller(new MapHitController(), self::qidPosted(self::ADMIN, $query));
		$this->assertToQid(match ($action) {
			'showReport' => $controller->showReport(),
			'showMails' => $controller->showMails(),
			default => $controller->showOutcome(),
		});
	}

	public function testReportQueryError(): void {
		$this->answers = [['AS d1, COUNT', function (): array {
			throw new RuntimeException('server has gone away');
		}]];
		[$response, $request] = $this->call('showReport');
		$this->assertRedirect($this->url(RouteName::ADMIN_MAPS), $response);
		$this->assertSame(['error' => ['Map hits query error. Check logs.']], self::flashes($request));
		$this->assertSame(['error [MapHitController_showReport] query error: server has gone away'], $this->log->lines);
	}

	/** @return array<string, array{string, array<string, mixed>}> */
	public static function adminPages(): array {
		return [
			'report' => ['showReport', []],
			'outcome' => ['showOutcome', ['map' => 'ip_blacklist']],
			'mails of a map' => ['showMails', ['map' => 'ip_blacklist']],
		];
	}

	/** @param array<string, mixed> $query */
	#[DataProvider('adminPages')]
	public function testUserRefused(string $action, array $query): void {
		[$response, $request] = $this->call($action, $query, self::USER);
		$this->assertRedirect($this->url(RouteName::DAY_LOGS), $response);
		$this->assertSame(['error' => ['Permission denied']], self::flashes($request));
		$this->assertSame(["warning 'alice' tried to show map hits without admin authorization"], $this->log->lines);
	}

	/** @param array<string, mixed> $query */
	#[DataProvider('adminPages')]
	public function testNeedsMigration(string $action, array $query): void {
		$this->status->setMigrationState(Migrations::MAP_HITS, Migrations::STATUS_RUNNING);
		[$response, $request] = $this->call($action, $query);
		$this->assertRedirect($this->url(RouteName::ADMIN_DAY_LOGS), $response);
		$this->assertSame(['error' => ['Map hits need the map hits migration (db:migrate_map_hits)']], self::flashes($request));
	}

	public function testMapMails(): void {
		$this->answers = [
			['SELECT DISTINCT t.mail_log_id', [['mail_log_id' => 41]]],
			['from `mail_logs` where `id` in', [self::MAIL]],
		];
		[$response] = $this->call('showMails', ['map' => 'ip_blacklist', 'days' => 7, 'action' => 'reject', 'released' => '1', 'notified' => '1']);
		$html = $this->html($response);
		$ids = $this->selected('SELECT DISTINCT t.mail_log_id');
		$this->assertNotNull($ids);
		$this->assertStringContainsString('ml.released = 1 AND ml.notified = 1', $ids[0]);
		$this->assertSame([1, 'ip_blacklist', 6, 'reject'], $ids[1]);
		$this->assertStringContainsString('Cheap pills', $html);
		foreach (['Map hit by "IP Blacklist"', 'Action is "reject"', 'Released is "yes"', 'Notified is "yes"', 'Date in "the last 7 days"'] as $shown) {
			$this->assertStringContainsString($shown, $html);
		}
	}

	// two pages: the next page's link keeps the list's query; the settings of the list shown
	public function testMapMailsPages(): void {
		Config::set('items_per_page', 1);
		Config::set('max_items', 2);
		Config::set('refresh_rate', 60);
		Config::set('subject_privacy', null);
		$this->answers = [
			['SELECT DISTINCT t.mail_log_id', [['mail_log_id' => 41], ['mail_log_id' => 40]]],
			['from `mail_logs` where `id` in', [self::MAIL]],
		];
		[$response] = $this->call('showMails', ['map' => 'ip_blacklist', 'days' => 7, 'released' => '1']);
		$html = $this->html($response);
		$this->assertStringContainsString('?page=2&amp;map=ip_blacklist&amp;days=7&amp;released=1', $html);
		$this->assertStringContainsString($this->url(RouteName::ADMIN_MAP_HITS_MAILS) . '?page=2', $html);
		foreach (['/ Limited', '| Displaying 1/page', '| Refreshing every 60 s', 'Cheap pills'] as $shown) {
			$this->assertStringContainsString($shown, $html);
		}
	}

	public function testMapMailsOfADay(): void {
		[$response] = $this->call('showMails', ['map' => 'ip_blacklist', 'day' => '2026-11-20']);
		$html = $this->html($response);
		$this->assertSame([1, 'ip_blacklist', '2026-11-20'], $this->selected('SELECT DISTINCT t.mail_log_id')[1] ?? null);
		$this->assertStringContainsString('the day 2026-11-20', $html);
	}

	/** @return array<string, array{string}> */
	public static function badDays(): array {
		return [
			'not a date' => ['yesterday'],
			'no such date' => ['2026-13-45'],
			'trailing text' => ['2026-11-20x'],
			'a leading blank' => [' 2026-11-20'],
			'a trailing newline' => ["2026-11-20\n"],
		];
	}

	// a day that is not one: the window instead
	#[DataProvider('badDays')]
	public function testMapMailsBadDay(string $day): void {
		[$response] = $this->call('showMails', ['map' => 'ip_blacklist', 'day' => $day]);
		$this->assertStringContainsString('the last 31 days', $this->html($response));
		$this->assertSame([1, 'ip_blacklist', 30], $this->selected('SELECT DISTINCT t.mail_log_id')[1] ?? null);
	}

	/** @return array<string, array{string}> */
	public static function badActions(): array {
		return [
			'upper case' => ['REJECT'],
			'too long' => [str_repeat('a', 21)],
			'a trailing newline' => ["reject\n"],
		];
	}

	#[DataProvider('badActions')]
	public function testMapMailsBadActionIgnored(string $action): void {
		$this->call('showMails', ['map' => 'ip_blacklist', 'action' => $action, 'released' => 'yes']);
		$ids = $this->selected('SELECT DISTINCT t.mail_log_id')[0] ?? '';
		$this->assertStringContainsString('ORDER BY t.mail_log_id', $ids);
		$this->assertStringNotContainsString('ml.action', $ids);
		$this->assertStringNotContainsString('released', $ids);
	}

	// over two pages: the next page keeps the symbol
	public function testSymbolMailsFilter(): void {
		Config::set('items_per_page', 1);
		$this->answers = [
			['from `symbol_names` where `name` in', [['id' => 12, 'name' => 'OTHER_RULE']]],
			['from `mail_log_symbol_hits`', [['mail_log_id' => 41], ['mail_log_id' => 40]]],
			['from `mail_logs` where `id` in', [self::MAIL]],
		];
		[$response] = $this->call('showMails', ['symbol' => 'OTHER_RULE', 'days' => 7]);
		$html = $this->html($response);
		$this->assertStringContainsString('Symbol not credited to a map "OTHER_RULE"', $html);
		$this->assertStringContainsString('?page=2&amp;symbol=OTHER_RULE&amp;days=7', $html);
	}

	public function testSubjectPrivacy(): void {
		Config::set('subject_privacy', true);
		$this->answers = [['SELECT DISTINCT t.mail_log_id', [['mail_log_id' => 41]]], ['from `mail_logs` where `id` in', [self::MAIL]]];
		[$response] = $this->call('showMails', ['map' => 'ip_blacklist']);
		$this->assertStringNotContainsString('Cheap pills', $this->html($response));
	}

	public function testSymbolMails(): void {
		$this->answers = [
			['from `symbol_names` where `name` in', fn (array $bindings): array => $bindings === ['OTHER_RULE'] ? [['id' => 12, 'name' => 'OTHER_RULE']] : []],
		];
		[$response] = $this->call('showMails', ['symbol' => 'OTHER_RULE', 'days' => 0]);
		$html = $this->html($response);
		$this->assertStringContainsString('not credited to a map', $html);
		$this->assertStringContainsString('any recorded day', $html);
		$this->assertSame(['select distinct `mail_log_id` from `mail_log_symbol_hits` where `symbol_id` = ? and `entry_model` = ? order by `mail_log_id` desc limit 10000', [12, 0]], end($this->db->selects));
	}

	public function testToday(): void {
		[$response] = $this->call('showMails', ['symbol' => 'OTHER_RULE', 'days' => 1]);
		$this->assertStringContainsString('today', $this->html($response));
	}

	/** @return array<string, array{array<string, mixed>}> */
	public static function notAList(): array {
		return [
			'a window not in the report' => [['map' => 'ip_blacklist', 'days' => 3]],
			'an unknown map' => [['map' => 'nosuch']],
			'a symbol that is not one' => [['symbol' => 'no such']],
			'nothing' => [[]],
		];
	}

	/** @param array<string, mixed> $query */
	#[DataProvider('notAList')]
	public function testNotAListBackToReport(array $query): void {
		[$response] = $this->call('showMails', $query);
		$this->assertRedirect($this->url(RouteName::ADMIN_MAP_HITS), $response);
	}

	public function testMailsQueryError(): void {
		$this->answers = [['SELECT DISTINCT t.mail_log_id', function (): array {
			throw new RuntimeException('server has gone away');
		}]];
		[$response, $request] = $this->call('showMails', ['map' => 'ip_blacklist']);
		$this->assertRedirect($this->url(RouteName::ADMIN_MAP_HITS), $response);
		$this->assertSame(['error' => ['Map hits query error. Check logs.']], self::flashes($request));
		$this->assertSame(['error [MapHitController_showMails] query error: server has gone away'], $this->log->lines);
	}

	private function entry(): void {
		$this->answers[] = ['from `maps_combined` where `map_name` = ?', fn (array $bindings): array => ($bindings[1] ?? null) === 5
			? [['id' => 5, 'map_name' => 'mail_from_rcpt_to_whitelist', 'mail_from' => 'friend@example.net', 'rcpt_to' => 'alice@example.org', 'user_id' => 7]]
			: []];
	}

	public function testUserEntryMails(): void {
		$this->entry();
		[$response] = $this->call('showMails', ['map' => 'mail_from_rcpt_to_whitelist', 'entry' => 5, 'days' => 7], self::USER);
		$html = $this->html($response);
		$this->assertStringContainsString('Map entry of Mail From/RCPT_TO Whitelist "friend@example.net | alice@example.org"', $html);
		$this->assertStringContainsString('of Mail From/RCPT_TO Whitelist', $html);
		$last = end($this->db->selects);
		$this->assertSame([1, 5], array_slice($last[1] ?? [], 0, 2));
	}

	// a user's entry list over two pages: on the user's own mails list
	public function testUserEntryMailsPages(): void {
		Config::set('items_per_page', 1);
		$this->entry();
		$this->answers[] = ['select distinct `mail_log_id` from `mail_log_symbol_hits`', [['mail_log_id' => 41], ['mail_log_id' => 40]]];
		// the user's scope first: its query also reads mail_logs by id
		$this->answers[] = ['select `id` from `mail_logs`', [['id' => 41], ['id' => 40]]];
		$this->answers[] = ['from `mail_logs` where `id` in', [self::MAIL]];
		[$response] = $this->call('showMails', ['map' => 'mail_from_rcpt_to_whitelist', 'entry' => 5, 'days' => 7], self::USER);
		$this->assertStringContainsString($this->url(RouteName::MAP_HITS_MAILS) . '?page=2&amp;map=mail_from_rcpt_to_whitelist&amp;entry=5&amp;days=7', $this->html($response));
	}

	/** @return array<string, array{array<string, mixed>}> */
	public static function entriesNotShown(): array {
		return [
			'not found' => [['map' => 'mail_from_rcpt_to_whitelist', 'entry' => 6]],
			"a map not the user's" => [['map' => 'ip_blacklist', 'entry' => 5]],
			'no map' => [['entry' => 5]],
		];
	}

	/** @param array<string, mixed> $query */
	#[DataProvider('entriesNotShown')]
	public function testUserEntryNotShown(array $query): void {
		$this->entry();
		[$response, $request] = $this->call('showMails', $query, self::USER);
		$this->assertRedirect($this->url(RouteName::DAY_LOGS), $response);
		// refused, not a query error
		$this->assertSame([[], []], [self::flashes($request), $this->log->lines]);
	}

	public function testCustomEntryMails(): void {
		$this->answers = [
			['from `custom_map_config` order by', [['id' => 1, 'map_name' => 'phish', 'map_description' => 'Phishing URLs', 'field_name' => 'url', 'field_label' => 'URL', 'created_at' => null]]],
			['from `maps_custom` where `map_name` = ?', [['id' => 9, 'map_name' => 'phish', 'pattern' => 'evil.example', 'disabled' => 0]]],
		];
		[$response] = $this->call('showMails', ['map' => 'phish', 'entry' => 9]);
		$this->assertStringContainsString('evil.example', $this->html($response));
		$last = end($this->db->selects);
		$this->assertSame([2, 9, 30], $last[1] ?? null);
	}

	private function outcome(): void {
		$this->answers = [
			['SELECT ml.action, COUNT(*) AS mails', [
				['action' => 'reject', 'mails' => 5, 'released' => 1, 'notified' => 0],
				['action' => 'add header', 'mails' => 3, 'released' => 0, 'notified' => 2],
			]],
			['SELECT t.created_day AS day', [['day' => '2026-11-20', 'mails' => 4]]],
			['SELECT CURDATE() AS d', [['d' => '2026-11-27']]],
		];
	}

	public function testOutcome(): void {
		$this->outcome();
		Config::set('show_charts', true);
		[$response] = $this->call('showOutcome', ['map' => 'ip_blacklist', 'days' => 7]);
		$html = $this->html($response);
		$this->assertSame([1, 'ip_blacklist', 6], $this->selected('SELECT ml.action')[1] ?? null);
		$this->assertStringContainsString('IP Blacklist', $html);
		$this->assertStringContainsString('add header', $html);
		$this->assertMatchesRegularExpression('/>\s*8\s*</', $html);
		// the totals (their links without an action): released 1, notified 2
		$this->assertStringContainsString('?map=ip_blacklist&amp;days=7&amp;released=1">1</a>', $html);
		$this->assertStringContainsString('?map=ip_blacklist&amp;days=7&amp;notified=1">2</a>', $html);
		// each bar to its day's mails of this map
		$this->assertStringContainsString(str_replace('/', '\\/', $this->url(RouteName::ADMIN_MAP_HITS_MAILS)) . '?map=ip_blacklist&day=2026-11-20"', $html);
		$this->assertStringContainsString("Click on a bar to view that day&#039;s mails", $html);
		$this->assertStringContainsString('day=2026-11-20', $html);
	}

	public function testOutcomeWithoutChart(): void {
		$this->outcome();
		Config::set('show_charts', false);
		[$response] = $this->call('showOutcome', ['map' => 'ip_blacklist', 'days' => 7]);
		$this->assertStringNotContainsString('Click on a bar', $this->html($response));
		$this->assertNull($this->selected('SELECT t.created_day AS day'));
	}

	/** @return array<string, array{array<string, mixed>}> */
	public static function noOutcome(): array {
		return [
			'an unknown map' => [['map' => 'nosuch']],
			'a window not in the report' => [['map' => 'ip_blacklist', 'days' => 0]],
		];
	}

	/** @param array<string, mixed> $query */
	#[DataProvider('noOutcome')]
	public function testNoOutcome(array $query): void {
		[$response] = $this->call('showOutcome', $query);
		$this->assertRedirect($this->url(RouteName::ADMIN_MAP_HITS), $response);
	}

	public function testOutcomeQueryError(): void {
		$this->answers = [['SELECT ml.action', function (): array {
			throw new RuntimeException('server has gone away');
		}]];
		[$response, $request] = $this->call('showOutcome', ['map' => 'ip_blacklist']);
		$this->assertRedirect($this->url(RouteName::ADMIN_MAP_HITS), $response);
		$this->assertSame(['error' => ['Map hits query error. Check logs.']], self::flashes($request));
		$this->assertSame(['error [MapHitController_showOutcome] query error: server has gone away'], $this->log->lines);
	}
}
