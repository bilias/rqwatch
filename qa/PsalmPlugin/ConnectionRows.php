<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Row types of Connection::select() and selectOne(), which Laravel declares
 as array and mixed: rows are stdClass, Laravel's default fetch mode
 (PDO::FETCH_OBJ), which rqwatch does not change.
*/

namespace Qa\PsalmPlugin;

use Illuminate\Database\Connection;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type;
use Psalm\Type\Union;

final class ConnectionRows implements MethodReturnTypeProviderInterface
{
	/** @return list<string> */
	#[\Override]
	public static function getClassLikeNames(): array {
		return [Connection::class];
	}

	#[\Override]
	public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union {
		return match ($event->getMethodNameLowercase()) {
			'select' => Type::parseString('list<stdClass>'),
			'selectone' => Type::parseString('stdClass|null'),
			default => null,
		};
	}
}
