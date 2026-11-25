<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 LoginController::oidcBaseUrl(), the origin OIDC callback and logout URLs are
 built on: the requested origin when WEB_SCHEME://WEB_HOST,
 WEB_SCHEME://WEB_HOST_NOTIFICATIONS or an OPENIDC_TRUSTED_HOSTS entry is the
 same origin (scheme, host and port, a default port being none); otherwise
 WEB_SCHEME://WEB_HOST, logged.
*/

namespace Tests\Unit\Controllers;

use App\Controllers\LoginController;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\Request;
use Tests\Unit\UnitTestCase;

final class OidcBaseUrlTest extends UnitTestCase
{
	private const string FALLBACK = 'https://rq.example';

	/** @return array<string, array{string, string}> requested URL, base URL */
	public static function requests(): array {
		return [
			'WEB_HOST' => ['https://rq.example/login', 'https://rq.example'],
			'WEB_HOST on 443' => ['https://rq.example:443/login', 'https://rq.example'],
			'WEB_HOST_NOTIFICATIONS' => ['https://notify.example/login', 'https://notify.example'],
			'trusted' => ['https://rq/login', 'https://rq'],
			'trusted on 443' => ['https://rq:443/login', 'https://rq'],
			'trusted with its port' => ['https://rq.internal:8443/login', 'https://rq.internal:8443'],
			'trusted, upper case' => ['https://RQ.Internal:8443/login', 'https://rq.internal:8443'],
			'trusted http' => ['http://plain/login', 'http://plain'],
			'trusted http on 80' => ['http://plain:80/login', 'http://plain'],
		];
	}

	#[DataProvider('requests')]
	public function testTrusted(string $url, string $base): void {
		$this->assertSame($base, $this->baseUrl($url));
		$this->assertSame([], $this->log->lines);
	}

	/** @return array<string, array{string, string}> requested URL, origin logged */
	public static function untrusted(): array {
		return [
			'another host' => ['https://evil.example/login', 'https://evil.example'],
			'WEB_HOST on another port' => ['https://rq.example:8443/login', 'https://rq.example:8443'],
			'WEB_HOST over http' => ['http://rq.example/login', 'http://rq.example'],
			'trusted on another port' => ['https://rq:8443/login', 'https://rq:8443'],
			'trusted without its port' => ['https://rq.internal/login', 'https://rq.internal'],
			'trusted http over https' => ['https://plain/login', 'https://plain'],
		];
	}

	#[DataProvider('untrusted')]
	public function testUntrustedFallsBack(string $url, string $logged): void {
		$this->assertSame(self::FALLBACK, $this->baseUrl($url));
		$this->assertLogged("OPENIDC: untrusted origin '{$logged}'");
	}

	// a host name alone (the form before origins) trusts nothing; EnvValidator refuses it at boot
	public function testBareHostEntryTrustsNothing(): void {
		$this->assertSame(self::FALLBACK, $this->baseUrl('https://rq/login', 'rq'));
		$this->assertLogged("OPENIDC: untrusted origin 'https://rq'");
	}

	public function testUnsetTrustsWebHostsOnly(): void {
		$this->assertSame('https://notify.example', $this->baseUrl('https://notify.example/login', null));
		$this->assertSame(self::FALLBACK, $this->baseUrl('https://rq/login', null));
	}

	private function baseUrl(string $url, ?string $trusted = 'https://rq, https://RQ.internal:8443,,http://plain:80'): mixed {
		$this->setEnv('WEB_SCHEME', 'https');
		$this->setEnv('WEB_HOST', 'rq.example');
		$this->setEnv('WEB_HOST_NOTIFICATIONS', 'notify.example');
		$this->setEnv('OPENIDC_TRUSTED_HOSTS', $trusted);

		$controller = (new ReflectionClass(LoginController::class))->newInstanceWithoutConstructor();
		(new ReflectionProperty($controller, 'request'))->setValue($controller, Request::create($url));
		(new ReflectionProperty($controller, 'fileLogger'))->setValue($controller, $this->log);
		return (new ReflectionMethod($controller, 'oidcBaseUrl'))->invoke($controller);
	}
}
