<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Anchored validation patterns end at the end of the text: with PCRE's
 default, $ also matches before a final line feed, so "value\n" passed
 as "value". Each pattern takes a valid value and refuses it with a line
 feed added. The form fields check every Regex constraint they have: a new
 one fails here until it has a sample. Patterns tested where their function
 is: AddressTest (SRS/PRVS), MapInventoryTest (CIDR prefix),
 FuzzyDigestsTest and FuzzyReplyHashesTest (fuzzy digests).
*/

namespace Tests\Unit\Helpers;

use App\Core\Database\Migrations\VirusSkipMap;
use App\Forms\CustomMapConfigForm;
use App\Forms\FuzzySearchForm;
use App\Forms\LoginForm;
use App\Forms\MailAliasForm;
use App\Forms\MailAliasSearchForm;
use App\Forms\UserForm;
use App\Forms\UserSearchForm;
use App\Services\MailTokenService;
use App\Services\MapService;
use App\Utils\Helper;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClassConstant;
use ReflectionMethod;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Validation;
use Tests\Unit\UnitTestCase;

final class LineEndPatternsTest extends UnitTestCase
{
	// a valid value for each field with a Regex constraint; null: not tested
	private const array FIELD_SAMPLES = [
		'username' => 'alice@example.org',
		'map_name' => '123', // matches both of its patterns, the second one refuses it
		'map_description' => 'Config test (1)',
		'field_name' => 'domain',
		'field_label' => 'Domain name',
		// a symbol list may end in white space, a line feed included: the field is trimmed
		'symbols' => null,
	];

	/** @return array<string, array{string}> */
	public static function forms(): array {
		return ['login' => ['login'], 'user' => ['user'], 'mail alias' => ['mail alias'], 'custom map config' => ['custom map config']];
	}

	#[DataProvider('forms')]
	public function testFormPatterns(string $form): void {
		$tested = 0;
		foreach (self::form($form)->all() as $name => $field) {
			/** @var mixed $constraints */
			$constraints = $field->getConfig()->getOption('constraints');
			/** @var mixed $constraint */
			foreach (is_array($constraints) ? $constraints : [] as $constraint) {
				if (!$constraint instanceof Regex) {
					continue;
				}
				$this->assertArrayHasKey($name, self::FIELD_SAMPLES, "{$form} {$name}: no sample for its Regex constraint");
				$sample = self::FIELD_SAMPLES[$name];
				if ($sample !== null) {
					self::assertEndsAtEnd(self::pattern($constraint->pattern, "{$form} {$name}"), $sample, "{$form} {$name}");
					$tested++;
				}
			}
		}
		$this->assertGreaterThan(0, $tested, "{$form}: no pattern tested");
	}

	/** @return array<string, array{class-string, string, string}> */
	public static function constants(): array {
		return [
			'fuzzy search' => [FuzzySearchForm::class, 'PATTERN', 'alice@example.org'],
			'user search' => [UserSearchForm::class, 'PATTERN', 'alice@example.org'],
			'alias search' => [MailAliasSearchForm::class, 'PATTERN', 'alias-alice@example.org'],
			'notification token' => [MailTokenService::class, 'TOKEN_FORMAT', str_repeat('0f', 32)],
			'virus skip map name' => [VirusSkipMap::class, 'NAME_REGEX', 'virus_skip'],
		];
	}

	/** @param class-string $class */
	#[DataProvider('constants')]
	public function testConstantPatterns(string $class, string $constant, string $sample): void {
		$what = "{$class}::{$constant}";
		self::assertEndsAtEnd(self::pattern((new ReflectionClassConstant($class, $constant))->getValue(), $what), $sample, $what);
	}

	public function testSymbolName(): void {
		$this->assertTrue(MapService::validSymbol('RQWATCH_FUZZY_SPAM'));
		$this->assertFalse(MapService::validSymbol("RQWATCH_FUZZY_SPAM\n"));
	}

	public function testMapFileName(): void {
		$safe = new ReflectionMethod(MapService::class, 'fileSafeMapName');
		$this->assertSame('blocked-senders', $safe->invoke(null, 'blocked-senders'));
		$this->assertNull($safe->invoke(null, "blocked-senders\n"));
	}

	// the date parse after the pattern refuses the line feed too
	public function testDate(): void {
		$this->assertTrue(Helper::check_date('2026-11-25'));
		$this->assertFalse(Helper::check_date("2026-11-25\n"));
	}

	/** @return FormInterface<mixed> */
	private static function form(string $name): FormInterface {
		$factory = Forms::createFormFactoryBuilder()
			->addExtension(new ValidatorExtension(Validation::createValidator()))
			->getFormFactory();
		return match ($name) {
			'login' => $factory->create(LoginForm::class),
			'user' => $factory->create(UserForm::class),
			'mail alias' => $factory->create(MailAliasForm::class),
			'custom map config' => $factory->create(CustomMapConfigForm::class, null, ['map_symbols' => true]),
			default => self::fail("no form {$name}"),
		};
	}

	/** @return non-empty-string */
	private static function pattern(mixed $pattern, string $what): string {
		if (!is_string($pattern) || $pattern === '') {
			self::fail("{$what}: no pattern");
		}
		return $pattern;
	}

	/** @param non-empty-string $pattern */
	private static function assertEndsAtEnd(string $pattern, string $sample, string $what): void {
		self::assertSame(1, preg_match($pattern, $sample), "{$what}: refuses {$sample}");
		self::assertSame(0, preg_match($pattern, "{$sample}\n"), "{$what}: takes {$sample} with a line feed");
	}
}
