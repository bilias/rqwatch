<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Charts;

use App\Core\Config;

class ChartBuilder {

	public static function createSearchChart(array $stats): ?Chart {
		if (!Config::get('charts_enable')) {
			return null;
		}

		$chart = new Chart(Chart::TYPE_BAR);

		$chart->setData([
			'labels' => [
				MailCategory::TOTAL->label(),
				MailCategory::STORED->label(),
				MailCategory::VIRUS->label(),
				MailCategory::CLEAN->label(),
				MailCategory::HEADER->label(),
				MailCategory::SUBJECT->label(),
				MailCategory::REJECT->label(),
				MailCategory::DISCARD->label(),
			],
			'datasets' => [
				[
					'label' => 'E-mails',
					'data' => [
						$stats['count'],
						$stats['stored'],
						$stats['has_virus'],
						$stats['action']['no action'],
						$stats['action']['add header'],
						$stats['action']['rewrite subject'],
						$stats['action']['reject'],
						$stats['action']['discard'],
					],
					'backgroundColor' => [
						MailCategory::TOTAL->color(),
						MailCategory::STORED->color(),
						MailCategory::VIRUS->color(),
						MailCategory::CLEAN->color(),
						MailCategory::HEADER->color(),
						MailCategory::SUBJECT->color(),
						MailCategory::REJECT->color(),
						MailCategory::DISCARD->color(),
					],
				],
			],
		]);

		$chart->setOptions([
			'responsive' => true,
			'maintainAspectRatio' => false,
		]);

		return $chart;
	}
}
