<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Helper::origin(), the form OPENIDC_TRUSTED_HOSTS entries are compared in:
 http(s)://host[:port] only, lower-case, the scheme's default port dropped,
 as Request::getSchemeAndHttpHost() gives the requested origin.
*/

namespace Tests\Unit\Helpers;

use App\Utils\Helper;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Tests\Unit\UnitTestCase;

final class OriginTest extends UnitTestCase
{
	/** @return array<string, array{string, ?string}> value, origin */
	public static function values(): array {
		return [
			'https' => ['https://rq.example', 'https://rq.example'],
			'https on 443' => ['https://rq.example:443', 'https://rq.example'],
			'http on 80' => ['http://rq.example:80', 'http://rq.example'],
			'https on 80' => ['https://rq.example:80', 'https://rq.example:80'],
			'http on 443' => ['http://rq.example:443', 'http://rq.example:443'],
			'other port' => ['https://rq.example:8443', 'https://rq.example:8443'],
			'leading zero port' => ['https://rq.example:08443', 'https://rq.example:8443'],
			'upper case' => ['HTTPS://RQ.Example:8443', 'https://rq.example:8443'],
			'short name' => ['https://rq', 'https://rq'],
			'IPv4' => ['http://192.0.2.1:8080', 'http://192.0.2.1:8080'],
			'IPv6' => ['https://[2001:DB8::1]:8443', 'https://[2001:db8::1]:8443'],
			'bare host' => ['rq.example', null],
			'bare host with port' => ['rq.example:8443', null],
			'other scheme' => ['ftp://rq.example', null],
			'no host' => ['https://', null],
			'dots only' => ['https://..', null],
			'trailing slash' => ['https://rq.example/', null],
			'path' => ['https://rq.example/rqwatch', null],
			'query' => ['https://rq.example?x', null],
			'fragment' => ['https://rq.example#x', null],
			'user info' => ['https://user@rq.example', null],
			'empty port' => ['https://rq.example:', null],
			'port 0' => ['https://rq.example:0', null],
			'port 1' => ['https://rq.example:1', 'https://rq.example:1'],
			'port 65535' => ['https://rq.example:65535', 'https://rq.example:65535'],
			'port past 65535' => ['https://rq.example:65536', null],
			'port with text' => ['https://rq.example:443x', null],
			'blank around' => [' https://rq.example', null],
			'line feed' => ["https://rq.example\n", null],
			'empty' => ['', null],
		];
	}

	#[DataProvider('values')]
	public function testOrigin(string $value, ?string $origin): void {
		$this->assertSame($origin, Helper::origin($value));
	}

	/** @return array<string, array{string}> */
	public static function requests(): array {
		return [
			'https' => ['https://rq.example/login'],
			'https on 443' => ['https://rq.example:443/login'],
			'http on 80' => ['http://rq.example:80/login'],
			'other port' => ['https://rq.example:8443/login'],
			'upper case host' => ['https://RQ.Example:8443/login'],
		];
	}

	// what the request gives is already in this form, so the two compare as strings
	#[DataProvider('requests')]
	public function testRequestOriginIsInThisForm(string $url): void {
		$requested = Request::create($url)->getSchemeAndHttpHost();
		$this->assertSame($requested, Helper::origin($requested));
	}
}
