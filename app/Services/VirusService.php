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

class VirusService
{
	private LoggerInterface $logger;

	public function __construct() {
		$this->logger = App::fileLogger();
	}

	/*
	 * Record the antivirus symbols and names of an imported virus mail.
	 * $symbols is the mail's symbols JSON. Never throws: a failure here must not cost the import.
	 */
	public function recordViruses(int $mailLogId, string $symbols, string $qid): void {
		try {
			if (!App::migrationStatus()->mailLogVirusesWritable()) {
				return;
			}

			$viruses = self::extractViruses(json_decode($symbols, true));
			if ($viruses === []) {
				return;
			}

			$db = App::capsule()->getConnection();
			// the mail's own day, so a spool replay lands on its arrival day
			$day = $db->table(AppConfig::MAIL_LOGS_TABLE)->where('id', $mailLogId)->value('created_day');
			if ($day === null) {
				$this->logger->error("[VirusService_recordViruses] {$qid} mail id {$mailLogId} not found");
				return;
			}

			$rows = [];
			foreach ($viruses as $virus) {
				$rows[] = [
					'mail_log_id' => $mailLogId,
					'created_day' => $day,
					'av' => $virus['av'],
					'name' => $virus['name'],
				];
			}

			// ignores rows the MAIL_LOG_VIRUSES backfill wrote meanwhile
			$db->table(AppConfig::MAIL_LOG_VIRUSES_TABLE)->insertOrIgnore($rows);
		} catch (Throwable $e) {
			$this->logger->error("[VirusService_recordViruses] {$qid} could not record viruses: " . $e->getMessage());
		}
	}

	/*
	 * One ['av', 'name'] per antivirus symbol and option, name '' when the
	 * symbol has none; the same rows the MAIL_LOG_VIRUSES backfill extracts.
	 */
	public static function extractViruses(mixed $symbols): array {
		if (!is_array($symbols)) {
			return [];
		}

		$viruses = [];
		foreach ($symbols as $symbol) {
			if (!is_array($symbol) || ($symbol['group'] ?? null) !== 'antivirus'
				|| !isset($symbol['name']) || !is_scalar($symbol['name'])) {
				continue;
			}

			$av = mb_substr((string) $symbol['name'], 0, 128);

			$names = [];
			foreach ((array) ($symbol['options'] ?? []) as $option) {
				$names[] = is_scalar($option) ? mb_substr((string) $option, 0, 255) : '';
			}
			if ($names === []) {
				$names = [''];
			}

			foreach ($names as $name) {
				$viruses["{$av}\0{$name}"] = ['av' => $av, 'name' => $name];
			}
		}

		return array_values($viruses);
	}

}
