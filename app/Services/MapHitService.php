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

use Throwable;

class MapHitService
{
	private LoggerInterface $logger;

	public function __construct() {
		$this->logger = App::fileLogger();
	}

	// entry_model values
	private const int ENTRY_NONE = 0;
	private const int ENTRY_COMBINED = 1;
	private const int ENTRY_CUSTOM = 2;

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
			if (!is_string($name) || !MapService::validSymbol($name)) {
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
				if (strcasecmp(self::customKey((string) $pattern), $key) === 0) {
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
				$networks[$id] = self::customKey((string) $pattern);
			}
			return $this->longestPrefix($keys[0], $networks, self::ENTRY_CUSTOM);
		}

		return [];
	}

	/*
	 * Custom entries carrying $name as their own symbol: the value after the
	 * key reads "SYMBOL", "SYMBOL:score" or "SYMBOL:score:options", the key
	 * of a regexp line ending at its closing /flags.
	 */
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
			$pattern = (string) $pattern;
			$re = str_starts_with($pattern, '/') ? '~^/.*/[A-Za-z]*\s+(\S+)~s' : '~^\S+\s+(\S+)~';
			if (preg_match($re, $pattern, $m) && explode(':', $m[1], 2)[0] === $name) {
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

	private static function customKey(string $pattern): string {
		return (string) strtok(trim($pattern), " \t");
	}

	private static function escapeLike(string $value): string {
		return addcslashes($value, '\\%_');
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
