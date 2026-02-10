<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

use Illuminate\Database\Query\Builder;

use App\Core\Config;
use App\Utils\Helper;

class RqwatchCliCommand extends Command
{
	public function getRuntime(): string {
		return Helper::get_runtime(
			Config::get('startTime'),
			Config::get('startMemory')
		);
	}

	public function printRuntime(OutputInterface $output): void {
		$runtime = $this->getRuntime();
		$output->writeln("<info>{$runtime}</info>",
			OutputInterface::VERBOSITY_VERY_VERBOSE);
	}

	public static function getSqlFromQuery(Builder $query): string {
		return vsprintf(str_replace('?', '"%s"', $query->toSql()), $query->getBindings());
	}
}
