<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Charts;

class ChartBuilder {

	// distinct from every MailCategory colour (Tableau 10 teal)
	private const STORED_RATIO_COLOR = '#76b7b2';

	public static function createSearchChart(array $stats): Chart {
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

	public static function createQuarantineChart(iterable $days, ?callable $dayUrl = null, array $totals = []): Chart {
		$chart = new Chart(Chart::TYPE_BAR);

		$days = is_array($days)
			? array_reverse($days)
			: $days->reverse();

		$labels = [];
		$data = [];
		$links = [];
		$ratios = [];

		foreach ($days as $day) {
			 $labels[] = $day->day;
			 $data[] = $day->cnt;
			 $links[] = $dayUrl !== null ? $dayUrl((string) $day->day) : null;
			 $ratios[] = self::storedRatio((int) $day->cnt, $totals[(string) $day->day] ?? 0);
		}

		$chart->setData([
			 'labels' => $labels,
			 'datasets' => [
				[
					'label' => 'Quarantined E-mails',
					'data' => $data,
					'links' => $links,
					'backgroundColor' => [
						MailCategory::STORED->color(),
					],
				],
			 ],
		]);

		$chart->setOptions([
			 'responsive' => true,
			 'maintainAspectRatio' => false,
		]);

		if ($totals !== []) {
			self::addStoredRatio($chart, $ratios, $links);
		}

		if ($dayUrl !== null) {
			$chart->setAttributes(['note' => "Click on a bar to view that day's quarantined mails\nClick on a label at the top to toggle its bars"]);
		}

		return $chart;
	}

	public static function createQuarantinePerMonthChart(iterable $months, ?callable $monthUrl = null, array $totals = []): Chart {
		$chart = new Chart(Chart::TYPE_BAR);

		$months = is_array($months)
			? array_reverse($months)
			: $months->reverse();

		$labels = [];
		$data = [];
		$links = [];
		$ratios = [];

		foreach ($months as $month) {
			 $labels[] = $month->month;
			 $data[] = $month->cnt;
			 $links[] = $monthUrl !== null ? $monthUrl((string) $month->month) : null;
			 $ratios[] = self::storedRatio((int) $month->cnt, $totals[(string) $month->month] ?? 0);
		}

		$chart->setData([
			 'labels' => $labels,
			 'datasets' => [
				[
					'label' => 'Quarantined E-mails',
					'data' => $data,
					'links' => $links,
					'backgroundColor' => [
						MailCategory::STORED->color(),
					],
				],
			 ],
		]);

		$chart->setOptions([
			 'responsive' => true,
			 'maintainAspectRatio' => false,
		]);

		if ($totals !== []) {
			self::addStoredRatio($chart, $ratios, $links);
		}

		if ($monthUrl !== null) {
			$chart->setAttributes(['note' => "Click on a bar to view that month's quarantined mails\nClick on a label at the top to toggle its bars"]);
		}

		return $chart;
	}

	// percentage of all mail (same day/month, same user scope) that was stored
	private static function storedRatio(int $stored, int $total): ?float {
		return $total > 0 ? round(100 * $stored / $total, 1) : null;
	}

	// second bar per label on a right-hand 0-100 axis
	private static function addStoredRatio(Chart $chart, array $ratios, array $links): void {
		$data = $chart->getData();
		$data['datasets'][] = [
			'label' => 'Stored / Total (%)',
			'data' => $ratios,
			'links' => $links,
			'yAxisID' => 'y1',
			'backgroundColor' => [
				self::STORED_RATIO_COLOR,
			],
		];
		$chart->setData($data);

		$options = $chart->getOptions();
		$options['scales'] = [
			'y' => [
				'display' => 'auto',
				'beginAtZero' => true,
			],
			'y1' => [
				'display' => 'auto',
				'position' => 'right',
				'min' => 0,
				'grace' => '20%',
				'grid' => ['drawOnChartArea' => false],
				'title' => ['display' => true, 'text' => '% of total'],
			],
		];
		$chart->setOptions($options);
	}
}
