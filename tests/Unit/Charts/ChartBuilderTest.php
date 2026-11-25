<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 ChartBuilder: the Chart.js data, options and note of each chart. Rows come
 newest first and are drawn oldest first; a link is the URL callback's for
 that bar's label. With totals, a second dataset on a right-hand axis gives
 each bar's share of all mail, null where there was none. MailCategory: each
 category's label and colour, all colours distinct.
*/

namespace Tests\Unit\Charts;

use App\Charts\Chart;
use App\Charts\ChartBuilder;
use App\Charts\MailCategory;
use App\Models\MailLog;
use Generator;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use Tests\Unit\UnitTestCase;

final class ChartBuilderTest extends UnitTestCase
{
	private const array OPTIONS = ['responsive' => true, 'maintainAspectRatio' => false];

	private const array RATIO_SCALES = [
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

	/** @return array<string, array{MailCategory, string, string}> */
	public static function categories(): array {
		return [
			'total' => [MailCategory::TOTAL, 'Total', '#4e79a7'],
			'stored' => [MailCategory::STORED, 'Stored', '#f28e2b'],
			'virus' => [MailCategory::VIRUS, 'Virus', '#9F91FF'],
			'clean' => [MailCategory::CLEAN, 'No action', '#d9d9d9'],
			'header' => [MailCategory::HEADER, 'Add header', '#EE6262'],
			'subject' => [MailCategory::SUBJECT, 'Rewrite subject', '#F5BBBB'],
			'reject' => [MailCategory::REJECT, 'Reject', '#000000'],
			'discard' => [MailCategory::DISCARD, 'Discard', '#0010FF'],
		];
	}

	#[DataProvider('categories')]
	public function testCategory(MailCategory $category, string $label, string $color): void {
		$this->assertSame($label, $category->label());
		$this->assertSame($color, $category->color());
	}

	// the stored ratio and size bars sit beside the categories' bars
	public function testColoursDistinct(): void {
		$colours = array_map(fn (MailCategory $c): string => strtolower($c->color()), MailCategory::cases());
		$constants = (new ReflectionClass(ChartBuilder::class))->getConstants();
		foreach (['STORED_RATIO_COLOR', 'SIZE_COLOR'] as $name) {
			/** @var mixed $colour */
			$colour = $constants[$name] ?? null;
			$this->assertIsString($colour);
			$colours[] = strtolower($colour);
		}
		$this->assertSame($colours, array_values(array_unique($colours)));
	}

	public function testSearchChart(): void {
		$chart = ChartBuilder::createSearchChart([
			'count' => 100, 'first' => null, 'last' => null, 'stored' => 40, 'notified' => 0, 'released' => 0, 'has_virus' => 3,
			'action' => ['no action' => 50, 'add header' => 20, 'rewrite subject' => 10, 'reject' => 15, 'discard' => 5],
		]);

		$this->assertSame(Chart::TYPE_BAR, $chart->getType());
		$this->assertSame([
			'labels' => ['Total', 'Stored', 'Virus', 'No action', 'Add header', 'Rewrite subject', 'Reject', 'Discard'],
			'datasets' => [[
				'label' => 'E-mails',
				'data' => [100, 40, 3, 50, 20, 10, 15, 5],
				'backgroundColor' => ['#4e79a7', '#f28e2b', '#9F91FF', '#d9d9d9', '#EE6262', '#F5BBBB', '#000000', '#0010FF'],
			]],
		], $chart->getData());
		$this->assertSame(self::OPTIONS, $chart->getOptions());
		$this->assertSame([], $chart->getAttributes());
		$this->assertSame(['type' => Chart::TYPE_BAR, 'data' => $chart->getData(), 'options' => self::OPTIONS], $chart->createView());
	}

	public function testQuarantineChartNoUrlNoTotals(): void {
		$chart = ChartBuilder::createQuarantineChart([$this->row('day', '2026-11-02', 5), $this->row('day', '2026-11-01', 3)]);

		$this->assertSame(Chart::TYPE_BAR, $chart->getType());
		$this->assertSame([
			'labels' => ['2026-11-01', '2026-11-02'],
			'datasets' => [[
				'label' => 'Quarantined E-mails',
				'data' => [3, 5],
				'links' => [null, null],
				'backgroundColor' => ['#f28e2b'],
			]],
		], $chart->getData());
		$this->assertSame(self::OPTIONS, $chart->getOptions());
		$this->assertSame([], $chart->getAttributes());
	}

	// any iterable (an Eloquent collection, say), its keys ignored; a day
	// missing from totals, or with none, has no ratio
	public function testQuarantineChartWithUrlAndTotals(): void {
		$chart = ChartBuilder::createQuarantineChart(
			$this->rows('day', ['2026-11-04' => 1, '2026-11-03' => 2, '2026-11-02' => 4, '2026-11-01' => 0]),
			fn (string $day): string => "/q/{$day}",
			['2026-11-01' => 0, '2026-11-02' => 8, '2026-11-03' => 3],
		);

		$links = ['/q/2026-11-01', '/q/2026-11-02', '/q/2026-11-03', '/q/2026-11-04'];
		$this->assertSame([
			'labels' => ['2026-11-01', '2026-11-02', '2026-11-03', '2026-11-04'],
			'datasets' => [
				[
					'label' => 'Quarantined E-mails',
					'data' => [0, 4, 2, 1],
					'links' => $links,
					'backgroundColor' => ['#f28e2b'],
				],
				[
					'label' => 'Stored / Total (%)',
					'data' => [null, 50.0, 66.7, null],
					'links' => $links,
					'yAxisID' => 'y1',
					'backgroundColor' => ['#76b7b2'],
				],
			],
		], $chart->getData());
		$this->assertSame(self::OPTIONS + ['scales' => self::RATIO_SCALES], $chart->getOptions());
		$this->assertSame(['note' => "Click on a bar to view that day's quarantined mails\nClick on a label at the top to toggle its bars"], $chart->getAttributes());
	}

	public function testQuarantinePerMonthChartNoUrlNoTotals(): void {
		$chart = ChartBuilder::createQuarantinePerMonthChart([$this->row('month', '2026-11', 7), $this->row('month', '2026-10', 9)]);

		$this->assertSame(Chart::TYPE_BAR, $chart->getType());
		$this->assertSame([
			'labels' => ['2026-10', '2026-11'],
			'datasets' => [[
				'label' => 'Quarantined E-mails',
				'data' => [9, 7],
				'links' => [null, null],
				'backgroundColor' => ['#f28e2b'],
			]],
		], $chart->getData());
		$this->assertSame(self::OPTIONS, $chart->getOptions());
		$this->assertSame([], $chart->getAttributes());
	}

	public function testQuarantinePerMonthChartWithUrlAndTotals(): void {
		$chart = ChartBuilder::createQuarantinePerMonthChart(
			$this->rows('month', ['2026-12' => 2, '2026-11' => 1, '2026-10' => 3]),
			fn (string $month): string => "/m/{$month}",
			['2026-10' => 9, '2026-11' => 1],
		);

		$links = ['/m/2026-10', '/m/2026-11', '/m/2026-12'];
		$this->assertSame([
			'labels' => ['2026-10', '2026-11', '2026-12'],
			'datasets' => [
				[
					'label' => 'Quarantined E-mails',
					'data' => [3, 1, 2],
					'links' => $links,
					'backgroundColor' => ['#f28e2b'],
				],
				[
					'label' => 'Stored / Total (%)',
					'data' => [33.3, 100.0, null],
					'links' => $links,
					'yAxisID' => 'y1',
					'backgroundColor' => ['#76b7b2'],
				],
			],
		], $chart->getData());
		$this->assertSame(self::OPTIONS + ['scales' => self::RATIO_SCALES], $chart->getOptions());
		$this->assertSame(['note' => "Click on a bar to view that month's quarantined mails\nClick on a label at the top to toggle its bars"], $chart->getAttributes());
	}

	// drawn in the order given
	public function testMapHitsChart(): void {
		$chart = ChartBuilder::createMapHitsChart(['2026-11-01' => 2, '2026-11-02' => 5], fn (string $day): string => "/h/{$day}");

		$this->assertSame(Chart::TYPE_BAR, $chart->getType());
		$this->assertSame([
			'labels' => ['2026-11-01', '2026-11-02'],
			'datasets' => [[
				'label' => 'Mails hit',
				'data' => [2, 5],
				'links' => ['/h/2026-11-01', '/h/2026-11-02'],
				'backgroundColor' => ['#4e79a7'],
			]],
		], $chart->getData());
		$this->assertSame(self::OPTIONS, $chart->getOptions());
		$this->assertSame(['note' => "Click on a bar to view that day's mails"], $chart->getAttributes());
	}

	/** @return array<string, array{string|null, string, string}> */
	public static function reportUnits(): array {
		return [
			'day (the default)' => [null, 'Stored', "Click on a day to view its mails\nClick on a label at the top to toggle its bars"],
			'day' => ['day', 'Stored', "Click on a day to view its mails\nClick on a label at the top to toggle its bars"],
			'month: ever stored' => ['month', 'Ever stored', "Click on a month to view its mails\nClick on a label at the top to toggle its bars"],
		];
	}

	// size in MB to one decimal (1.25 MB up, a byte less down), none known as
	// 0; a row with no date links to ''
	#[DataProvider('reportUnits')]
	public function testReportsDayChart(?string $unit, string $storedLabel, string $note): void {
		$rows = [
			['date' => '2026-11-02', 'total' => 10, 'total_stored' => 4, 'total_size' => 1310720],
			['date' => '2026-11-01', 'total' => 6, 'total_stored' => 1, 'total_size' => null],
			['total' => 2, 'total_stored' => 0, 'total_size' => 1310719],
		];
		$url = fn (string $date): string => "/r?d={$date}";
		$chart = $unit === null
			? ChartBuilder::createReportsDayChart($rows, $url)
			: ChartBuilder::createReportsDayChart($rows, $url, $unit);

		$links = ['/r?d=', '/r?d=2026-11-01', '/r?d=2026-11-02'];
		$this->assertSame(Chart::TYPE_BAR, $chart->getType());
		$this->assertSame([
			'labels' => ['', '2026-11-01', '2026-11-02'],
			'datasets' => [
				[
					'label' => 'Total',
					'data' => [2, 6, 10],
					'links' => $links,
					'backgroundColor' => ['#4e79a7'],
				],
				[
					'label' => $storedLabel,
					'data' => [0, 1, 4],
					'links' => $links,
					'backgroundColor' => ['#f28e2b'],
				],
				[
					'label' => 'Size (MB)',
					'data' => [1.2, 0.0, 1.3],
					'links' => $links,
					'yAxisID' => 'y1',
					'backgroundColor' => ['#59a14f'],
				],
			],
		], $chart->getData());
		$this->assertSame([
			'responsive' => true,
			'maintainAspectRatio' => false,
			'interaction' => ['mode' => 'index', 'intersect' => false],
			'scales' => [
				'y' => [
					'display' => 'auto',
					'beginAtZero' => true,
					'title' => ['display' => true, 'text' => 'Mails'],
				],
				'y1' => [
					'display' => 'auto',
					'position' => 'right',
					'beginAtZero' => true,
					'grid' => ['drawOnChartArea' => false],
					'title' => ['display' => true, 'text' => 'MB'],
				],
			],
		], $chart->getOptions());
		$this->assertSame(['note' => $note], $chart->getAttributes());
	}

	public function testNoRows(): void {
		$chart = ChartBuilder::createQuarantineChart([], fn (string $day): string => $day, ['2026-11-01' => 5]);
		$this->assertSame(['labels' => [], 'datasets' => [
			['label' => 'Quarantined E-mails', 'data' => [], 'links' => [], 'backgroundColor' => ['#f28e2b']],
			['label' => 'Stored / Total (%)', 'data' => [], 'links' => [], 'yAxisID' => 'y1', 'backgroundColor' => ['#76b7b2']],
		]], $chart->getData());
	}

	// only the builders above call it, always on a chart with datasets
	public function testStoredRatioNeedsDatasets(): void {
		$this->expectException(LogicException::class);
		$this->expectExceptionMessage('The chart has no datasets to add the stored ratio to');
		(new ReflectionMethod(ChartBuilder::class, 'addStoredRatio'))->invoke(null, new Chart(Chart::TYPE_BAR), [], []);
	}

	private function row(string $field, string $value, int $cnt): MailLog {
		return (new MailLog())->setRawAttributes([$field => $value, 'cnt' => $cnt]);
	}

	/**
	 * a generator, not an array: the builders take any iterable, and every row
	 * has the same key, which a builder keeping keys would collapse
	 * @param array<string, int> $counts label => cnt, newest first
	 * @return Generator<int, MailLog>
	 */
	private function rows(string $field, array $counts): Generator {
		foreach ($counts as $value => $cnt) {
			yield 0 => $this->row($field, $value, $cnt);
		}
	}
}
