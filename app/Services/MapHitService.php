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

use Psr\Log\LoggerInterface;

use Throwable;

class MapHitService
{
	// symbols recorded whether or not a map names them, as checkForMap() treats them
	private const array PREFIXES = ['RQWATCH_', 'LOCAL_'];

	private LoggerInterface $logger;

	public function __construct() {
		$this->logger = App::fileLogger();
	}

	/*
	 * Record the map and local rule symbols of an imported mail, with their
	 * scores. $symbols is the mail's symbols JSON. Never throws: a failure
	 * here must not cost the import.
	 */
	public function recordHits(int $mailLogId, string $symbols, string $qid): void {
		try {
			if (!MapService::mapHitsEnabled()) {
				return;
			}

			$scores = $this->wantedSymbols($symbols);
			if ($scores === []) {
				return;
			}

			$ids = $this->symbolIds(array_keys($scores));

			$db = App::capsule()->getConnection();
			// the mail's own day, so a spool replay lands on its arrival day
			$day = $db->table(AppConfig::MAIL_LOGS_TABLE)->where('id', $mailLogId)->value('created_day');
			if ($day === null) {
				$this->logger->error("[MapHitService_recordHits] {$qid} mail id {$mailLogId} not found");
				return;
			}

			$rows = [];
			foreach ($scores as $name => $score) {
				if (!isset($ids[$name])) {
					continue;
				}
				$rows[] = [
					'symbol_id' => $ids[$name],
					'created_day' => $day,
					'mail_log_id' => $mailLogId,
					'score' => $score,
				];
			}

			if ($rows !== []) {
				$db->table(AppConfig::MAIL_LOG_SYMBOL_HITS_TABLE)->insertOrIgnore($rows);
			}
		} catch (Throwable $e) {
			$this->logger->error("[MapHitService_recordHits] {$qid} could not record map hits: " . $e->getMessage());
		}
	}

	// symbol name => score for the prefixed symbols and those a map names
	private function wantedSymbols(string $symbols): array {
		$list = json_decode($symbols, true);
		if (!is_array($list) || $list === []) {
			return [];
		}

		$mapped = [];
		foreach (MapService::mapSymbols() as $names) {
			foreach ($names as $name) {
				$mapped[$name] = true;
			}
		}

		$scores = [];
		foreach ($list as $key => $symbol) {
			if (!is_array($symbol)) {
				continue;
			}
			$name = $symbol['name'] ?? (is_string($key) ? $key : null);
			if (!is_string($name) || !MapService::validSymbol($name)) {
				continue;
			}
			if (!isset($mapped[$name]) && !$this->hasPrefix($name)) {
				continue;
			}
			$score = $symbol['score'] ?? null;
			$scores[$name] = is_numeric($score) ? (float) $score : null;
		}

		return $scores;
	}

	private function hasPrefix(string $name): bool {
		foreach (self::PREFIXES as $prefix) {
			if (str_starts_with($name, $prefix)) {
				return true;
			}
		}
		return false;
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
