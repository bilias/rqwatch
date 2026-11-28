<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The map pages and actions, on RecordingDatabase with a MAP_DIR of their
 own. The map picker (its choice goes to the map), the entries of all
 maps or of one (a user's only their own, and no custom map), the custom
 maps' configs (admin only: added, edited, deleted; the virus skip map is
 protected; a name is new, lowercased, never manage_custom_maps). An
 entry added (prefilled from the query string; an entry already there,
 or past the user's quota, refused; back to the mail it came from),
 edited, enabled or disabled, deleted, or all of a map's; each action
 with its CSRF token. The admin's search of the entries, a GET page that
 its form posts to.
*/

namespace Tests\Unit\Controllers;

use App\Configuration\Config;
use App\Controllers\MapController;
use App\Core\Routing\RouteName;
use App\Inventory\Migrations;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class MapControllerTest extends ControllerTestCase
{
	private string $dir = '';

	/** @var list<array<string, mixed>> the maps_combined rows */
	private array $entries = [];

	/** @var list<array<string, mixed>> the custom_map_config rows */
	private array $custom = [];

	/** @var list<array<string, mixed>> the maps_custom rows */
	private array $customEntries = [];

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->setEnv('WEB_HOST', 'rq.example.org');
		$this->dir = sys_get_temp_dir() . '/rqwatch-mc-' . bin2hex(random_bytes(4));
		mkdir($this->dir);
		Config::set('MAP_DIR', $this->dir);
		Config::set('MAP_FILE_PERM', 0640);
		Config::set('notification_skip_virus_map', 'virus_skip');
		$this->entries = [
			['id' => 5, 'map_name' => 'mail_from_rcpt_to_whitelist', 'mail_from' => 'friend@example.net', 'rcpt_to' => 'alice@example.org', 'mime_from' => null, 'ip' => null,
				'user_id' => 7, 'disabled' => 0, 'created_at' => '2026-11-01 10:00:00', 'updated_at' => '2026-11-01 10:00:00'],
		];
		$this->custom = [
			['id' => 1, 'map_name' => 'phish', 'map_description' => 'Phishing URLs', 'field_name' => 'url', 'field_label' => 'URL', 'symbols' => null, 'created_at' => null, 'updated_at' => null],
			['id' => 2, 'map_name' => 'virus_skip', 'map_description' => 'Virus skip', 'field_name' => 'email', 'field_label' => 'Address', 'symbols' => null, 'created_at' => null, 'updated_at' => null],
		];
		$this->customEntries = [['id' => 9, 'map_name' => 'phish', 'pattern' => 'evil.example', 'disabled' => 0, 'user_id' => 1, 'created_at' => '2026-11-01 10:00:00', 'updated_at' => '2026-11-01 10:00:00']];
		$this->answers = [
			['select exists(', [['exists' => 0]]],
			['count(*) as aggregate from `maps_combined`', fn (): array => [['aggregate' => count($this->entries)]]],
			['count(*) as aggregate from `maps_custom`', fn (): array => [['aggregate' => count($this->customEntries)]]],
			['count(*) as aggregate from `custom_map_config`', fn (): array => [['aggregate' => count($this->custom)]]],
			['select `field_name`, `field_label` from `custom_map_config` where `map_name` = ?', fn (array $b): array => array_values(array_filter($this->custom, fn (array $c): bool => $c['map_name'] === ($b[0] ?? null)))],
			['from `custom_map_config` where `custom_map_config`.`id` = ?', fn (array $b): array => array_values(array_filter($this->custom, fn (array $c): bool => $c['id'] === ($b[0] ?? null)))],
			['from `custom_map_config`', fn (): array => $this->custom],
			['from `maps_combined`', fn (): array => $this->entries],
			['from `maps_custom`', fn (): array => $this->customEntries],
			['from `users` where `users`.`id` in', [['id' => 7, 'username' => 'alice']]],
		];
		$this->db->affect = fn (): int => 1;
	}

	#[\Override]
	protected function tearDown(): void {
		$files = glob("{$this->dir}/*");
		foreach ($files === false ? [] : $files as $file) {
			unlink($file);
		}
		rmdir($this->dir);
		parent::tearDown();
	}

	/** @param array<string, mixed> $parameters */
	private function url(RouteName $route, array $parameters = []): string {
		return $this->urls->generate($route->value, $parameters);
	}

	private function maps(Request $request): MapController {
		return $this->controller(new MapController(), $request);
	}

	private function html(Response $response): string {
		$this->assertSame(Response::HTTP_OK, $response->getStatusCode());
		return (string) $response->getContent();
	}

	public function testPicker(): void {
		$request = self::request($this->url(RouteName::ADMIN_MAPS), self::ADMIN);
		$this->framed($request);
		$html = $this->html($this->maps($request)->showSelectMap());
		$this->assertFramed($html, 'Maps');
		$this->assertStringContainsString('name="map_combined_form[map_name]"', $html);
		$this->assertStringContainsString('name="map_custom_form[map_name]"', $html);
		$this->assertStringContainsString('<option value="phish">Phishing URLs</option>', $html);
	}

	public function testPickerChoice(): void {
		$request = self::request('/admin/maps', self::ADMIN);
		$request->setMethod('POST');
		$request->request->replace(['map_custom_form' => ['map_name' => 'phish', 'model' => 'MapCustom', 'select' => '', '_token' => self::csrf($request, 'map_custom_form')]]);
		$this->assertRedirect($this->url(RouteName::ADMIN_MAP_SHOW, ['map' => 'phish']), $this->maps($request)->showSelectMap());
	}

	/** @return array<string, array{\Closure(MapController): Response}> */
	public static function pages(): array {
		return [
			'the picker' => [fn (MapController $c): Response => $c->showSelectMap()],
			'all entries' => [fn (MapController $c): Response => $c->showAllMaps()],
			'the configs' => [fn (MapController $c): Response => $c->showCustomMapsConfig()],
			'a new config' => [fn (MapController $c): Response => $c->addCustomMap()],
			'a config' => [fn (MapController $c): Response => $c->editCustomMap(1)],
			'a map' => [fn (MapController $c): Response => $c->showMap('ip_blacklist')],
			'a new entry' => [fn (MapController $c): Response => $c->addMapEntry('ip_blacklist')],
			'an entry' => [fn (MapController $c): Response => $c->editMapEntry('ip_blacklist', 5)],
			'the search' => [fn (MapController $c): Response => $c->searchMapEntry()],
		];
	}

	/** @param \Closure(MapController): Response $page */
	#[DataProvider('pages')]
	public function testFramedPages(\Closure $page): void {
		$request = self::request($this->url(RouteName::ADMIN_MAPS), self::ADMIN, ['model' => 'MapCombined', 'q' => 'friend']);
		$this->framed($request);
		$this->assertFramed($this->html($page($this->maps($request))), 'Maps');
	}

	/** @param \Closure(MapController): Response $page */
	#[DataProvider('pages')]
	public function testQidBox(\Closure $page): void {
		$this->assertToQid($page($this->maps(self::qidPosted(self::ADMIN))));
	}

	/** @param \Closure(MapController): Response $page */
	#[DataProvider('pages')]
	public function testPickerOnEveryPage(\Closure $page): void {
		$request = self::request('/admin/maps', self::ADMIN);
		$request->setMethod('POST');
		$request->request->replace(['map_combined_form' => ['map_name' => 'ip_blacklist', 'model' => 'MapCombined', 'select' => '', '_token' => self::csrf($request, 'map_combined_form')]]);
		$this->assertRedirect($this->url(RouteName::ADMIN_MAP_SHOW, ['map' => 'ip_blacklist']), $page($this->maps($request)));
	}

	// the picker's choice is handled on every page, whichever shows the picker
	/** @param \Closure(MapController): Response $page */
	#[DataProvider('pages')]
	public function testPickerChoiceOnEveryPage(\Closure $page): void {
		$request = self::request('/admin/maps', self::ADMIN, ['model' => 'MapCombined', 'q' => 'friend']);
		$request->setMethod('POST');
		$request->request->replace(['map_custom_form' => ['map_name' => 'phish', 'model' => 'MapCustom', 'select' => '', '_token' => self::csrf($request, 'map_custom_form')]]);
		$this->assertRedirect($this->url(RouteName::ADMIN_MAP_SHOW, ['map' => 'phish']), $page($this->maps($request)));
	}

	/** @return array<string, array{array<string, mixed>, bool, bool}> who, map hits migrated, the link */
	public static function mapHitsLinks(): array {
		return [
			'an admin' => [self::ADMIN, true, true],
			'a user' => [self::USER, true, false],
			'before the migration' => [self::ADMIN, false, false],
		];
	}

	// the map hits page, linked for an admin once the map hits are migrated
	/** @param array<string, mixed> $session */
	#[DataProvider('mapHitsLinks')]
	public function testPickerMapHitsLink(array $session, bool $migrated, bool $link): void {
		if (!$migrated) {
			$this->status->setMigrationState(Migrations::MAP_HITS, Migrations::STATUS_RUNNING);
		}
		$html = $this->html($this->maps(self::request('/maps', $session))->showSelectMap());
		$this->assertSame($link, str_contains($html, '>Map hits</a>'));
	}

	public function testUserPicker(): void {
		$html = $this->html($this->maps(self::request('/maps', self::USER))->showSelectMap());
		$this->assertStringNotContainsString('<option value="phish">', $html);
		$this->assertStringContainsString('<option value="mail_from_rcpt_to_whitelist">', $html);
	}

	public function testAllEntries(): void {
		$html = $this->html($this->maps(self::request('/admin/maps/all', self::ADMIN))->showAllMaps());
		$this->assertStringContainsString('friend@example.net', $html);
		// the table's own cells: the field headers, the map, the creator
		foreach (['<th>Mail From</th>', '">Mail From/RCPT_TO Whitelist</a></td>', "<td class='center'>alice</td>"] as $shown) {
			$this->assertStringContainsString($shown, $html);
		}
	}

	// a user's pages link to the user's list
	public function testUserAllEntriesPages(): void {
		Config::set('items_per_page', 1);
		$this->entries[] = ['id' => 6] + $this->entries[0];
		$html = $this->html($this->maps(self::request('/maps/all', self::USER))->showAllMaps());
		$this->assertStringContainsString($this->url(RouteName::MAP_SHOW_ALL) . '?page=2', $html);
		$this->assertStringContainsString("Total: 2 entries\n| Displaying 1/page", $html);
	}

	// the pickers, the search, the hits column and the map file links; the first page
	public function testAllEntriesPage(): void {
		$html = $this->html($this->maps(self::request('/admin/maps/all', self::ADMIN))->showAllMaps());
		foreach (['name="map_combined_form[map_name]"', 'name="map_custom_form[map_name]"', '<div class="mapsearchform">', 'name="map_search_form[model]" value="MapCombined"',
			'<th>Hits (', '"http://rq.example.org/maps/mail_from_rcpt_to_whitelist.txt"', 'All Basic/Combined Map Entries'] as $shown) {
			$this->assertStringContainsString($shown, $html);
		}
		$this->assertStringEndsWith('offset 0', $this->selected('select * from `maps_combined`')[0] ?? '');
		$this->assertSame([], $this->log->lines);
	}

	// the custom entries: their own search scope, hits and page links
	public function testAllCustomEntriesPage(): void {
		Config::set('items_per_page', 1);
		$this->customEntries[] = ['id' => 10, 'map_name' => 'phish', 'pattern' => 'worse.example', 'disabled' => 0, 'user_id' => 1, 'created_at' => null, 'updated_at' => null];
		$html = $this->html($this->maps(self::request('/admin/maps/all/MapCustom', self::ADMIN))->showAllMaps('MapCustom'));
		foreach (['name="map_search_form[model]" value="MapCustom"', '<th>Hits (', $this->url(RouteName::ADMIN_MAP_SHOW_ALL, ['model' => 'MapCustom']) . '?page=2', "Total: 2 entries\n| Displaying 1/page"] as $shown) {
			$this->assertStringContainsString($shown, $html);
		}
		$this->assertSame([], $this->log->lines);
	}

	public function testAllCustomEntries(): void {
		$html = $this->html($this->maps(self::request('/admin/maps/all/MapCustom', self::ADMIN))->showAllMaps('MapCustom'));
		$this->assertStringContainsString('evil.example', $html);
		$this->assertStringContainsString('">Phishing URLs</a></td>', $html);
		$this->assertStringContainsString("<td class='center'>URL</td>", $html);
	}

	/** @return array<string, array{string|null}> */
	public static function models(): array {
		return ['combined' => [null], 'custom' => ['MapCustom']];
	}

	#[DataProvider('models')]
	public function testNoEntries(?string $model): void {
		$this->entries = [];
		$this->customEntries = [];
		$request = self::request('/admin/maps/all', self::ADMIN);
		$this->assertRedirect($this->url(RouteName::ADMIN_MAPS), $this->maps($request)->showAllMaps($model));
		$this->assertSame(['info' => ['No map entries exist']], self::flashes($request));
	}

	// a user's view of all entries: never the custom maps
	public function testUserAllEntries(): void {
		$html = $this->html($this->maps(self::request('/maps/all', self::USER))->showAllMaps('MapCustom'));
		$this->assertStringContainsString('friend@example.net', $html);
		$this->assertNull($this->selected('from `maps_custom`'));
	}

	public function testCustomConfigs(): void {
		$html = $this->html($this->maps(self::request('/admin/maps/custom', self::ADMIN))->showCustomMapsConfig());
		$this->assertStringContainsString('Phishing URLs', $html);
		$this->assertStringContainsString('Virus skip', $html);
	}

	public function testCustomConfigsPage(): void {
		Config::set('items_per_page', 1);
		$html = $this->html($this->maps(self::request('/admin/maps/custom', self::ADMIN))->showCustomMapsConfig());
		foreach (['name="map_combined_form[map_name]"', 'name="map_custom_form[map_name]"', 'Total: 2 entries', '| Displaying 1/page', '"http://rq.example.org/maps/phish.txt"'] as $shown) {
			$this->assertStringContainsString($shown, $html);
		}
		$this->assertStringEndsWith('limit 1 offset 0', $this->selected('select * from `custom_map_config`')[0] ?? '');
	}

	/** @return array<string, array{string, string}> */
	public static function adminOnly(): array {
		return [
			'configs' => ['showCustomMapsConfig', 'access custom map config'],
			'add a config' => ['addCustomMap', 'add custom map'],
			'edit a config' => ['editCustomMap', 'edit custom map'],
			'delete a config' => ['delCustomMap', 'delete a custom map'],
		];
	}

	#[DataProvider('adminOnly')]
	public function testUserRefused(string $action, string $what): void {
		$request = self::request('/', self::USER);
		$maps = $this->maps($request);
		$response = match ($action) {
			'showCustomMapsConfig' => $maps->showCustomMapsConfig(),
			'addCustomMap' => $maps->addCustomMap(),
			'editCustomMap' => $maps->editCustomMap(1),
			default => $maps->delCustomMap(1),
		};
		$this->assertRedirect($this->url(RouteName::DAY_LOGS), $response);
		$this->assertSame(['error' => ['Permission denied']], self::flashes($request));
		$this->assertSame(["warning 'alice' tried to {$what} without admin authorization"], $this->log->lines);
	}

	/**
	 * @param array<string, string> $fields
	 * @return array{Response, Request}
	 */
	private function postConfig(array $fields, int $id = 0): array {
		$request = self::request('/admin/maps/custom/add', self::ADMIN);
		$request->setMethod('POST');
		$request->request->replace(['custom_map_config_form' => $fields + ['map_description' => 'Senders', 'field_name' => 'email', 'field_label' => 'Sender', '_token' => self::csrf($request, 'custom_map_config_form')]]);
		$maps = $this->maps($request);
		return [$id === 0 ? $maps->addCustomMap() : $maps->editCustomMap($id), $request];
	}

	public function testAddConfigForm(): void {
		$html = $this->html($this->maps(self::request('/admin/maps/custom/add', self::ADMIN))->addCustomMap());
		$this->assertStringContainsString('name="custom_map_config_form[map_name]"', $html);
		$this->assertStringContainsString('name="custom_map_config_form[symbols]"', $html);
	}

	public function testAddConfig(): void {
		[$response, $request] = $this->postConfig(['map_name' => 'senders']);
		$this->assertRedirect($this->url(RouteName::ADMIN_MAPS_CUSTOM_SHOW), $response);
		$this->assertSame(['success' => ["Custom Map 'senders' created"]], self::flashes($request));
		$this->assertContains("info Custom map 'senders' created by 'root@example.org'", $this->log->lines);
		$this->assertSame('senders', $this->db->rows('custom_map_config')[0]['map_name'] ?? null);
	}

	// the rspamd symbols reading the map, while map hits are on
	public function testAddConfigSymbols(): void {
		$this->postConfig(['map_name' => 'senders', 'symbols' => 'MY_SYM']);
		$this->assertSame('MY_SYM', $this->db->rows('custom_map_config')[0]['symbols'] ?? null);
	}

	public function testAddConfigReservedName(): void {
		[$response, $request] = $this->postConfig(['map_name' => 'manage_custom_maps']);
		$this->assertRedirect($this->url(RouteName::ADMIN_MAPS_CUSTOM_ADD), $response);
		$this->assertSame(['error' => ["Map name 'manage_custom_maps' is not allowed!"]], self::flashes($request));
		$this->assertSame([], $this->db->rows('custom_map_config'));
	}

	public function testAddConfigExisting(): void {
		$this->answers[0] = ['select exists(', [['exists' => 1]]];
		[$response, $request] = $this->postConfig(['map_name' => 'phish']);
		$this->assertRedirect($this->url(RouteName::ADMIN_MAPS_CUSTOM_ADD), $response);
		$this->assertSame(['error' => ["Map 'phish' already exists!"]], self::flashes($request));
	}

	private const string FILE_BEHIND = 'The change is saved, but the map file could not be written: it takes effect when cron:updatemapfiles writes the file. Check logs.';

	/** @return array<string, array{string, string}> the change, its success message */
	public static function savedChanges(): array {
		return [
			'a custom map added' => ['add config', "Custom Map 'senders' created"],
			'a custom map updated' => ['edit config', "Custom Map 'phishing' updated"],
			'an entry added' => ['add entry', "Entry 'IP Address: 192.0.2.0/24' created in Map 'IP Blacklist'"],
			'a custom entry added' => ['add custom entry', "Entry 'URL: bad.example' created in Map 'Phishing URLs'"],
			'an entry updated' => ['edit entry', "Entry 'Mail From: new@example.net, RCPT To: alice@example.org' updated in Map 'Mail From/RCPT_TO Whitelist'"],
			'a custom entry updated' => ['edit custom entry', "Entry 'URL: worse.example' updated in Map 'Phishing URLs'"],
			'an entry deleted' => ['del', "Map entry 'Mail From: friend@example.net, RCPT To: alice@example.org' deleted from Map 'Mail From/RCPT_TO Whitelist'"],
			'all entries deleted' => ['delall', "All entries from Map 'Mail From/RCPT_TO Whitelist' deleted"],
			'an entry toggled' => ['toggle', "Map entry 'Mail From: friend@example.net, RCPT To: alice@example.org' disabled in Map 'Mail From/RCPT_TO Whitelist'"],
		];
	}

	/**
	 * One of the nine changes of savedChanges(), by an admin.
	 * @return array{Response, Request}
	 */
	private function change(string $change): array {
		return match ($change) {
			'add config' => $this->postConfig(['map_name' => 'senders']),
			'edit config' => $this->postConfig(['map_name' => 'phishing'], 1),
			'add entry' => $this->postEntry('ip_blacklist', 'map_ip_form', ['ip' => '192.0.2.0/24']),
			'add custom entry' => $this->postEntry('phish', 'map_with_custom_field_form', ['url' => 'bad.example']),
			'edit entry' => $this->postEntry('mail_from_rcpt_to_whitelist', 'map_mail_from_rcpt_to_form', ['mail_from' => 'new@example.net', 'rcpt_to' => 'alice@example.org'], self::ADMIN, [], 5),
			'edit custom entry' => $this->postEntry('phish', 'map_with_custom_field_form', ['url' => 'worse.example'], self::ADMIN, [], 9),
			default => $this->entryAction($change),
		};
	}

	/*
	 A change whose map file cannot be written (no MAP_DIR): it is saved, so
	 it is a success, back to where a success goes, with a warning that the
	 file is behind.
	*/
	#[DataProvider('savedChanges')]
	public function testSavedWithItsMapFileBehind(string $change, string $success): void {
		Config::set('MAP_DIR', "{$this->dir}/none");
		[$response, $request] = $this->change($change);
		$this->assertSame(['success' => [$success], 'warning' => [self::FILE_BEHIND]], self::flashes($request));
		$this->assertRedirect(match ($change) {
			'add config', 'edit config' => $this->url(RouteName::ADMIN_MAPS_CUSTOM_SHOW),
			'add entry' => $this->url(RouteName::ADMIN_MAP_SHOW, ['map' => 'ip_blacklist']),
			'add custom entry', 'edit custom entry' => $this->url(RouteName::ADMIN_MAP_SHOW, ['map' => 'phish']),
			default => $this->url(RouteName::ADMIN_MAP_SHOW, ['map' => 'mail_from_rcpt_to_whitelist']),
		}, $response);
	}

	/** @return array<string, array{string, string, string, int}> the change, its failure message, what is logged, the statements undone */
	public static function changesNotLogged(): array {
		return [
			'a custom map added' => ['add config', "Custom map 'senders' creation problem. Check logs.", '[addCustomMapConfig] Query insert error', 1],
			'a custom map renamed' => ['edit config', "Custom map 'phishing' update problem. Check logs.", '[updateCustomMapConfig] Query update error', 3],
			'an entry added' => ['add entry', "Entry 'IP Address: 192.0.2.0/24' creation in Map IP Blacklist failed", '[addMapCombinedEntry] Query save error', 1],
			'a custom entry added' => ['add custom entry', "Entry 'URL: bad.example' creation in Map Phishing URLs failed", '[addMapCustomEntry] Query insert error', 1],
			'an entry updated' => ['edit entry', "Entry 'Mail From: new@example.net, RCPT To: alice@example.org' update in Map Mail From/RCPT_TO Whitelist failed", '[updateMapCombinedEntry] Query save error', 1],
			'a custom entry updated' => ['edit custom entry', "Entry 'URL: worse.example' update in Map Phishing URLs failed", '[updateMapCustomEntry] Query update error', 1],
			'an entry deleted' => ['del', 'Map entry Mail From: friend@example.net, RCPT To: alice@example.org failed to be deleted from Map Mail From/RCPT_TO Whitelist', '[delMapEntry] Query error', 1],
			'all entries deleted' => ['delall', 'All entries failed to be deleted from Map Mail From/RCPT_TO Whitelist', '[delMapAllEntries] Query error', 1],
			'an entry toggled' => ['toggle', 'Map entry Mail From: friend@example.net, RCPT To: alice@example.org failed to be disabled in Map Mail From/RCPT_TO Whitelist', '[toggleMapEntry] Query error', 1],
		];
	}

	/*
	 A change whose activity log cannot be written: the change is undone
	 with it (one transaction), so it is a failure, logged, and no map file
	 is written.
	*/
	#[DataProvider('changesNotLogged')]
	public function testUndoneWithoutItsActivityLog(string $change, string $error, string $logged, int $undone): void {
		$this->db->failInsertInto['map_activity_logs'] = new RuntimeException('gone away');
		[, $request] = $this->change($change);
		$this->assertSame(['error' => [$error]], self::flashes($request));
		$this->assertSame([$undone], $this->db->rolledBack);
		$this->assertContains("error {$logged}: gone away", array_map(trim(...), $this->log->lines));
		$this->assertSame([], glob("{$this->dir}/*"));
	}

	// a change that is saved rolls nothing back
	#[DataProvider('savedChanges')]
	public function testSavedWithItsActivityLog(string $change, string $success): void {
		[, $request] = $this->change($change);
		$this->assertSame(['success' => [$success]], self::flashes($request));
		$this->assertSame([], $this->db->rolledBack);
		$this->assertCount(1, $this->db->rows('map_activity_logs'));
		$this->assertCount(1, (array) glob("{$this->dir}/*"));
	}

	// a user is told without the admin's details
	public function testUserSavedWithItsMapFileBehind(): void {
		Config::set('MAP_DIR', "{$this->dir}/none");
		[, $request] = $this->entryAction('toggle', 'mail_from_rcpt_to_whitelist', self::USER);
		$this->assertSame(['The change is saved, but is not in effect yet.'], self::flashes($request)['warning'] ?? null);
	}

	public function testEditConfigForm(): void {
		$html = $this->html($this->maps(self::request('/admin/maps/custom/1/edit', self::ADMIN))->editCustomMap(1));
		$this->assertStringContainsString('value="phish"', $html);
		$this->assertStringContainsString('name="custom_map_config_form[symbols]"', $html);
	}

	public function testEditConfig(): void {
		[$response, $request] = $this->postConfig(['map_name' => 'phishing'], 1);
		$this->assertRedirect($this->url(RouteName::ADMIN_MAPS_CUSTOM_SHOW), $response);
		$this->assertSame(['success' => ["Custom Map 'phishing' updated"]], self::flashes($request));
		$this->assertContains("info Custom map 'phishing' updated by 'root@example.org'", $this->log->lines);
	}

	public function testEditConfigToExisting(): void {
		$this->answers[0] = ['select exists(', [['exists' => 1]]];
		[$response, $request] = $this->postConfig(['map_name' => 'ip_blacklist'], 1);
		$this->assertRedirect($this->url(RouteName::ADMIN_MAPS_CUSTOM_EDIT, ['id' => 1]), $response);
		$this->assertSame(['error' => ["Map 'ip_blacklist' already exists!"]], self::flashes($request));
	}

	public function testEditConfigReservedName(): void {
		[$response, $request] = $this->postConfig(['map_name' => 'manage_custom_maps'], 1);
		$this->assertRedirect($this->url(RouteName::ADMIN_MAPS_CUSTOM_EDIT, ['id' => 1]), $response);
		$this->assertSame(['error' => ["Map name 'manage_custom_maps' is not allowed!"]], self::flashes($request));
		$this->assertSame([], $this->db->changes);
	}

	public function testEditConfigFails(): void {
		$this->db->affect = fn (): never => throw new RuntimeException('gone away');
		[$response, $request] = $this->postConfig(['map_name' => 'phish'], 1);
		$this->assertRedirect($this->url(RouteName::ADMIN_MAPS_CUSTOM_EDIT, ['id' => 1]), $response);
		$this->assertSame(['error' => ["Custom map 'phish' update problem. Check logs."]], self::flashes($request));
	}

	/** @return array<string, array{int, string}> */
	public static function configsNotEdited(): array {
		return [
			'not found' => [8, 'Custom map not found!'],
			'the virus skip map' => [2, "Custom map 'virus_skip' is the virus skip map and cannot be edited"],
			'no id' => [0, 'Invalid map id'],
		];
	}

	#[DataProvider('configsNotEdited')]
	public function testConfigNotEdited(int $id, string $error): void {
		$request = self::request('/admin/maps/custom/edit', self::ADMIN);
		$this->assertRedirect($this->url(RouteName::ADMIN_MAPS_CUSTOM_SHOW), $this->maps($request)->editCustomMap($id));
		$this->assertSame(['error' => [$error]], self::flashes($request));
	}

	/** @return array{Response, Request} */
	private function delConfig(int $id, ?string $token = null): array {
		$request = self::request('/admin/maps/custom/del', self::ADMIN);
		$request->query->set('_token', $token ?? self::csrf($request, 'custom_map_del'));
		return [$this->maps($request)->delCustomMap($id), $request];
	}

	public function testDelConfig(): void {
		[$response, $request] = $this->delConfig(1);
		$this->assertRedirect($this->url(RouteName::ADMIN_MAPS_CUSTOM_SHOW), $response);
		$this->assertSame(['success' => ["Custom Map 'phish' deleted."]], self::flashes($request));
	}

	/** @return array<string, array{int, string|null, string}> */
	public static function configsNotDeleted(): array {
		return [
			'not found' => [8, null, 'Custom map not found!'],
			'the virus skip map' => [2, null, "Custom map 'virus_skip' is the virus skip map and cannot be deleted"],
			'a forged token' => [1, 'forged', 'Invalid or expired request. Please try again.'],
		];
	}

	#[DataProvider('configsNotDeleted')]
	public function testConfigNotDeleted(int $id, ?string $token, string $error): void {
		[$response, $request] = $this->delConfig($id, $token);
		$this->assertRedirect($this->url(RouteName::ADMIN_MAPS_CUSTOM_SHOW), $response);
		$this->assertSame(['error' => [$error]], self::flashes($request));
		$this->assertSame([], $this->db->changes);
		$this->assertSame($token === null ? [] : ['warning CSRF check failed on delCustomMap from 192.0.2.7'], $this->log->lines);
	}

	public function testMap(): void {
		$request = self::request($this->url(RouteName::ADMIN_MAP_SHOW, ['map' => 'mail_from_rcpt_to_whitelist']), self::ADMIN);
		$this->framed($request);
		$html = $this->html($this->maps($request)->showMap('mail_from_rcpt_to_whitelist'));
		$this->assertFramed($html, 'Maps');
		$this->assertStringContainsString('friend@example.net', $html);
		$this->assertStringContainsString('Mail From/RCPT_TO Whitelist', $html);
		$this->assertStringContainsString('"http://rq.example.org/maps/mail_from_rcpt_to_whitelist.txt"', $html);
		foreach (['name="map_combined_form[map_name]"', 'name="map_custom_form[map_name]"', '<div class="mapsearchform">', '1 entries', "<th class='email'>Mail From</th>"] as $shown) {
			$this->assertStringContainsString($shown, $html);
		}
		// no ?entry: the whole map, its first page
		$sql = $this->selected('`rcpt_to` from `maps_combined`')[0] ?? '';
		$this->assertStringNotContainsString('`id` = ?', $sql);
		$this->assertStringEndsWith('offset 0', $sql);
	}

	// one entry, as the detail page links it
	public function testMapOneEntry(): void {
		$this->html($this->maps(self::request('/admin/map/mail_from_rcpt_to_whitelist', self::ADMIN, ['entry' => '5']))->showMap('mail_from_rcpt_to_whitelist'));
		$this->assertContains(5, $this->selected('`rcpt_to` from `maps_combined`')[1] ?? []);
	}

	// the map's last change, as the activity log keeps it
	public function testMapLastChange(): void {
		$this->answers = array_merge([['from `map_activity_logs`', [['map_name' => 'mail_from_rcpt_to_whitelist', 'table_name' => 'maps_combined', 'last_changed_at' => '2026-11-20 09:00:00']]]], $this->answers);
		$html = $this->html($this->maps(self::request('/admin/map/mail_from_rcpt_to_whitelist', self::ADMIN))->showMap('mail_from_rcpt_to_whitelist'));
		$this->assertStringContainsString('| Updated 2026-11-20 09:00:00', $html);
	}

	// idle entries (no hit in the window): kept in the page links
	public function testMapIdlePages(): void {
		Config::set('items_per_page', 1);
		$this->entries = [
			['id' => 6, 'map_name' => 'ip_blacklist', 'ip' => '192.0.2.1', 'mail_from' => null, 'rcpt_to' => null, 'mime_from' => null, 'user_id' => 7, 'disabled' => 0, 'created_at' => null, 'updated_at' => null],
			['id' => 7, 'map_name' => 'ip_blacklist', 'ip' => '192.0.2.2', 'mail_from' => null, 'rcpt_to' => null, 'mime_from' => null, 'user_id' => 7, 'disabled' => 0, 'created_at' => null, 'updated_at' => null],
		];
		$html = $this->html($this->maps(self::request('/admin/map/ip_blacklist', self::ADMIN, ['idle' => '1']))->showMap('ip_blacklist'));
		$this->assertStringContainsString('idle=1&amp;page=2', $html);
		$this->assertStringContainsString('Displaying 1/page', $html);
	}

	// the hits column without its counts when their query fails, logged
	public function testMapHitsQueryError(): void {
		$this->answers = array_merge([['entry_id, COUNT(DISTINCT mail_log_id) AS hits', function (): array {
			throw new RuntimeException('gone away');
		}]], $this->answers);
		$this->html($this->maps(self::request('/admin/map/mail_from_rcpt_to_whitelist', self::ADMIN))->showMap('mail_from_rcpt_to_whitelist'));
		$this->assertSame(['error [MapController] map hits query error: gone away'], $this->log->lines);
	}

	/** @return array<string, array{int, bool, bool}> the entry's creator, USER_CAN_DEL_ADMIN_MAP_ENTRIES, a delete link */
	public static function userDeletes(): array {
		return [
			'own entry' => [7, false, true],
			"another's entry" => [1, false, false],
			"another's entry, allowed" => [1, true, true],
			"a deleted user's entry" => [99, false, false],
		];
	}

	// a user deletes own entries, others' only when USER_CAN_DEL_ADMIN_MAP_ENTRIES allows
	#[DataProvider('userDeletes')]
	public function testUserDeleteLink(int $creator, bool $allowed, bool $link): void {
		Config::set('USER_CAN_DEL_ADMIN_MAP_ENTRIES', $allowed);
		$this->entries[0]['user_id'] = $creator;
		$this->answers = array_merge([['from `users` where `users`.`id` in', [['id' => 7, 'username' => 'alice'], ['id' => 1, 'username' => 'root']]]], $this->answers);
		$html = $this->html($this->maps(self::request('/map/mail_from_rcpt_to_whitelist', self::USER))->showMap('mail_from_rcpt_to_whitelist'));
		$this->assertSame($link, str_contains($html, 'href="' . $this->url(RouteName::MAP_DEL_ENTRY, ['map' => 'mail_from_rcpt_to_whitelist', 'id' => 5])));
		$this->assertStringContainsString("<td class='center'>" . [7 => 'alice', 1 => 'root', 99 => 'Deleted user'][$creator] . '</td>', $html);
	}

	/** @return array<string, array{string|null, string}> $_SERVER['HTTPS'], the scheme of the map files' links */
	public static function schemes(): array {
		return [
			'on' => ['on', 'https'],
			'one' => ['1', 'https'],
			'off' => ['off', 'http'],
			'zero' => ['0', 'http'],
			'empty' => ['', 'http'],
		];
	}

	#[DataProvider('schemes')]
	public function testMapFileLinkScheme(?string $https, string $scheme): void {
		$_SERVER['HTTPS'] = $https;
		try {
			$html = $this->html($this->maps(self::request('/admin/map/ip_blacklist', self::ADMIN))->showMap('ip_blacklist'));
		} finally {
			unset($_SERVER['HTTPS']);
		}
		$this->assertStringContainsString("\"{$scheme}://rq.example.org/maps/ip_blacklist.txt\"", $html);
	}

	public function testCustomMap(): void {
		$html = $this->html($this->maps(self::request('/admin/map/phish', self::ADMIN))->showMap('phish'));
		$this->assertStringContainsString('name="map_search_form[model]" value="MapCustom"', $html);
		$this->assertStringContainsString('evil.example', $html);
		$this->assertStringContainsString('URL', $html);
	}

	public function testManageCustomMaps(): void {
		$this->assertRedirect($this->url(RouteName::ADMIN_MAPS_CUSTOM_SHOW), $this->maps(self::request('/', self::ADMIN))->showMap('manage_custom_maps'));
	}

	/** @return array<string, array{array<string, mixed>, string, string}> */
	public static function mapsNotShown(): array {
		return [
			'unknown' => [self::ADMIN, 'nosuch', 'Invalid map selected'],
			"a custom map, for a user" => [self::USER, 'phish', 'Invalid map selected'],
			'an admin map, for a user' => [self::USER, 'ip_blacklist', 'Invalid map selected'],
			'none' => [self::ADMIN, '', 'No map selected'],
		];
	}

	/** @param array<string, mixed> $session */
	#[DataProvider('mapsNotShown')]
	public function testMapNotShown(array $session, string $map, string $error): void {
		$request = self::request('/', $session);
		$this->assertRedirect($this->url($session === self::USER ? RouteName::MAPS : RouteName::ADMIN_MAPS), $this->maps($request)->showMap($map));
		$this->assertSame(['error' => [$error]], self::flashes($request));
	}

	public function testAddEntryForm(): void {
		$request = self::request('/admin/map/mail_from_rcpt_to_whitelist/add', self::ADMIN, ['mail_from' => 'new@example.net', 'rcpt_to' => ['x']]);
		$html = $this->html($this->maps($request)->addMapEntry('mail_from_rcpt_to_whitelist'));
		$this->assertStringContainsString('value="new@example.net"', $html);
	}

	/**
	 * @param array<string, mixed> $fields
	 * @param array<string, mixed> $session
	 * @param array<string, string> $query
	 * @return array{Response, Request}
	 */
	private function postEntry(string $map, string $form, array $fields, array $session = self::ADMIN, array $query = [], int $id = 0): array {
		$request = self::request('/map', $session, $query);
		$request->setMethod('POST');
		$request->request->replace([$form => $fields + ['_token' => self::csrf($request, $form)]]);
		$maps = $this->maps($request);
		return [$id === 0 ? $maps->addMapEntry($map) : $maps->editMapEntry($map, $id), $request];
	}

	public function testAddEntry(): void {
		[$response, $request] = $this->postEntry('ip_blacklist', 'map_ip_form', ['ip' => '192.0.2.0/24']);
		$this->assertRedirect($this->url(RouteName::ADMIN_MAP_SHOW, ['map' => 'ip_blacklist']), $response);
		$this->assertSame(['success' => ["Entry 'IP Address: 192.0.2.0/24' created in Map 'IP Blacklist'"]], self::flashes($request));
		$this->assertSame('192.0.2.0/24', $this->db->rows('maps_combined')[0]['ip'] ?? null);
	}

	public function testAddEntryBackToMail(): void {
		[$response] = $this->postEntry('ip_blacklist', 'map_ip_form', ['ip' => '192.0.2.1'], self::ADMIN, ['return-mail' => '41']);
		$this->assertRedirect($this->url(RouteName::ADMIN_DETAIL, ['type' => 'id', 'value' => 41]), $response);
	}

	// the smallest mail id is one
	public function testAddEntryBackToMailOne(): void {
		[$response] = $this->postEntry('ip_blacklist', 'map_ip_form', ['ip' => '192.0.2.1'], self::ADMIN, ['return-mail' => '1']);
		$this->assertRedirect($this->url(RouteName::ADMIN_DETAIL, ['type' => 'id', 'value' => 1]), $response);
	}

	public function testAddEntryExistingBackToMail(): void {
		$this->answers[0] = ['select exists(', [['exists' => 1]]];
		[$response] = $this->postEntry('ip_blacklist', 'map_ip_form', ['ip' => '192.0.2.1'], self::ADMIN, ['return-mail' => '41']);
		$this->assertRedirect($this->url(RouteName::ADMIN_DETAIL, ['type' => 'id', 'value' => 41]), $response);
	}

	public function testAddCustomEntryBackToMail(): void {
		[$response] = $this->postEntry('phish', 'map_with_custom_field_form', ['url' => 'bad.example'], self::ADMIN, ['return-mail' => '41']);
		$this->assertRedirect($this->url(RouteName::ADMIN_DETAIL, ['type' => 'id', 'value' => 41]), $response);
	}

	public function testUserAddEntryBackToMail(): void {
		[$response] = $this->postEntry('mail_from_rcpt_to_whitelist', 'map_mail_from_rcpt_to_user_form', ['mail_from' => 'new@example.net', 'rcpt_to' => 'alias@example.org'], self::USER, ['return-mail' => '41']);
		$this->assertRedirect($this->url(RouteName::DETAIL, ['type' => 'id', 'value' => 41]), $response);
	}

	public function testUserAddEntryExisting(): void {
		$this->answers[0] = ['select exists(', [['exists' => 1]]];
		[$response] = $this->postEntry('mail_from_rcpt_to_whitelist', 'map_mail_from_rcpt_to_user_form', ['mail_from' => 'new@example.net', 'rcpt_to' => 'alias@example.org'], self::USER);
		$this->assertRedirect($this->url(RouteName::MAP_ADD_ENTRY, ['map' => 'mail_from_rcpt_to_whitelist']), $response);
	}

	public function testUserQuotaBackToMail(): void {
		Config::set('USER_MAP_MAX_ENTRIES', 1);
		$this->answers = array_merge([['select count(*) as aggregate from `maps_combined` where `map_name` = ? and `user_id` = ?', [['aggregate' => 1]]]], $this->answers);
		[$response] = $this->postEntry('mail_from_rcpt_to_whitelist', 'map_mail_from_rcpt_to_user_form', ['mail_from' => 'new@example.net', 'rcpt_to' => 'alias@example.org'], self::USER, ['return-mail' => '41']);
		$this->assertRedirect($this->url(RouteName::DETAIL, ['type' => 'id', 'value' => 41]), $response);
	}

	/** @return array<string, array{string}> */
	public static function badReturns(): array {
		return ['zero' => ['0'], 'not a number' => ['4x'], 'negative' => ['-3']];
	}

	#[DataProvider('badReturns')]
	public function testAddEntryBadReturnIgnored(string $mail): void {
		[$response] = $this->postEntry('ip_blacklist', 'map_ip_form', ['ip' => '192.0.2.1'], self::ADMIN, ['return-mail' => $mail]);
		$this->assertRedirect($this->url(RouteName::ADMIN_MAP_SHOW, ['map' => 'ip_blacklist']), $response);
	}

	public function testAddEntryExisting(): void {
		$this->answers[0] = ['select exists(', [['exists' => 1]]];
		[$response, $request] = $this->postEntry('ip_blacklist', 'map_ip_form', ['ip' => '192.0.2.1']);
		$this->assertRedirect($this->url(RouteName::ADMIN_MAP_ADD_ENTRY, ['map' => 'ip_blacklist']), $response);
		$this->assertSame(['error' => ["Entry 'IP Address: 192.0.2.1' already exists in Map 'IP Blacklist'"]], self::flashes($request));
		$this->assertSame([], $this->db->rows('maps_combined'));
	}

	public function testUserAddEntry(): void {
		[$response, $request] = $this->postEntry('mail_from_rcpt_to_whitelist', 'map_mail_from_rcpt_to_user_form', ['mail_from' => 'new@example.net', 'rcpt_to' => 'alias@example.org'], self::USER);
		$this->assertRedirect($this->url(RouteName::MAP_SHOW, ['map' => 'mail_from_rcpt_to_whitelist']), $response);
		$this->assertSame([7, 'alias@example.org'], [$this->db->rows('maps_combined')[0]['user_id'] ?? null, $this->db->rows('maps_combined')[0]['rcpt_to'] ?? null]);
	}

	public function testUserQuota(): void {
		Config::set('USER_MAP_MAX_ENTRIES', 1);
		$this->answers = array_merge([['select count(*) as aggregate from `maps_combined` where `map_name` = ? and `user_id` = ?', [['aggregate' => 1]]]], $this->answers);
		[$response, $request] = $this->postEntry('mail_from_rcpt_to_whitelist', 'map_mail_from_rcpt_to_user_form', ['mail_from' => 'new@example.net', 'rcpt_to' => 'alias@example.org'], self::USER);
		$this->assertRedirect($this->url(RouteName::MAP_SHOW, ['map' => 'mail_from_rcpt_to_whitelist']), $response);
		$this->assertSame(['error' => ["Map entry limit reached (1) in Map 'Mail From/RCPT_TO Whitelist'"]], self::flashes($request));
		$this->assertSame(["warning User alice reached map entry limit (1) in Map 'mail_from_rcpt_to_whitelist'"], $this->log->lines);
	}

	public function testAddCustomEntry(): void {
		[$response, $request] = $this->postEntry('phish', 'map_with_custom_field_form', ['url' => 'bad.example']);
		$this->assertRedirect($this->url(RouteName::ADMIN_MAP_SHOW, ['map' => 'phish']), $response);
		$this->assertSame(['success' => ["Entry 'URL: bad.example' created in Map 'Phishing URLs'"]], self::flashes($request));
		$this->assertSame('bad.example', $this->db->rows('maps_custom')[0]['pattern'] ?? null);
	}

	// '0' is no map, and 0 no entry
	public function testNoMapSelected(): void {
		$request = self::request('/', self::ADMIN);
		$this->assertRedirect($this->url(RouteName::ADMIN_MAPS), $this->maps($request)->addMapEntry('0'));
		$this->assertSame(['error' => ['No map selected']], self::flashes($request));
		$request = self::request('/', self::ADMIN);
		$this->assertRedirect($this->url(RouteName::ADMIN_MAPS), $this->maps($request)->editMapEntry('0', 5));
		$this->assertSame(['error' => ['No map selected']], self::flashes($request));
		$request = self::request('/', self::ADMIN);
		$this->assertRedirect($this->url(RouteName::ADMIN_MAPS), $this->maps($request)->editMapEntry('ip_blacklist', 0));
		$this->assertSame(['error' => ['Invalid map entry id']], self::flashes($request));
		[$response, $request] = $this->entryAction('delall', '0');
		$this->assertRedirect($this->url(RouteName::ADMIN_MAPS), $response);
		$this->assertSame(['error' => ['Invalid map selected']], self::flashes($request));
	}

	public function testAddEntryFails(): void {
		$this->db->failInsertInto['maps_combined'] = new RuntimeException('gone away');
		[$response, $request] = $this->postEntry('ip_blacklist', 'map_ip_form', ['ip' => '192.0.2.1']);
		$this->assertRedirect($this->url(RouteName::ADMIN_MAP_ADD_ENTRY, ['map' => 'ip_blacklist']), $response);
		$this->assertSame(['error' => ["Entry 'IP Address: 192.0.2.1' creation in Map IP Blacklist failed"]], self::flashes($request));
	}

	public function testAddCustomEntryFails(): void {
		$this->db->failInsertInto['maps_custom'] = new RuntimeException('gone away');
		[$response, $request] = $this->postEntry('phish', 'map_with_custom_field_form', ['url' => 'bad.example']);
		$this->assertRedirect($this->url(RouteName::ADMIN_MAP_ADD_ENTRY, ['map' => 'phish']), $response);
		$this->assertSame(['error' => ["Entry 'URL: bad.example' creation in Map Phishing URLs failed"]], self::flashes($request));
	}

	public function testAddEntryUnknownMap(): void {
		$request = self::request('/', self::USER);
		$this->assertRedirect($this->url(RouteName::MAPS), $this->maps($request)->addMapEntry('ip_blacklist'));
		$this->assertSame(['error' => ['Invalid map selected']], self::flashes($request));
		$this->assertSame(['warning User alice tried to add map entry in / without admin authorization'], $this->log->lines);
	}

	public function testEditEntry(): void {
		$this->entries[0] = ['id' => 6, 'map_name' => 'ip_blacklist', 'ip' => '192.0.2.1', 'mail_from' => null, 'rcpt_to' => null, 'mime_from' => null, 'user_id' => 1, 'disabled' => 0, 'created_at' => null, 'updated_at' => null];
		[$response, $request] = $this->postEntry('ip_blacklist', 'map_ip_form', ['ip' => '192.0.2.2'], self::ADMIN, [], 6);
		$this->assertRedirect($this->url(RouteName::ADMIN_MAP_SHOW, ['map' => 'ip_blacklist']), $response);
		$this->assertSame(['success' => ["Entry 'IP Address: 192.0.2.2' updated in Map 'IP Blacklist'"]], self::flashes($request));
	}

	public function testEditEntryForm(): void {
		$html = $this->html($this->maps(self::request('/', self::ADMIN))->editMapEntry('mail_from_rcpt_to_whitelist', 5));
		$this->assertStringContainsString('value="friend@example.net"', $html);
	}

	public function testEditEntryFails(): void {
		$this->entries[0] = ['id' => 6, 'map_name' => 'ip_blacklist', 'ip' => '192.0.2.1', 'mail_from' => null, 'rcpt_to' => null, 'mime_from' => null, 'user_id' => 1, 'disabled' => 0, 'created_at' => null, 'updated_at' => null];
		$this->db->affect = fn (): never => throw new RuntimeException('gone away');
		[$response, $request] = $this->postEntry('ip_blacklist', 'map_ip_form', ['ip' => '192.0.2.2'], self::ADMIN, [], 6);
		$this->assertRedirect($this->url(RouteName::ADMIN_MAP_EDIT_ENTRY, ['map' => 'ip_blacklist', 'id' => 6]), $response);
		$this->assertSame(['error' => ["Entry 'IP Address: 192.0.2.2' update in Map IP Blacklist failed"]], self::flashes($request));
	}

	// a user's own entry in a map users may use: the user's addresses to choose from
	public function testUserEditEntryForm(): void {
		$html = $this->html($this->maps(self::request('/', self::USER))->editMapEntry('mail_from_rcpt_to_whitelist', 5));
		$this->assertStringContainsString('value="friend@example.net"', $html);
		$this->assertStringContainsString('<option value="alias@example.org"', $html);
	}

	public function testUserEditEntryOtherMap(): void {
		$request = self::request('/', self::USER);
		$this->assertRedirect($this->url(RouteName::MAPS), $this->maps($request)->editMapEntry('ip_blacklist', 5));
		$this->assertSame(['error' => ['Invalid map selected']], self::flashes($request));
		$this->assertSame(['warning User alice tried to edit map entry in / without admin authorization'], $this->log->lines);
	}

	// a user's update that fails: back to the user's edit page
	public function testUserEditEntryFails(): void {
		$this->db->affect = fn (): never => throw new RuntimeException('gone away');
		[$response] = $this->postEntry('mail_from_rcpt_to_whitelist', 'map_mail_from_rcpt_to_user_form', ['mail_from' => 'new@example.net', 'rcpt_to' => 'alias@example.org'], self::USER, [], 5);
		$this->assertRedirect($this->url(RouteName::MAP_EDIT_ENTRY, ['map' => 'mail_from_rcpt_to_whitelist', 'id' => 5]), $response);
	}

	public function testEditCustomEntryForm(): void {
		$html = $this->html($this->maps(self::request('/', self::ADMIN))->editMapEntry('phish', 9));
		$this->assertStringContainsString('value="evil.example"', $html);
	}

	public function testEditCustomEntry(): void {
		[$response, $request] = $this->postEntry('phish', 'map_with_custom_field_form', ['url' => ' worse.example '], self::ADMIN, [], 9);
		$this->assertRedirect($this->url(RouteName::ADMIN_MAP_SHOW, ['map' => 'phish']), $response);
		$this->assertSame(['success' => ["Entry 'URL: worse.example' updated in Map 'Phishing URLs'"]], self::flashes($request));
		$this->assertContains('worse.example', $this->db->changes[0][1] ?? []);
	}

	public function testEditCustomEntryFails(): void {
		$this->db->affect = fn (): never => throw new RuntimeException('gone away');
		[$response, $request] = $this->postEntry('phish', 'map_with_custom_field_form', ['url' => 'worse.example'], self::ADMIN, [], 9);
		$this->assertRedirect($this->url(RouteName::ADMIN_MAP_EDIT_ENTRY, ['map' => 'phish', 'id' => 9]), $response);
		$this->assertSame(['error' => ["Entry 'URL: worse.example' update in Map Phishing URLs failed"]], self::flashes($request));
	}

	/** @return array<string, array{int}> the entry's id, 0 to add one */
	public static function customEntryForms(): array {
		return ['add' => [0], 'edit' => [9]];
	}

	// a custom map deleted between the action reading its config and its form reading its field
	#[DataProvider('customEntryForms')]
	public function testCustomMapDeletedMeanwhile(int $id): void {
		array_unshift($this->answers, ['select `field_name`, `field_label` from `custom_map_config` where `map_name` = ?', []]);
		[$response, $request] = $this->postEntry('phish', 'map_with_custom_field_form', ['url' => 'bad.example'], self::ADMIN, [], $id);
		$this->assertRedirect($this->url(RouteName::ADMIN_MAPS), $response);
		$this->assertSame(['error' => ['Invalid map selected']], self::flashes($request));
		$this->assertSame(["error getCustomField: no custom_map_config row for map 'phish'"], $this->log->lines);
		$this->assertSame([], $this->db->rows('maps_custom'));
		$this->assertSame([], $this->db->changes);
	}

	public function testEditEntryNotFound(): void {
		$this->entries = [];
		$request = self::request('/', self::ADMIN);
		$this->assertRedirect($this->url(RouteName::ADMIN_MAP_SHOW, ['map' => 'ip_blacklist']), $this->maps($request)->editMapEntry('ip_blacklist', 6));
		$this->assertSame(['error' => ['Map entry not found']], self::flashes($request));
	}

	/**
	 * @param array<string, mixed> $session
	 * @return array{Response, Request}
	 */
	private function entryAction(string $action, string $map = 'mail_from_rcpt_to_whitelist', array $session = self::ADMIN, ?string $token = null): array {
		$request = self::request('/', $session);
		$id = ['del' => 'map_del', 'delall' => 'map_delall', 'toggle' => 'map_toggle'][$action];
		$request->query->set('_token', $token ?? self::csrf($request, $id));
		$maps = $this->maps($request);
		$response = match ($action) {
			'del' => $maps->delMapEntry($map, 5),
			'delall' => $maps->delMapAllEntries($map),
			default => $maps->toggleMapEntry($map, 5),
		};
		return [$response, $request];
	}

	public function testDelEntry(): void {
		[$response, $request] = $this->entryAction('del');
		$this->assertRedirect($this->url(RouteName::ADMIN_MAP_SHOW, ['map' => 'mail_from_rcpt_to_whitelist']), $response);
		$this->assertSame(['success' => ["Map entry 'Mail From: friend@example.net, RCPT To: alice@example.org' deleted from Map 'Mail From/RCPT_TO Whitelist'"]], self::flashes($request));
	}

	/**
	 * A custom map entry: deleted or toggled by an admin.
	 * @return array{Response, Request}
	 */
	private function customEntryAction(string $action): array {
		$request = self::request('/', self::ADMIN);
		$request->query->set('_token', self::csrf($request, $action === 'del' ? 'map_del' : 'map_toggle'));
		$maps = $this->maps($request);
		return [$action === 'del' ? $maps->delMapEntry('phish', 9) : $maps->toggleMapEntry('phish', 9), $request];
	}

	public function testDelCustomEntry(): void {
		[$response, $request] = $this->customEntryAction('del');
		$this->assertRedirect($this->url(RouteName::ADMIN_MAP_SHOW, ['map' => 'phish']), $response);
		$this->assertSame(['success' => ["Map entry 'URL: evil.example' deleted from Map 'Phishing URLs'"]], self::flashes($request));
	}

	public function testToggleCustomEntry(): void {
		[$response, $request] = $this->customEntryAction('toggle');
		$this->assertRedirect($this->url(RouteName::ADMIN_MAP_SHOW, ['map' => 'phish']), $response);
		$this->assertSame(['success' => ["Map entry 'URL: evil.example' disabled in Map 'Phishing URLs'"]], self::flashes($request));
	}

	/** @return array<string, array{string, string}> */
	public static function failedEntryActions(): array {
		return [
			'delete' => ['del', "Map entry Mail From: friend@example.net, RCPT To: alice@example.org failed to be deleted from Map Mail From/RCPT_TO Whitelist"],
			'delete all' => ['delall', 'All entries failed to be deleted from Map Mail From/RCPT_TO Whitelist'],
			'toggle' => ['toggle', 'Map entry Mail From: friend@example.net, RCPT To: alice@example.org failed to be disabled in Map Mail From/RCPT_TO Whitelist'],
		];
	}

	// the database refusing the change: logged, said as a failure, no error page
	#[DataProvider('failedEntryActions')]
	public function testEntryActionDatabaseError(string $action, string $error): void {
		$this->db->affect = fn (): never => throw new RuntimeException('gone away');
		[$response, $request] = $this->entryAction($action);
		$this->assertRedirect($this->url(RouteName::ADMIN_MAP_SHOW, ['map' => 'mail_from_rcpt_to_whitelist']), $response);
		$this->assertSame(['error' => [$error]], self::flashes($request));
		$method = ['del' => 'delMapEntry', 'delall' => 'delMapAllEntries', 'toggle' => 'toggleMapEntry'][$action];
		$this->assertSame(["error [{$method}] Query error: gone away"], $this->log->lines);
	}

	// nothing to delete: said so, no failure
	public function testDelAllOfAnEmptyMap(): void {
		$this->db->affect = fn (): int => 0;
		[$response, $request] = $this->entryAction('delall');
		$this->assertRedirect($this->url(RouteName::ADMIN_MAP_SHOW, ['map' => 'mail_from_rcpt_to_whitelist']), $response);
		$this->assertSame(['info' => ["No entries to delete in Map 'Mail From/RCPT_TO Whitelist'"]], self::flashes($request));
		// nothing changed: no activity logged, no map file written
		$this->assertSame([], $this->db->rows('map_activity_logs'));
		$this->assertSame([], glob("{$this->dir}/*"));
	}

	public function testToggleNotFound(): void {
		$this->entries = [];
		[$response, $request] = $this->entryAction('toggle');
		$this->assertRedirect($this->url(RouteName::ADMIN_MAP_SHOW, ['map' => 'mail_from_rcpt_to_whitelist']), $response);
		$this->assertSame(['error' => ['Map entry not found']], self::flashes($request));
	}

	public function testDelEntryNotFound(): void {
		$this->entries = [];
		[$response, $request] = $this->entryAction('del');
		$this->assertSame(['error' => ['Map entry not found']], self::flashes($request));
	}

	public function testDelAll(): void {
		[$response, $request] = $this->entryAction('delall');
		$this->assertRedirect($this->url(RouteName::ADMIN_MAP_SHOW, ['map' => 'mail_from_rcpt_to_whitelist']), $response);
		$this->assertSame(['success' => ["All entries from Map 'Mail From/RCPT_TO Whitelist' deleted"]], self::flashes($request));
	}

	public function testToggle(): void {
		[$response, $request] = $this->entryAction('toggle');
		$this->assertRedirect($this->url(RouteName::ADMIN_MAP_SHOW, ['map' => 'mail_from_rcpt_to_whitelist']), $response);
		$this->assertSame(['success' => ["Map entry 'Mail From: friend@example.net, RCPT To: alice@example.org' disabled in Map 'Mail From/RCPT_TO Whitelist'"]], self::flashes($request));
	}

	public function testToggleDisabled(): void {
		$this->entries[0]['disabled'] = 1;
		[, $request] = $this->entryAction('toggle');
		$this->assertStringContainsString(' enabled in Map ', (string) json_encode(self::flashes($request)));
	}

	/** @return array<string, array{string, string}> */
	public static function entryActions(): array {
		return ['delete' => ['del', 'delMapEntry'], 'delete all' => ['delall', 'delMapAllEntries'], 'toggle' => ['toggle', 'toggleMapEntry']];
	}

	#[DataProvider('entryActions')]
	public function testEntryActionBadToken(string $action, string $method): void {
		[$response, $request] = $this->entryAction($action, 'mail_from_rcpt_to_whitelist', self::ADMIN, 'forged');
		$this->assertRedirect($this->url(RouteName::ADMIN_MAPS), $response);
		$this->assertSame(["warning CSRF check failed on {$method} from 192.0.2.7"], $this->log->lines);
		$this->assertSame(['error' => ['Invalid or expired request. Please try again.']], self::flashes($request));
		$this->assertSame([], $this->db->changes);
	}

	/** @return array<string, array{string, string}> */
	public static function userEntryActions(): array {
		return [
			'delete' => ['del', "Map entry 'Mail From: friend@example.net, RCPT To: alice@example.org' deleted from Map 'Mail From/RCPT_TO Whitelist'"],
			'delete all' => ['delall', "All entries from Map 'Mail From/RCPT_TO Whitelist' deleted"],
			'toggle' => ['toggle', "Map entry 'Mail From: friend@example.net, RCPT To: alice@example.org' disabled in Map 'Mail From/RCPT_TO Whitelist'"],
		];
	}

	// a user's own entries in a map of theirs
	#[DataProvider('userEntryActions')]
	public function testUserEntryAction(string $action, string $success): void {
		[$response, $request] = $this->entryAction($action, 'mail_from_rcpt_to_whitelist', self::USER);
		$this->assertRedirect($this->url(RouteName::MAP_SHOW, ['map' => 'mail_from_rcpt_to_whitelist']), $response);
		$this->assertSame(['success' => [$success]], self::flashes($request));
	}

	public function testDelAllCustom(): void {
		[$response, $request] = $this->entryAction('delall', 'phish');
		$this->assertRedirect($this->url(RouteName::ADMIN_MAP_SHOW, ['map' => 'phish']), $response);
		$this->assertSame(['success' => ["All entries from Map 'Phishing URLs' deleted"]], self::flashes($request));
	}

	#[DataProvider('entryActions')]
	public function testEntryActionOtherMap(string $action, string $method): void {
		[$response, $request] = $this->entryAction($action, 'ip_blacklist', self::USER);
		$this->assertRedirect($this->url(RouteName::MAPS), $response);
		$this->assertSame(['error' => ['Invalid map selected']], self::flashes($request));
		$what = ['del' => 'del map entry', 'delall' => 'del all entries', 'toggle' => 'toggle map entry'][$action];
		$this->assertSame(["warning User alice tried to {$what} in / for an invalid map or without authorization"], $this->log->lines);
		$this->assertSame([], $this->db->changes);
	}

	public function testSearch(): void {
		$request = self::request('/admin/maps/search', self::ADMIN, ['model' => 'MapCombined', 'q' => ' friend ']);
		$html = $this->html($this->maps($request)->searchMapEntry());
		$this->assertStringContainsString('friend@example.net', $html);
		$this->assertContains('%friend%', $this->selected('count(*) as aggregate from `maps_combined`')[1] ?? []);
		foreach (['Basic/Combined Map Entries after search', '<th>Hits (', '<th>Mail From</th>', '">Mail From/RCPT_TO Whitelist</a></td>', "<td class='center'>alice</td>", 'name="map_combined_form[map_name]"', 'name="map_custom_form[map_name]"', '"http://rq.example.org/maps/mail_from_rcpt_to_whitelist.txt"'] as $shown) {
			$this->assertStringContainsString($shown, $html);
		}
		$this->assertStringEndsWith('offset 0', $this->selected('select * from `maps_combined`')[0] ?? '');
		// one page: its size is not said
		$this->assertStringNotContainsString('| Displaying', $html);
	}

	/** @return array<string, array{string}> */
	public static function searchedModels(): array {
		return ['basic and combined' => ['MapCombined'], 'custom' => ['MapCustom']];
	}

	// more results than a page holds: the page size is said
	#[DataProvider('searchedModels')]
	public function testSearchPages(string $model): void {
		Config::set('items_per_page', 1);
		$this->entries[] = ['id' => 6] + $this->entries[0];
		$this->customEntries[] = ['id' => 10] + $this->customEntries[0];
		$html = $this->html($this->maps(self::request('/admin/maps/search', self::ADMIN, ['model' => $model, 'q' => 'e']))->searchMapEntry());
		$this->assertStringContainsString("Total: 2 entries\n| Displaying 1/page", $html);
	}

	public function testSearchCustom(): void {
		$request = self::request('/admin/maps/search', self::ADMIN, ['model' => 'MapCustom', 'q' => 'evil', 'map' => 'phish']);
		$html = $this->html($this->maps($request)->searchMapEntry());
		$this->assertStringContainsString('evil.example', $html);
		foreach (['name="map_search_form[model]" value="MapCustom"', '<th>Hits (', "<td class='center'>URL</td>", '">Phishing URLs</a></td>'] as $shown) {
			$this->assertStringContainsString($shown, $html);
		}
	}

	public function testSearchForm(): void {
		$request = self::request('/admin/maps/search', self::ADMIN);
		$request->setMethod('POST');
		$request->request->replace(['map_search_form' => ['model' => 'MapCombined', 'field' => ' friend ', 'map_name' => 'mail_from_rcpt_to_whitelist']]);
		$this->assertRedirect($this->url(RouteName::ADMIN_MAP_SEARCH_ENTRY) . '?model=MapCombined&q=friend&map=mail_from_rcpt_to_whitelist', $this->maps($request)->searchMapEntry());
	}

	public function testSearchFormNotAValue(): void {
		$request = self::request('/admin/maps/search', self::ADMIN);
		$request->setMethod('POST');
		$request->request->replace(['map_search_form' => ['model' => 'MapCombined', 'field' => ['friend'], 'map_name' => 'mail_from_rcpt_to_whitelist']]);
		$this->expectException(\Symfony\Component\HttpFoundation\Exception\BadRequestException::class);
		$this->expectExceptionMessage('Input value "field" contains a non-scalar value.');
		$this->maps($request)->searchMapEntry();
	}

	/** @return array<string, array{array<string, string>, string}> */
	public static function badMapSearches(): array {
		return [
			'no model' => [['q' => 'x'], 'Search Model empty'],
			'another model' => [['model' => 'User', 'q' => 'x'], 'Wrong search Model'],
			'nothing searched' => [['model' => 'MapCombined', 'q' => ' '], 'Search field empty'],
			'an unknown map' => [['model' => 'MapCombined', 'q' => 'x', 'map' => 'nosuch'], 'Wrong search Map'],
		];
	}

	/** @param array<string, string> $query */
	#[DataProvider('badMapSearches')]
	public function testBadSearch(array $query, string $error): void {
		$request = self::request('/admin/maps/search', self::ADMIN, $query);
		$this->assertRedirect($this->url(RouteName::ADMIN_MAPS), $this->maps($request)->searchMapEntry());
		$this->assertSame(['error' => [$error]], self::flashes($request));
	}

	public function testSearchCustomNothingFound(): void {
		$this->customEntries = [];
		$request = self::request('/admin/maps/search', self::ADMIN, ['model' => 'MapCustom', 'q' => 'x']);
		$this->assertRedirect($this->url(RouteName::ADMIN_MAPS), $this->maps($request)->searchMapEntry());
		$this->assertSame(['info' => ['No map entries exist']], self::flashes($request));
	}

	public function testSearchNothingFound(): void {
		$this->entries = [];
		$request = self::request('/admin/maps/search', self::ADMIN, ['model' => 'MapCombined', 'q' => 'x']);
		$this->assertRedirect($this->url(RouteName::ADMIN_MAPS), $this->maps($request)->searchMapEntry());
		$this->assertSame(['info' => ['No map entries exist']], self::flashes($request));
	}

	public function testSearchUserRefused(): void {
		$request = self::request('/maps/search', self::USER);
		$this->assertRedirect($this->url(RouteName::MAPS), $this->maps($request)->searchMapEntry());
		$this->assertSame(["warning 'alice' tried to search map entries without admin authorization"], $this->log->lines);
		$this->assertSame(['error' => ['Permission denied']], self::flashes($request));
		$this->assertSame([], $this->db->selects);
	}
}
