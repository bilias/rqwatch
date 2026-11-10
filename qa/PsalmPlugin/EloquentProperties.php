<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Eloquent model attributes, which Model::__get()/__set() serve by magic,
 for a sealed Psalm (sealAllProperties). Mirrors what Larastan gives
 PHPStan, from the same sources:

 - a relation method, by its exact name: what its getResults() returns
   (Relation's TResult, e.g. Collection<int, MailLogRecipient> for a
   HasMany, ?User for a BelongsTo); read-only
 - a get<Studly>Attribute() accessor: its return type
 - a column of the model's table in contrib/*.sql, parsed by Larastan's
   own SQL parser and type converter; then the model's $casts and dates
   (Larastan's cast types), and NULL for a nullable column
 - the primary key, when the schema does not list it: $keyType

 Real properties and @property / @property-read tags are left to Psalm,
 so a tag still overrides the schema, as it does for Larastan. Anything
 else is not claimed and stays an UndefinedMagicProperty* issue.
 Attribute-returning accessors (Laravel 9 style) are not handled: rqwatch
 has none.
*/

namespace Qa\PsalmPlugin;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use Larastan\Larastan\Properties\Schema\MySqlDataTypeToPhpTypeConverter;
use Larastan\Larastan\SQL\IamcalSqlParser;
use Larastan\Larastan\SQL\SqlParserFactory;
use Larastan\Larastan\SQL\SqlParserFailure;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\Statements\Expression\Call\ClassTemplateParamCollector;
use Psalm\Internal\MethodIdentifier;
use Psalm\Internal\Type\TypeExpander;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Plugin\EventHandler\Event\PropertyExistenceProviderEvent;
use Psalm\Plugin\EventHandler\Event\PropertyTypeProviderEvent;
use Psalm\Plugin\EventHandler\Event\PropertyVisibilityProviderEvent;
use Psalm\Type;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;
use ReflectionClass;
use RuntimeException;

final class EloquentProperties implements AfterCodebasePopulatedInterface
{
	// as qa/phpstan.neon's squashedMigrationsPath, from the project root
	private const string SCHEMA = 'contrib/*.sql';

	// Larastan's date type (Eloquent returns Illuminate\Support\Carbon, a Carbon)
	private const string DATE = '\Carbon\Carbon';

	private static ?Codebase $codebase = null;

	/** @var array<string, array<string, array{type: string, nullable: bool}>>|null table => column => */
	private static ?array $tables = null;

	/** @var array<string, ?Model> class => instance without constructor */
	private static array $models = [];

	/** @var array<string, array{read: Union, write: Union}|null> class::property => types */
	private static array $properties = [];

	#[\Override]
	public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void {
		$codebase = self::$codebase = $event->getCodebase();
		$model = strtolower(Model::class);

		foreach ($codebase->classlike_storage_provider::getAll() as $storage) {
			if (!$storage->user_defined || $storage->abstract || !isset($storage->parent_classes[$model])) {
				continue;
			}
			$class = $storage->name;
			$codebase->properties->property_existence_provider->registerClosure($class, self::doesPropertyExist(...));
			$codebase->properties->property_visibility_provider->registerClosure($class, self::isPropertyVisible(...));
			$codebase->properties->property_type_provider->registerClosure($class, self::getPropertyType(...));
		}
	}

	public static function doesPropertyExist(PropertyExistenceProviderEvent $event): ?bool {
		return self::property($event->getFqClasslikeName(), $event->getPropertyName()) !== null ? true : null;
	}

	// served by __get()/__set(), so public
	public static function isPropertyVisible(PropertyVisibilityProviderEvent $event): ?bool {
		return self::property($event->getFqClasslikeName(), $event->getPropertyName()) !== null ? true : null;
	}

	public static function getPropertyType(PropertyTypeProviderEvent $event): ?Union {
		$types = self::property($event->getFqClasslikeName(), $event->getPropertyName());
		if ($types === null) {
			return null;
		}
		return $event->isReadMode() ? $types['read'] : $types['write'];
	}

	/**
	 * The read and write types of the attribute $name of the model $class, or
	 * null if not claimed here (see the header). Also used by MailTaintSources.
	 *
	 * @return array{read: Union, write: Union}|null
	 */
	public static function property(string $class, string $name): ?array {
		$key = strtolower($class) . '::' . $name;
		if (array_key_exists($key, self::$properties)) {
			return self::$properties[$key];
		}
		$codebase = self::codebase();
		$storage = $codebase->classlike_storage_provider->get($class);
		if (isset($storage->declaring_property_ids[$name])
			|| isset($storage->pseudo_property_get_types['$' . $name])
			|| isset($storage->pseudo_property_set_types['$' . $name])
		) {
			return self::$properties[$key] = null;
		}
		$class = $storage->name;
		return self::$properties[$key] = self::relation($codebase, $class, $name)
			?? self::accessor($codebase, $class, $name)
			?? self::column($class, $name);
	}

	/** @return array{read: Union, write: Union}|null */
	private static function relation(Codebase $codebase, string $class, string $name): ?array {
		$returnType = self::returnType($codebase, $class, $name);
		if ($returnType === null) {
			return null;
		}
		$results = [];
		foreach ($returnType->getAtomicTypes() as $atomic) {
			if (!$atomic instanceof TNamedObject || !$codebase->classExtends($atomic->value, Relation::class)) {
				return null;
			}
			$params = ClassTemplateParamCollector::collect(
				$codebase,
				$codebase->classlike_storage_provider->get(Relation::class),
				$codebase->classlike_storage_provider->get($atomic->value),
				'getresults',
				$atomic,
			);
			$results[] = $params['TResult'][Relation::class] ?? Type::getMixed();
		}
		return ['read' => Type::combineUnionTypeArray($results, $codebase), 'write' => Type::getNever()];
	}

	/** @return array{read: Union, write: Union}|null */
	private static function accessor(Codebase $codebase, string $class, string $name): ?array {
		$method = 'get' . Str::studly($name) . 'Attribute';
		if (!$codebase->methodExists(new MethodIdentifier($class, strtolower($method)))) {
			return null;
		}
		$type = self::returnType($codebase, $class, $method) ?? Type::getMixed();
		return ['read' => $type, 'write' => $type];
	}

	// $class's own (or inherited) $method's return type, $this and self resolved
	private static function returnType(Codebase $codebase, string $class, string $method): ?Union {
		$id = new MethodIdentifier($class, strtolower($method));
		if (!$codebase->methodExists($id)) {
			return null;
		}
		$declaring = $codebase->methods->getDeclaringMethodId($id);
		if ($declaring === null) {
			return null;
		}
		$type = $codebase->methods->getStorage($declaring)->return_type;
		if ($type === null) {
			return null;
		}
		return TypeExpander::expandUnion($codebase, $type, $class, $class, null);
	}

	/** @return array{read: Union, write: Union}|null */
	private static function column(string $class, string $name): ?array {
		$model = self::model($class);
		if ($model === null) {
			return null;
		}
		$column = self::tables()[$model->getTable()][$name] ?? null;
		if ($column === null) {
			if ($name !== $model->getKeyName()) {
				return null;
			}
			$type = Type::parseString($model->getKeyType() === 'int' ? 'int' : 'string');
			return ['read' => $type, 'write' => $type];
		}

		/** @var mixed $cast */
		$cast = $model->getCasts()[$name] ?? null;
		// Eloquent casts are a type or a class name
		if ($cast !== null && !is_string($cast)) {
			throw new \LogicException(get_class($model) . "::\$casts['{$name}'] is not a string");
		}
		if (in_array($name, $model->getDates(), true)) {
			// Eloquent runs fromDateTime() on date columns before any cast
			$read = $cast !== null ? self::castType($cast, $column['type'], true) : self::DATE;
			$write = self::DATE . '|string';
		} elseif ($cast !== null) {
			$read = self::castType($cast, $column['type'], true);
			$write = self::castType($cast, $column['type'], false);
		} else {
			$read = $write = $column['type'];
		}
		if ($column['nullable']) {
			$read .= '|null';
			$write .= '|null';
		}
		return ['read' => Type::parseString($read), 'write' => Type::parseString($write)];
	}

	// Larastan's ModelCastHelper types, for the casts it knows natively
	private static function castType(string $cast, string $column, bool $read): string {
		[$cast] = explode(':', str_starts_with($cast, 'encrypted:') ? substr($cast, 10) : $cast, 2);
		return match ($cast) {
			'int', 'integer', 'timestamp' => in_array($column, ['int', 'non-negative-int'], true) ? $column : 'int',
			'real', 'float', 'double' => 'float',
			'decimal' => $read ? 'numeric-string' : 'numeric-string|int|float',
			'string' => 'string',
			'bool', 'boolean' => $read ? 'bool' : 'bool|0|1',
			'object' => '\stdClass',
			'array', 'json' => 'array<array-key, mixed>',
			'collection' => '\Illuminate\Support\Collection',
			'date', 'datetime' => self::DATE,
			'immutable_date', 'immutable_datetime' => '\Carbon\CarbonImmutable',
			default => enum_exists($cast) ? '\\' . ltrim($cast, '\\') : 'mixed',
		};
	}

	private static function model(string $class): ?Model {
		if (!array_key_exists($class, self::$models)) {
			// as Larastan: no constructor, so no boot()
			self::$models[$class] = is_a($class, Model::class, true)
				? (new ReflectionClass($class))->newInstanceWithoutConstructor()
				: null;
		}
		return self::$models[$class];
	}

	/** @return array<string, array<string, array{type: string, nullable: bool}>> */
	private static function tables(): array {
		if (self::$tables !== null) {
			return self::$tables;
		}
		$parser = (new SqlParserFactory(new IamcalSqlParser()))->create();
		$converter = new MySqlDataTypeToPhpTypeConverter();
		$pattern = rtrim(self::codebase()->config->base_dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . self::SCHEMA;
		$files = glob($pattern);
		if ($files === false) {
			throw new RuntimeException("glob() failed for {$pattern}");
		}
		sort($files);

		$tables = [];
		foreach ($files as $file) {
			$sql = file_get_contents($file);
			if ($sql === false) {
				continue;
			}
			try {
				$definitions = $parser->parseTables($sql);
			} catch (SqlParserFailure $e) {
				throw new RuntimeException("$file: " . $e->getMessage(), 0, $e);
			}
			// the first definition of a table wins, as for Larastan
			foreach ($definitions as $table) {
				if (isset($tables[$table->name])) {
					continue;
				}
				$tables[$table->name] = [];
				foreach ($table->columns as $column) {
					$tables[$table->name][$column->name] = [
						'type' => $converter->convert($column->type, $column->typeOptions, $column->values),
						'nullable' => $column->nullable,
					];
				}
			}
		}
		return self::$tables = $tables;
	}

	private static function codebase(): Codebase {
		return self::$codebase ?? throw new RuntimeException('EloquentProperties: no codebase yet');
	}
}
