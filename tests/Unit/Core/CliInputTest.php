<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The console commands' option and argument readers (RqwatchCliCommand):
 a flag is on or off; a whole-number option is its default, the digits
 given, or null when given without a value, and anything else is refused
 rather than cut to its leading digits; text options and arguments are
 text.
*/

namespace Tests\Unit\Core;

use App\Console\RqwatchCliCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Tests\Unit\UnitTestCase;

// a command with one option or argument of each kind, its readers made public
final class CliInputProbe extends RqwatchCliCommand
{
	#[\Override]
	protected function configure(): void {
		$this->setName('test:input')
			->addOption('show', 's', InputOption::VALUE_NONE)
			->addOption('batch', 'b', InputOption::VALUE_OPTIONAL, '', 500)
			->addOption('table', 't', InputOption::VALUE_REQUIRED)
			->addArgument('username', InputArgument::REQUIRED);
	}

	public function readFlag(InputInterface $input, string $name): bool {
		return $this->flag($input, $name);
	}

	public function readInt(InputInterface $input, string $name): ?int {
		return $this->intOption($input, $name);
	}

	public function readText(InputInterface $input, string $name): ?string {
		return $this->textOption($input, $name);
	}

	public function readArgument(InputInterface $input, string $name): string {
		return $this->textArgument($input, $name);
	}
}

final class CliInputTest extends UnitTestCase
{
	private static function command(): CliInputProbe {
		return new CliInputProbe();
	}

	/** @param array<string, mixed> $given */
	private static function input(array $given): ArrayInput {
		return new ArrayInput($given + ['username' => 'alice'], self::command()->getDefinition());
	}

	public function testFlag(): void {
		$command = self::command();
		$this->assertTrue($command->readFlag(self::input(['--show' => true]), 'show'));
		$this->assertFalse($command->readFlag(self::input([]), 'show'));
	}

	/** @return array<string, array{array<string, mixed>, int|null}> */
	public static function wholeNumbers(): array {
		return [
			'the default' => [[], 500],
			'digits' => [['--batch' => '250'], 250],
			'a leading zero' => [['--batch' => '08'], 8],
			'given without a value' => [['--batch' => null], null],
		];
	}

	/** @param array<string, mixed> $given */
	#[DataProvider('wholeNumbers')]
	public function testIntOption(array $given, ?int $read): void {
		$this->assertSame($read, self::command()->readInt(self::input($given), 'batch'));
	}

	/** @return array<string, array{string}> */
	public static function notWholeNumbers(): array {
		return ['trailing text' => ['5x'], 'text' => ['x'], 'negative' => ['-3'], 'decimal' => ['1.5'], 'spaced' => [' 7']];
	}

	#[DataProvider('notWholeNumbers')]
	public function testIntOptionRefused(string $given): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage("Option --batch must be a whole number, not '{$given}'");
		self::command()->readInt(self::input(['--batch' => $given]), 'batch');
	}

	public function testTextOptionAndArgument(): void {
		$command = self::command();
		$this->assertSame('mail_logs', $command->readText(self::input(['--table' => 'mail_logs']), 'table'));
		$this->assertNull($command->readText(self::input([]), 'table'));
		$this->assertSame('alice', $command->readArgument(self::input([]), 'username'));
	}
}
