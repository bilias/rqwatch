<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 A redirect to a results page: the route's path on this app, the query
 encoded after it.
*/

namespace Tests\Unit\Routing;

use App\Core\Routing\RouteName;
use App\Core\Routing\Routes;
use App\Core\Routing\UrlBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;

final class UrlBuilderTest extends TestCase
{
	public function testWithQuery(): void {
		$generator = new UrlGenerator(Routes::load()['routes'], new RequestContext());
		$this->assertSame(
			'/admin/fuzzy/search?qid=Q1&from=a%26b%3Dc%40example.org',
			UrlBuilder::withQuery($generator, RouteName::ADMIN_FUZZY_SEARCH, ['qid' => 'Q1', 'from' => 'a&b=c@example.org'])
		);
	}
}
