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
use PHPUnit\Framework\Attributes\DataProvider;
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

	public function testUserPicker(): void {
		$html = $this->html($this->maps(self::request('/maps', self::USER))->showSelectMap());
		$this->assertStringNotContainsString('<option value="phish">', $html);
		$this->assertStringContainsString('<option value="mail_from_rcpt_to_whitelist">', $html);
	}

	public function testAllEntries(): void {
		$html = $this->html($this->maps(self::request('/admin/maps/all', self::ADMIN))->showAllMaps());
		$this->assertStringContainsString('friend@example.net', $html);
		$this->assertStringContainsString('Mail From/RCPT_TO Whitelist', $html);
		$this->assertStringContainsString('alice', $html);
	}

	public function testAllCustomEntries(): void {
		$html = $this->html($this->maps(self::request('/admin/maps/all/MapCustom', self::ADMIN))->showAllMaps('MapCustom'));
		$this->assertStringContainsString('evil.example', $html);
		$this->assertStringContainsString('Phishing URLs', $html);
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
	}

	public function testAddConfig(): void {
		[$response, $request] = $this->postConfig(['map_name' => 'senders']);
		$this->assertRedirect($this->url(RouteName::ADMIN_MAPS_CUSTOM_SHOW), $response);
		$this->assertSame(['success' => ["Custom Map 'senders' created"]], self::flashes($request));
		$this->assertContains("info Custom map 'senders' created by 'root@example.org'", $this->log->lines);
		$this->assertSame('senders', $this->db->rows('custom_map_config')[0]['map_name'] ?? null);
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

	// saved, but the map file cannot be written
	public function testAddConfigFails(): void {
		Config::set('MAP_DIR', "{$this->dir}/gone");
		[$response, $request] = $this->postConfig(['map_name' => 'senders']);
		$this->assertRedirect($this->url(RouteName::ADMIN_MAPS_CUSTOM_ADD), $response);
		$this->assertSame(['error' => ["Custom map 'senders' creation problem. Check logs."]], self::flashes($request));
	}

	public function testEditConfigForm(): void {
		$html = $this->html($this->maps(self::request('/admin/maps/custom/1/edit', self::ADMIN))->editCustomMap(1));
		$this->assertStringContainsString('value="phish"', $html);
	}

	public function testEditConfig(): void {
		[$response, $request] = $this->postConfig(['map_name' => 'phishing'], 1);
		$this->assertRedirect($this->url(RouteName::ADMIN_MAPS_CUSTOM_SHOW), $response);
		$this->assertSame(['success' => ["Custom Map 'phishing' updated"]], self::flashes($request));
	}

	public function testEditConfigToExisting(): void {
		$this->answers[0] = ['select exists(', [['exists' => 1]]];
		[$response, $request] = $this->postConfig(['map_name' => 'ip_blacklist'], 1);
		$this->assertRedirect($this->url(RouteName::ADMIN_MAPS_CUSTOM_EDIT, ['id' => 1]), $response);
		$this->assertSame(['error' => ["Map 'ip_blacklist' already exists!"]], self::flashes($request));
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
	}

	public function testMap(): void {
		$request = self::request($this->url(RouteName::ADMIN_MAP_SHOW, ['map' => 'mail_from_rcpt_to_whitelist']), self::ADMIN);
		$this->framed($request);
		$html = $this->html($this->maps($request)->showMap('mail_from_rcpt_to_whitelist'));
		$this->assertFramed($html, 'Maps');
		$this->assertStringContainsString('friend@example.net', $html);
		$this->assertStringContainsString('Mail From/RCPT_TO Whitelist', $html);
		$this->assertStringContainsString('"http://rq.example.org/maps/mail_from_rcpt_to_whitelist.txt"', $html);
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

	public function testAddEntryUnknownMap(): void {
		$request = self::request('/', self::USER);
		$this->assertRedirect($this->url(RouteName::MAPS), $this->maps($request)->addMapEntry('ip_blacklist'));
		$this->assertSame(['error' => ['Invalid map selected']], self::flashes($request));
		$this->assertStringStartsWith('warning User alice tried to add map entry in ', $this->log->lines[0] ?? '');
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
		$this->assertSame([], $this->db->changes);
	}

	#[DataProvider('entryActions')]
	public function testEntryActionOtherMap(string $action, string $method): void {
		[$response, $request] = $this->entryAction($action, 'ip_blacklist', self::USER);
		$this->assertRedirect($this->url(RouteName::MAPS), $response);
		$this->assertSame(['error' => ['Invalid map selected']], self::flashes($request));
		$this->assertSame([], $this->db->changes);
	}

	public function testSearch(): void {
		$request = self::request('/admin/maps/search', self::ADMIN, ['model' => 'MapCombined', 'q' => ' friend ']);
		$html = $this->html($this->maps($request)->searchMapEntry());
		$this->assertStringContainsString('friend@example.net', $html);
		$this->assertContains('%friend%', $this->selected('count(*) as aggregate from `maps_combined`')[1] ?? []);
	}

	public function testSearchCustom(): void {
		$request = self::request('/admin/maps/search', self::ADMIN, ['model' => 'MapCustom', 'q' => 'evil', 'map' => 'phish']);
		$this->assertStringContainsString('evil.example', $this->html($this->maps($request)->searchMapEntry()));
	}

	public function testSearchForm(): void {
		$request = self::request('/admin/maps/search', self::ADMIN);
		$request->setMethod('POST');
		$request->request->replace(['map_search_form' => ['model' => 'MapCombined', 'field' => ' friend ', 'map_name' => 'mail_from_rcpt_to_whitelist']]);
		$this->assertRedirect($this->url(RouteName::ADMIN_MAP_SEARCH_ENTRY) . '?model=MapCombined&q=friend&map=mail_from_rcpt_to_whitelist', $this->maps($request)->searchMapEntry());
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
	}
}
