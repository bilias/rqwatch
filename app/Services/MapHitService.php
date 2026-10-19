<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Services;

use App\Core\App;

use App\Configuration\AppConfig;

use App\Utils\Helper;

use App\Inventory\MapInventory;

use Psr\Log\LoggerInterface;

use Illuminate\Database\Eloquent\Builder;

use Throwable;

class MapHitService
{
	private LoggerInterface $logger;

	public function __construct() {
		$this->logger = App::fileLogger();
	}

	// entry_model values
	private const int ENTRY_NONE = 0;
	public const int ENTRY_COMBINED = 1;
	public const int ENTRY_CUSTOM = 2;

	/*
	 * Record the map and local rule symbols of an imported mail, with their
	 * scores and the map entries that produced them. $symbols is the mail's
	 * symbols JSON. Never throws: a failure here must not cost the import.
	 */
	public function recordHits(int $mailLogId, string $symbols, string $qid): void {
		try {
			if (!MapService::mapHitsEnabled()) {
				return;
			}

			// symbol => maps naming it
			$symbolMaps = [];
			foreach (MapService::mapSymbols() as $map => $names) {
				foreach ($names as $name) {
					$symbolMaps[$name][] = $map;
				}
			}

			$wanted = $this->wantedSymbols($symbols, $symbolMaps);
			if ($wanted === []) {
				return;
			}

			$ids = $this->symbolIds(array_keys($wanted));

			$db = App::capsule()->getConnection();
			// the mail's own day, so a spool replay lands on its arrival day
			$day = $db->table(AppConfig::MAIL_LOGS_TABLE)->where('id', $mailLogId)->value('created_day');
			if ($day === null) {
				$this->logger->error("[MapHitService_recordHits] {$qid} mail id {$mailLogId} not found");
				return;
			}

			$rows = [];
			foreach ($wanted as $name => $symbol) {
				if (!isset($ids[$name])) {
					continue;
				}
				$entries = isset($symbolMaps[$name])
					? $this->optionEntries($symbolMaps[$name], $symbol['options'])
					: $this->ownSymbolEntries($name);
				if ($entries === []) {
					$entries = [[self::ENTRY_NONE, 0]];
				}
				foreach ($entries as [$model, $entryId]) {
					$rows[] = [
						'symbol_id' => $ids[$name],
						'created_day' => $day,
						'mail_log_id' => $mailLogId,
						'entry_model' => $model,
						'entry_id' => $entryId,
						'score' => $symbol['score'],
					];
				}
			}

			if ($rows !== []) {
				$db->table(AppConfig::MAIL_LOG_SYMBOL_HITS_TABLE)->insertOrIgnore($rows);
			}
		} catch (Throwable $e) {
			$this->logger->error("[MapHitService_recordHits] {$qid} could not record map hits: " . $e->getMessage());
		}
	}

	// symbol name => [score, options] for the local rule symbols and those a map names
	private function wantedSymbols(string $symbols, array $symbolMaps): array {
		$list = json_decode($symbols, true);
		if (!is_array($list) || $list === []) {
			return [];
		}

		$wanted = [];
		foreach ($list as $key => $symbol) {
			if (!is_array($symbol)) {
				continue;
			}
			$name = $symbol['name'] ?? (is_string($key) ? $key : null);
			// fuzzy symbols have their own hits (FuzzyService::recordHits())
			if (!is_string($name) || !MapService::validSymbol($name) || Helper::isFuzzySymbol($name)) {
				continue;
			}
			if (!isset($symbolMaps[$name]) && !Helper::symbolMatches($name, 'local_symbol_patterns')) {
				continue;
			}
			$score = $symbol['score'] ?? null;
			$wanted[$name] = [
				'score' => is_numeric($score) ? (float) $score : null,
				'options' => array_values(array_filter((array) ($symbol['options'] ?? []), 'is_scalar')),
			];
		}

		return $wanted;
	}

	/*
	 * Entries of $maps whose key is one of the symbol's options, as
	 * [[entry_model, id], ...]. Options are the looked-up values: multimap
	 * exact maps and our combined-map Lua give the key itself, the whitelist
	 * module "key:<check>:<+|->". An IP option also matches the longest
	 * CIDR entry, as rspamd's radix maps do. Enabled entries only.
	 */
	private function optionEntries(array $maps, array $options): array {
		$builtin = MapInventory::getMapConfigs(null, false);

		$entries = [];
		foreach ($maps as $map) {
			foreach ($options as $option) {
				$option = trim((string) $option);
				if ($option === '') {
					continue;
				}
				$keys = [$option];
				if (preg_match('/^(.+):[A-Za-z]:[+-]$/', $option, $m)) {
					$keys[] = $m[1];
				}

				$found = isset($builtin[$map])
					? $this->combinedEntries($map, $builtin[$map]['fields'], $keys)
					: $this->customEntries($map, $keys);

				foreach ($found as $entry) {
					$entries[$entry[0] . ':' . $entry[1]] = $entry;
				}
			}
		}

		return array_values($entries);
	}

	private function combinedEntries(string $map, array $fields, array $keys): array {
		$table = App::capsule()->getConnection()->table(AppConfig::MAPS_COMBINED_TABLE);

		foreach ($keys as $key) {
			$parts = explode('|', $key);
			if (count($parts) !== count($fields)) {
				continue;
			}
			$query = (clone $table)->where('map_name', $map)->where('disabled', 0);
			foreach ($fields as $i => $field) {
				$query->where($field, $parts[$i]);
			}
			$ids = $query->pluck('id')->all();
			if ($ids !== []) {
				return array_map(fn ($id) => [self::ENTRY_COMBINED, (int) $id], $ids);
			}
		}

		if ($fields === ['ip'] && filter_var($keys[0], FILTER_VALIDATE_IP) !== false) {
			$networks = (clone $table)->where('map_name', $map)->where('disabled', 0)
				->where('ip', 'like', '%/%')->pluck('ip', 'id')->all();
			return $this->longestPrefix($keys[0], $networks, self::ENTRY_COMBINED);
		}

		return [];
	}

	// a custom entry's key is its pattern up to the first whitespace
	private function customEntries(string $map, array $keys): array {
		$table = App::capsule()->getConnection()->table(AppConfig::MAPS_CUSTOM_TABLE);

		foreach ($keys as $key) {
			$rows = (clone $table)->where('map_name', $map)->where('disabled', 0)
				->where(function ($q) use ($key) {
					$q->where('pattern', $key)
						->orWhere('pattern', 'like', self::escapeLike($key) . ' %');
				})
				->pluck('pattern', 'id')->all();

			$ids = [];
			foreach ($rows as $id => $pattern) {
				if (strcasecmp(self::customIdentity((string) $pattern)[0], $key) === 0) {
					$ids[] = [self::ENTRY_CUSTOM, (int) $id];
				}
			}
			if ($ids !== []) {
				return $ids;
			}
		}

		if (filter_var($keys[0], FILTER_VALIDATE_IP) !== false) {
			$networks = [];
			$rows = (clone $table)->where('map_name', $map)->where('disabled', 0)
				->where('pattern', 'like', '%/%')->pluck('pattern', 'id')->all();
			foreach ($rows as $id => $pattern) {
				$networks[$id] = self::customIdentity((string) $pattern)[0];
			}
			return $this->longestPrefix($keys[0], $networks, self::ENTRY_CUSTOM);
		}

		return [];
	}

	// custom entries carrying $name as their own symbol
	private function ownSymbolEntries(string $name): array {
		$like = self::escapeLike($name);
		$rows = App::capsule()->getConnection()->table(AppConfig::MAPS_CUSTOM_TABLE)
			->where('disabled', 0)
			->where(function ($q) use ($like) {
				$q->where('pattern', 'like', "% {$like}:%")
					->orWhere('pattern', 'like', "% {$like}");
			})
			->pluck('pattern', 'id')->all();

		$entries = [];
		foreach ($rows as $id => $pattern) {
			if (self::customIdentity((string) $pattern)[1] === $name) {
				$entries[] = [self::ENTRY_CUSTOM, (int) $id];
			}
		}

		return $entries;
	}

	// the entries of $networks (id => CIDR) with the longest prefix holding $ip
	private function longestPrefix(string $ip, array $networks, int $model): array {
		$addr = inet_pton($ip);
		$best = -1;
		$ids = [];

		foreach ($networks as $id => $network) {
			[$net, $len] = array_pad(explode('/', (string) $network, 2), 2, null);
			$bin = filter_var($net, FILTER_VALIDATE_IP) !== false ? inet_pton($net) : false;
			if ($bin === false || $addr === false || strlen($bin) !== strlen($addr)
				|| !ctype_digit((string) $len) || (int) $len > strlen($bin) * 8) {
				continue;
			}
			$len = (int) $len;
			if ($len < $best || !self::inPrefix($addr, $bin, $len)) {
				continue;
			}
			if ($len > $best) {
				$best = $len;
				$ids = [];
			}
			$ids[] = [$model, (int) $id];
		}

		return $ids;
	}

	private static function inPrefix(string $addr, string $net, int $len): bool {
		$bytes = intdiv($len, 8);
		if (substr($addr, 0, $bytes) !== substr($net, 0, $bytes)) {
			return false;
		}
		$bits = $len % 8;
		if ($bits === 0) {
			return true;
		}
		$mask = (0xFF << (8 - $bits)) & 0xFF;
		return (ord($addr[$bytes]) & $mask) === (ord($net[$bytes]) & $mask);
	}

	/*
	 * A custom entry's key and own symbol, as [key, symbol or null]. The key
	 * of a regexp line ends at its closing /flags, any other at the first
	 * whitespace; the value after it reads "SYMBOL", "SYMBOL:score" or
	 * "SYMBOL:score:options", or is a score.
	 */
	public static function customIdentity(string $pattern): array {
		$pattern = trim($pattern);
		$re = str_starts_with($pattern, '/') ? '~^(/.*/[A-Za-z]*)(?:\s+(\S+))?~s' : '~^(\S+)(?:\s+(\S+))?~';
		if (!preg_match($re, $pattern, $m)) {
			return [$pattern, null];
		}
		$symbol = isset($m[2]) ? explode(':', $m[2], 2)[0] : null;
		if ($symbol === null || is_numeric($symbol) || !MapService::validSymbol($symbol)) {
			$symbol = null;
		}
		return [$m[1], $symbol];
	}

	/*
	 * Detach an entry's hits after its key changed: they stay on the map,
	 * credited to no entry. A hit whose mail already has an uncredited row
	 * for that symbol is deleted instead. Never throws.
	 */
	public function detachEntry(int $model, int $entryId): void {
		try {
			if (!MapService::mapHitsEnabled()) {
				return;
			}

			$db = App::capsule()->getConnection();
			$table = AppConfig::MAIL_LOG_SYMBOL_HITS_TABLE;

			$moved = $db->update(
				"UPDATE IGNORE `{$table}` SET entry_model = ?, entry_id = 0 WHERE entry_model = ? AND entry_id = ?",
				[self::ENTRY_NONE, $model, $entryId]
			);
			$dropped = $db->delete(
				"DELETE FROM `{$table}` WHERE entry_model = ? AND entry_id = ?",
				[$model, $entryId]
			);

			if ($moved + $dropped > 0) {
				$this->logger->info("[MapHitService] entry {$model}:{$entryId} key changed, detached " . ($moved + $dropped) . " hit(s)");
			}
		} catch (Throwable $e) {
			$this->logger->error("[MapHitService_detachEntry] entry {$model}:{$entryId}: " . $e->getMessage());
		}
	}

	private static function escapeLike(string $value): string {
		return addcslashes($value, '\\%_');
	}

	/*
	 * First mail id of the backfill window: the last $days days including
	 * today, or the whole mail log. Null when there is no mail.
	 */
	public function backfillStartId(?int $days): ?int {
		$query = App::capsule()->getConnection()->table(AppConfig::MAIL_LOGS_TABLE);
		self::dayWindow($query, $days);
		$id = $query->min('id');
		return $id === null ? null : (int) $id;
	}

	public function backfillCount(int $startId, ?int $days): int {
		$query = App::capsule()->getConnection()->table(AppConfig::MAIL_LOGS_TABLE)
			->where('id', '>=', $startId);
		self::dayWindow($query, $days);
		return $query->count();
	}

	// the last $days days including today; null: no limit
	private static function dayWindow($query, ?int $days, string $column = 'created_day'): void {
		if ($days !== null) {
			$query->whereRaw("{$column} >= CURDATE() - INTERVAL ? DAY", [$days - 1]);
		}
	}

	/*
	 * Record again the hits of the next $batch mails after $afterId, replacing
	 * the rows they have, so the result reflects the current maps and symbols.
	 * Returns [last mail id, mails], last id null when no mail is left.
	 */
	public function backfillBatch(int $afterId, int $batch, ?int $days): array {
		$db = App::capsule()->getConnection();

		$query = $db->table(AppConfig::MAIL_LOGS_TABLE . ' as ml')
			->join(AppConfig::MAIL_LOG_DATA_TABLE . ' as md', 'md.mail_log_id', '=', 'ml.id')
			->where('ml.id', '>', $afterId)
			->orderBy('ml.id')
			->limit($batch);
		self::dayWindow($query, $days, 'ml.created_day');
		$mails = $query->get(['ml.id', 'ml.qid', 'md.symbols']);

		if ($mails->isEmpty()) {
			return [null, 0];
		}

		$db->table(AppConfig::MAIL_LOG_SYMBOL_HITS_TABLE)
			->whereIn('mail_log_id', $mails->pluck('id')->all())
			->delete();

		foreach ($mails as $mail) {
			$this->recordHits((int) $mail->id, (string) $mail->symbols, (string) $mail->qid);
		}

		return [(int) $mails->last()->id, $mails->count()];
	}

	// entry_model of a map model ('MapCombined', 'MapCustom')
	public static function entryModel(string $model): int {
		return $model === 'MapCustom' ? self::ENTRY_CUSTOM : self::ENTRY_COMBINED;
	}

	/*
	 * Per entry of $ids: distinct mails in the last $days days and the day of
	 * its last hit ever, as id => ['hits' => n, 'last' => day or null].
	 */
	public function entryHits(int $model, array $ids, int $days): array {
		$ids = array_values(array_unique(array_map('intval', $ids)));
		$result = array_fill_keys($ids, ['hits' => 0, 'last' => null]);
		if ($ids === []) {
			return $result;
		}

		$table = App::capsule()->getConnection()->table(AppConfig::MAIL_LOG_SYMBOL_HITS_TABLE);

		$counts = (clone $table)->where('entry_model', $model)->whereIn('entry_id', $ids)
			->whereRaw('created_day >= CURDATE() - INTERVAL ? DAY', [$days - 1])
			->groupBy('entry_id')
			->selectRaw('entry_id, COUNT(DISTINCT mail_log_id) AS hits')
			->pluck('hits', 'entry_id')->all();
		$last = (clone $table)->where('entry_model', $model)->whereIn('entry_id', $ids)
			->groupBy('entry_id')
			->selectRaw('entry_id, MAX(created_day) AS last')
			->pluck('last', 'entry_id')->all();

		foreach ($ids as $id) {
			$result[$id] = ['hits' => (int) ($counts[$id] ?? 0), 'last' => $last[$id] ?? null];
		}
		return $result;
	}

	// mail ids credited to an entry in the last $days days (null: any day), newest first
	public function entryHitMailIds(int $model, int $entryId, ?int $days, int $limit): array {
		$query = App::capsule()->getConnection()->table(AppConfig::MAIL_LOG_SYMBOL_HITS_TABLE)
			->where('entry_model', $model)
			->where('entry_id', $entryId);
		self::dayWindow($query, $days);

		return $query
			->orderBy('mail_log_id', 'DESC')
			->distinct()
			->limit($limit)
			->pluck('mail_log_id')
			->map(fn ($id) => (int) $id)
			->all();
	}

	// limit an entry query on $table to entries with no hit in the last $days days
	public static function whereIdle(Builder $query, int $model, string $table, int $days): void {
		$query->whereNotExists(function ($q) use ($model, $table, $days) {
			$q->selectRaw('1')
				->from(AppConfig::MAIL_LOG_SYMBOL_HITS_TABLE)
				->where('entry_model', $model)
				->whereColumn('entry_id', "{$table}.id")
				->whereRaw('created_day >= CURDATE() - INTERVAL ? DAY', [$days - 1]);
		});
	}

	// one mail's recorded hits, as symbol name => [[entry_model, entry_id], ...]
	public function mailHits(int $mailLogId): array {
		$rows = App::capsule()->getConnection()->table(AppConfig::MAIL_LOG_SYMBOL_HITS_TABLE . ' as h')
			->join(AppConfig::SYMBOL_NAMES_TABLE . ' as sn', 'sn.id', '=', 'h.symbol_id')
			->where('h.mail_log_id', $mailLogId)
			->get(['sn.name', 'h.entry_model', 'h.entry_id']);

		$hits = [];
		foreach ($rows as $row) {
			$hits[$row->name] ??= [];
			if ((int) $row->entry_model !== self::ENTRY_NONE) {
				$hits[$row->name][] = [(int) $row->entry_model, (int) $row->entry_id];
			}
		}
		return $hits;
	}

	// map name of each existing entry, as "model:id" => map name
	public function entryMaps(array $entries): array {
		$ids = [self::ENTRY_COMBINED => [], self::ENTRY_CUSTOM => []];
		foreach ($entries as [$model, $id]) {
			$ids[$model][] = $id;
		}

		$maps = [];
		foreach ([self::ENTRY_COMBINED => AppConfig::MAPS_COMBINED_TABLE, self::ENTRY_CUSTOM => AppConfig::MAPS_CUSTOM_TABLE] as $model => $table) {
			if ($ids[$model] === []) {
				continue;
			}
			$found = App::capsule()->getConnection()->table($table)
				->whereIn('id', array_unique($ids[$model]))->pluck('map_name', 'id')->all();
			foreach ($found as $id => $map) {
				$maps["{$model}:{$id}"] = $map;
			}
		}
		return $maps;
	}

	// report windows in days, today included
	public const array WINDOWS = [1, 7, 31];

	/*
	 * Hits per map, as map name => ['windows' => [days => mails], 'last' => day
	 * or null]: distinct mails in each window and the day of the last hit
	 * ever. A map's hits are those of its symbols and those credited to its
	 * entries (entries carrying their own symbol). $maps is map name =>
	 * [model, symbols].
	 */
	public function mapHits(array $maps): array {
		$ids = $this->existingSymbolIds(array_merge([], ...array_column($maps, 1)));

		$result = [];
		foreach ($maps as $map => [$model, $symbols]) {
			$symbolIds = array_values(array_intersect_key($ids, array_flip($symbols)));
			[$sql, $bindings] = $this->mapHitsSource($map, $model, $symbolIds, max(self::WINDOWS));

			$row = App::capsule()->getConnection()->selectOne(
				"SELECT " . self::windowCounts('t') . " FROM ({$sql}) t", $bindings
			);
			[$lastSql, $lastBindings] = $this->mapHitsSource($map, $model, $symbolIds, null);
			$last = App::capsule()->getConnection()->selectOne(
				"SELECT MAX(t.created_day) AS last FROM ({$lastSql}) t", $lastBindings
			);

			$result[$map] = [
				'windows' => self::windowValues($row),
				'last' => $last->last ?? null,
			];
		}

		return $result;
	}

	/*
	 * Hits of the recorded symbols that are not credited to any map: neither
	 * one of $mappedSymbols nor credited to an entry. Symbol name =>
	 * ['windows' => [days => mails], 'last' => day], those hit in the longest
	 * window only.
	 */
	public function unmappedHits(array $mappedSymbols): array {
		$mappedIds = array_values($this->existingSymbolIds($mappedSymbols));
		$days = max(self::WINDOWS);

		$query = App::capsule()->getConnection()
			->table(AppConfig::MAIL_LOG_SYMBOL_HITS_TABLE . ' as h')
			->join(AppConfig::SYMBOL_NAMES_TABLE . ' as sn', 'sn.id', '=', 'h.symbol_id')
			->where('h.entry_model', self::ENTRY_NONE)
			->whereRaw('h.created_day >= CURDATE() - INTERVAL ? DAY', [$days - 1])
			->groupBy('sn.name')
			->selectRaw('sn.name, ' . self::windowCounts('h') . ', MAX(h.created_day) AS last');
		if ($mappedIds !== []) {
			$query->whereNotIn('h.symbol_id', $mappedIds);
		}

		$result = [];
		foreach ($query->get() as $row) {
			$result[$row->name] = ['windows' => self::windowValues($row), 'last' => $row->last];
		}
		return $result;
	}

	/*
	 * Mail ids hit by a map in the last $days days (null: any day) or on
	 * $day, newest first; $outcome limits them as mapOutcome() counts them:
	 * 'action', 'released' => true, 'notified' => true.
	 */
	public function mapHitMailIds(string $map, string $model, array $symbols, ?int $days, int $limit, array $outcome = [], ?string $day = null): array {
		$symbolIds = array_values($this->existingSymbolIds($symbols));
		[$sql, $bindings] = $this->mapHitsSource($map, $model, $symbolIds, $days, $day);

		$where = [];
		if (isset($outcome['action'])) {
			$where[] = 'ml.action = ?';
			$bindings[] = (string) $outcome['action'];
		}
		if (!empty($outcome['released'])) {
			$where[] = 'ml.released = 1';
		}
		if (!empty($outcome['notified'])) {
			$where[] = 'ml.notified = 1';
		}
		$join = $where === [] ? '' : ' JOIN ' . AppConfig::MAIL_LOGS_TABLE . ' ml ON ml.id = t.mail_log_id WHERE ' . implode(' AND ', $where);

		return array_map('intval', array_column(App::capsule()->getConnection()->select(
			"SELECT DISTINCT t.mail_log_id FROM ({$sql}) t{$join} ORDER BY t.mail_log_id DESC LIMIT " . (int) $limit,
			$bindings
		), 'mail_log_id'));
	}

	/*
	 * A map's distinct mails per day over the last $days days, oldest first,
	 * days without hits included: day (Y-m-d) => mails
	 */
	public function mapDailyHits(string $map, string $model, array $symbols, int $days): array {
		$db = App::capsule()->getConnection();
		$symbolIds = array_values($this->existingSymbolIds($symbols));
		[$sql, $bindings] = $this->mapHitsSource($map, $model, $symbolIds, $days);

		$counts = [];
		foreach ($db->select("SELECT t.created_day AS day, COUNT(DISTINCT t.mail_log_id) AS mails FROM ({$sql}) t GROUP BY t.created_day", $bindings) as $row) {
			$counts[(string) $row->day] = (int) $row->mails;
		}

		// the database's today, as the windows use
		$today = new \DateTimeImmutable((string) $db->selectOne('SELECT CURDATE() AS d')->d);
		$result = [];
		for ($i = $days - 1; $i >= 0; $i--) {
			$d = $today->modify("-{$i} day")->format('Y-m-d');
			$result[$d] = $counts[$d] ?? 0;
		}
		return $result;
	}

	/*
	 * How a map's mails of the last $days days ended up, per action: action
	 * => ['mails' => n, 'released' => n, 'notified' => n], most mails first.
	 */
	public function mapOutcome(string $map, string $model, array $symbols, int $days): array {
		$symbolIds = array_values($this->existingSymbolIds($symbols));
		[$sql, $bindings] = $this->mapHitsSource($map, $model, $symbolIds, $days);

		$rows = App::capsule()->getConnection()->select(
			"SELECT ml.action, COUNT(*) AS mails, SUM(ml.released = 1) AS released, SUM(ml.notified = 1) AS notified"
			. " FROM (SELECT DISTINCT t.mail_log_id FROM ({$sql}) t) x"
			. " JOIN " . AppConfig::MAIL_LOGS_TABLE . " ml ON ml.id = x.mail_log_id"
			. " GROUP BY ml.action ORDER BY mails DESC",
			$bindings
		);

		$outcome = [];
		foreach ($rows as $row) {
			$outcome[(string) $row->action] = [
				'mails' => (int) $row->mails,
				'released' => (int) $row->released,
				'notified' => (int) $row->notified,
			];
		}
		return $outcome;
	}

	// mail ids with an uncredited hit of $symbol in the last $days days (null: any day), newest first
	public function symbolHitMailIds(string $symbol, ?int $days, int $limit): array {
		$id = $this->existingSymbolIds([$symbol])[$symbol] ?? null;
		if ($id === null) {
			return [];
		}

		$query = App::capsule()->getConnection()->table(AppConfig::MAIL_LOG_SYMBOL_HITS_TABLE)
			->where('symbol_id', $id)
			->where('entry_model', self::ENTRY_NONE);
		self::dayWindow($query, $days);

		return $query
			->orderBy('mail_log_id', 'DESC')
			->distinct()
			->limit($limit)
			->pluck('mail_log_id')
			->map(fn ($id) => (int) $id)
			->all();
	}

	/*
	 * SQL selecting (created_day, mail_log_id) of a map's hits, as [sql,
	 * bindings]: hits of its symbols, and hits credited to its entries.
	 * $days null: no window; $day (Y-m-d): that day only, instead of $days.
	 */
	private function mapHitsSource(string $map, string $model, array $symbolIds, ?int $days, ?string $day = null): array {
		$hits = AppConfig::MAIL_LOG_SYMBOL_HITS_TABLE;
		[$entries, $entryModel] = $model === 'MapCustom'
			? [AppConfig::MAPS_CUSTOM_TABLE, self::ENTRY_CUSTOM]
			: [AppConfig::MAPS_COMBINED_TABLE, self::ENTRY_COMBINED];

		if ($day !== null) {
			$window = ' AND h.created_day = ?';
			$windowBinding = [$day];
		} else {
			$window = $days === null ? '' : ' AND h.created_day >= CURDATE() - INTERVAL ? DAY';
			$windowBinding = $days === null ? [] : [$days - 1];
		}

		$parts = [];
		$bindings = [];
		if ($symbolIds !== []) {
			$parts[] = "SELECT h.created_day, h.mail_log_id FROM {$hits} h WHERE h.symbol_id IN ("
				. implode(',', array_fill(0, count($symbolIds), '?')) . "){$window}";
			$bindings = array_merge($bindings, $symbolIds, $windowBinding);
		}
		$parts[] = "SELECT h.created_day, h.mail_log_id FROM {$hits} h JOIN {$entries} e ON e.id = h.entry_id"
			. " WHERE h.entry_model = ? AND e.map_name = ?{$window}";
		$bindings = array_merge($bindings, [$entryModel, $map], $windowBinding);

		return [implode(' UNION ALL ', $parts), $bindings];
	}

	// COUNT(DISTINCT) of mails per window over $alias.created_day
	private static function windowCounts(string $alias): string {
		return implode(', ', array_map(fn ($d) =>
			"COUNT(DISTINCT CASE WHEN {$alias}.created_day >= CURDATE() - INTERVAL " . ($d - 1) .
			" DAY THEN {$alias}.mail_log_id END) AS d{$d}", self::WINDOWS));
	}

	private static function windowValues(?object $row): array {
		$values = [];
		foreach (self::WINDOWS as $d) {
			$values[$d] = (int) ($row->{"d{$d}"} ?? 0);
		}
		return $values;
	}

	// symbol name => symbol_names.id, for the names recorded so far
	private function existingSymbolIds(array $names): array {
		$names = array_values(array_unique($names));
		if ($names === []) {
			return [];
		}
		return array_map('intval', App::capsule()->getConnection()->table(AppConfig::SYMBOL_NAMES_TABLE)
			->whereIn('name', $names)->pluck('id', 'name')->all());
	}

	// symbol name => symbol_names.id, adding the names not seen before
	private function symbolIds(array $names): array {
		$table = App::capsule()->getConnection()->table(AppConfig::SYMBOL_NAMES_TABLE);

		$ids = (clone $table)->whereIn('name', $names)->pluck('id', 'name')->all();

		$missing = array_values(array_diff($names, array_keys($ids)));
		if ($missing !== []) {
			(clone $table)->insertOrIgnore(array_map(fn ($n) => ['name' => $n], $missing));
			$ids += (clone $table)->whereIn('name', $missing)->pluck('id', 'name')->all();
		}

		return array_map('intval', $ids);
	}

}
