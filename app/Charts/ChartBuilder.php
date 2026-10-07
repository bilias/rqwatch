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

	public static function createQuarantineChart(iterable $days, ?callable $dayUrl = null): Chart {
		$chart = new Chart(Chart::TYPE_BAR);

		$days = is_array($days)
			? array_reverse($days)
			: $days->reverse();

		$labels = [];
		$data = [];
		$links = [];

		foreach ($days as $day) {
			 $labels[] = $day->day;
			 $data[] = $day->cnt;
			 $links[] = $dayUrl !== null ? $dayUrl((string) $day->day) : null;
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

		if ($dayUrl !== null) {
			$chart->setAttributes(['note' => "Click on a bar to view that day's quarantined mails"]);
		}

		return $chart;
	}

	public static function createQuarantinePerMonthChart(iterable $months, ?callable $monthUrl = null): Chart {
		$chart = new Chart(Chart::TYPE_BAR);

		$months = is_array($months)
			? array_reverse($months)
			: $months->reverse();

		$labels = [];
		$data = [];
		$links = [];

		foreach ($months as $month) {
			 $labels[] = $month->month;
			 $data[] = $month->cnt;
			 $links[] = $monthUrl !== null ? $monthUrl((string) $month->month) : null;
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

		if ($monthUrl !== null) {
			$chart->setAttributes(['note' => "Click on a bar to view that month's quarantined mails"]);
		}

		return $chart;
	}



}
