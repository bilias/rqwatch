<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Core\Database;

use RuntimeException;
use stdClass;

/*
 Typed column reads of raw database rows (select(), the query builder's
 get(), pluck()): a missing column, or a value of another type, throws
 naming the column instead of being cast to '' or 0. An integer column
 comes back as an int with native prepares and as its digits otherwise:
 both are accepted, nothing else is.
*/
final class Row
{
	public static function string(stdClass $row, string $column): string {
		return self::nullableString($row, $column) ?? throw self::wrongType($column, 'text', null);
	}

	public static function nullableString(stdClass $row, string $column): ?string {
		/** @var mixed $value */
		$value = self::value($row, $column);
		return $value === null || is_string($value) ? $value : throw self::wrongType($column, 'text', $value);
	}

	public static function int(stdClass $row, string $column): int {
		return self::nullableInt($row, $column) ?? throw self::wrongType($column, 'an integer', null);
	}

	public static function nullableInt(stdClass $row, string $column): ?int {
		/** @var mixed $value */
		$value = self::value($row, $column);
		return $value === null ? null : self::toInt($value, $column);
	}

	/**
	 * A plucked list of integer values, keys kept.
	 * @template TKey of array-key
	 * @param array<TKey, mixed> $values
	 * @return array<TKey, int>
	 */
	public static function intValues(array $values, string $column): array {
		$ints = [];
		/** @var mixed $value */
		foreach ($values as $key => $value) {
			$ints[$key] = self::toInt($value, $column);
		}
		return $ints;
	}

	/**
	 * A plucked list of text values, keys kept.
	 * @template TKey of array-key
	 * @param array<TKey, mixed> $values
	 * @return array<TKey, string>
	 */
	public static function stringValues(array $values, string $column): array {
		$strings = [];
		/** @var mixed $value */
		foreach ($values as $key => $value) {
			$strings[$key] = is_string($value) ? $value : throw self::wrongType($column, 'text', $value);
		}
		return $strings;
	}

	private static function toInt(mixed $value, string $column): int {
		if (is_int($value)) {
			return $value;
		}
		if (is_string($value) && preg_match('/^-?[0-9]+$/D', $value) === 1) {
			$int = filter_var($value, FILTER_VALIDATE_INT);
			if (is_int($int)) {
				return $int;
			}
		}
		throw self::wrongType($column, 'an integer', $value);
	}

	private static function value(stdClass $row, string $column): mixed {
		if (!property_exists($row, $column)) {
			throw new RuntimeException("Database row has no column '{$column}'");
		}
		/** @var mixed $value */
		$value = $row->{$column};
		return $value;
	}

	private static function wrongType(string $column, string $expected, mixed $value): RuntimeException {
		return new RuntimeException("Database column '{$column}' must be {$expected}, not " . get_debug_type($value));
	}
}
