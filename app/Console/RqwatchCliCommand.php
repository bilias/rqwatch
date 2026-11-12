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
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use Illuminate\Database\Query\Builder;

use App\Core\App;
use App\Utils\Helper;

use Psr\Log\LoggerInterface;

class RqwatchCliCommand extends Command
{
	protected LoggerInterface $fileLogger;
	protected LoggerInterface $syslogLogger;

	public function __construct() {
		parent::__construct();

		$this->fileLogger = App::fileLogger();
		$this->syslogLogger = App::syslogLogger();
	}

	// a VALUE_NONE option: on or off
	protected function flag(InputInterface $input, string $name): bool {
		return $input->getOption($name) === true;
	}

	/*
	 A whole-number option: its default (an int), or digits as given. Null
	 when given without a value, so the command's own check applies as
	 before; anything else is refused, never cut down to its leading digits
	 the way (int) '5x' is 5.
	*/
	protected function intOption(InputInterface $input, string $name): ?int {
		/** @var mixed $value */
		$value = $input->getOption($name);
		if ($value === null || is_int($value)) {
			return $value;
		}
		if (is_string($value) && ctype_digit($value)) {
			return (int) $value;
		}
		throw new InvalidArgumentException("Option --{$name} must be a whole number, not '" . (is_string($value) ? $value : get_debug_type($value)) . "'");
	}

	// a text option: its text, or null when not given
	protected function textOption(InputInterface $input, string $name): ?string {
		/** @var mixed $value */
		$value = $input->getOption($name);
		if ($value === null || is_string($value)) {
			return $value;
		}
		throw new InvalidArgumentException("Option --{$name} must be text, not " . get_debug_type($value));
	}

	// a required argument, which Symfony gives as text
	protected function textArgument(InputInterface $input, string $name): string {
		/** @var mixed $value */
		$value = $input->getArgument($name);
		if (is_string($value)) {
			return $value;
		}
		throw new InvalidArgumentException("Argument {$name} must be text, not " . get_debug_type($value));
	}

	public function getRuntime(): string {
		return App::getRuntime();
	}

	public function printRuntime(OutputInterface $output): void {
		$runtime = $this->getRuntime();
		$output->writeln("<info>{$runtime}</info>",
			OutputInterface::VERBOSITY_VERY_VERBOSE);
	}

	public static function getSqlFromQuery(Builder $query): string {
		return Helper::sqlWithBindings($query->toSql(), $query->getBindings());
	}
}
