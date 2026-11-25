<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Core\Routing;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class UrlBuilder
{
	/** @param array<string, mixed> $parameters route parameters */
	public static function generate(
		UrlGeneratorInterface $urlGenerator,
		RouteName $route,
		array $parameters = []
	): string {
		return $urlGenerator->generate($route->value, $parameters);
	}

	/**
	 * $route's path on this app with $query as its query string, for a
	 * redirect to a GET results page. http_build_query() encodes every name
	 * and value (all but letters, digits and ._-), and the target stays
	 * $route's path: a route, not a URL, so no caller can pass another host.
	 * @param array<string, string> $query
	 * @psalm-taint-escape redirect
	 */
	public static function withQuery(UrlGeneratorInterface $urlGenerator, RouteName $route, array $query): string {
		return self::generate($urlGenerator, $route) . '?' . http_build_query($query);
	}

}
