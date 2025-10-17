<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU Affero General Public License version 3
as published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU Affero General Public License for more details.

You should have received a copy of the GNU Affero General Public License
along with this program.  If not, see <https://www.gnu.org/licenses/>.
*/

namespace App\Console;

use Symfony\Component\Console\Command\Command;

use Symfony\Component\Console\Output\OutputInterface;

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
}
