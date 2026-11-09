<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Chart::getDataController(): the data-controller attribute, null when there
 is none; one that is not a string fails rather than reading as none.
*/

namespace Tests\Unit\Charts;

use App\Charts\Chart;
use InvalidArgumentException;
use Tests\Unit\UnitTestCase;

final class ChartTest extends UnitTestCase
{
	public function testDataController(): void {
		$this->assertSame('chart', (new Chart('line'))->setAttributes(['data-controller' => 'chart'])->getDataController());
		$this->assertNull((new Chart('line'))->getDataController());
	}

	public function testDataControllerNotAStringFails(): void {
		$chart = (new Chart('line'))->setAttributes(['data-controller' => ['chart']]);
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Chart data-controller must be a string, not array');
		$chart->getDataController();
	}
}
