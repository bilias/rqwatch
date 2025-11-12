<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Utils;

use App\Core\RouteName;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class UrlHelper
{
	public static function generate(
		UrlGeneratorInterface $urlGenerator,
		RouteName $route,
		array $parameters = []
	): string {
		return $urlGenerator->generate($route->value, $parameters);
	}

}
