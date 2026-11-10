<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Row types of Connection::select() and selectOne() for PHPStan, as
 qa/PsalmPlugin/ConnectionRows.php gives them to Psalm: Laravel declares
 array and mixed, and the rows are stdClass, Laravel's default fetch mode
 (PDO::FETCH_OBJ), which rqwatch does not change. Larastan already stubs
 Connection, so this cannot be a stub file.
*/

namespace Qa\PHPStan;

use Illuminate\Database\Connection;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\Accessory\AccessoryArrayListType;
use PHPStan\Type\ArrayType;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\IntegerRangeType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use stdClass;

final class ConnectionRows implements DynamicMethodReturnTypeExtension
{
	#[\Override]
	public function getClass(): string {
		return Connection::class;
	}

	#[\Override]
	public function isMethodSupported(MethodReflection $methodReflection): bool {
		return in_array($methodReflection->getName(), ['select', 'selectOne'], true);
	}

	#[\Override]
	public function getTypeFromMethodCall(MethodReflection $methodReflection, MethodCall $methodCall, Scope $scope): Type {
		$row = new ObjectType(stdClass::class);
		if ($methodReflection->getName() === 'selectOne') {
			return TypeCombinator::addNull($row);
		}
		return TypeCombinator::intersect(
			new ArrayType(IntegerRangeType::fromInterval(0, null), $row),
			new AccessoryArrayListType()
		);
	}
}
