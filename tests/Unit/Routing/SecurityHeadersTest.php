<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The headers every web response gets: a strict Content-Security-Policy
 (inline styles only where a controller asks, for mail previews), no
 sniffing, no framing, no browser features, same-origin isolation and,
 over https, HSTS. A controller's own Referrer-Policy and CSP are kept.
 A route's middleware is one of the two classes; anything else is refused.
*/

namespace Tests\Unit\Routing;

use App\Core\Middleware\AuthMiddleware;
use App\Core\Middleware\Authorization;
use App\Core\Routing\Router;
use App\Core\Routing\Routes;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Tests\Unit\UnitTestCase;

final class SecurityHeadersTest extends UnitTestCase
{
	private const string CSP = "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; "
		. "form-action 'self'; frame-ancestors 'none'; base-uri 'self'; object-src 'none'";

	public function testContentSecurityPolicy(): void {
		$this->assertSame(self::CSP, Router::contentSecurityPolicy());
		$this->assertSame(str_replace("style-src 'self'", "style-src 'self' 'unsafe-inline'", self::CSP), Router::contentSecurityPolicy(true));
	}

	/** @return array<string, string> header => value */
	private function headers(Response $response, string $scheme): array {
		$this->setEnv('WEB_SCHEME', $scheme);
		(new ReflectionMethod(Router::class, 'applySecurityHeaders'))->invoke(null, $response);
		$headers = [];
		foreach ($response->headers->all() as $name => $values) {
			if (!in_array($name, ['cache-control', 'date'], true)) {
				$headers[$name] = implode(', ', array_map(strval(...), $values));
			}
		}
		ksort($headers);
		return $headers;
	}

	/** @return array<string, string> */
	private static function expected(): array {
		return [
			'content-security-policy' => self::CSP,
			'cross-origin-opener-policy' => 'same-origin',
			'cross-origin-resource-policy' => 'same-origin',
			'permissions-policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
			'referrer-policy' => 'strict-origin-when-cross-origin',
			'x-content-type-options' => 'nosniff',
			'x-frame-options' => 'DENY',
		];
	}

	public function testHttp(): void {
		$this->assertSame(self::expected(), $this->headers(new Response(), 'http'));
	}

	public function testHttps(): void {
		$expected = self::expected() + ['strict-transport-security' => 'max-age=31536000; includeSubDomains'];
		ksort($expected);
		$this->assertSame($expected, $this->headers(new Response(), 'https'));
	}

	public function testControllerPoliciesKept(): void {
		$response = new Response();
		$response->headers->set('Referrer-Policy', 'no-referrer');
		$response->headers->set('Content-Security-Policy', Router::contentSecurityPolicy(inlineStyles: true));
		$expected = array_replace(self::expected(), [
			'referrer-policy' => 'no-referrer',
			'content-security-policy' => Router::contentSecurityPolicy(true),
		]);
		$this->assertSame($expected, $this->headers($response, 'http'));
	}

	/** @return array<string, array{class-string}> */
	public static function middlewares(): array {
		return ['auth' => [AuthMiddleware::class], 'authorization' => [Authorization::class]];
	}

	/** @param class-string $class */
	#[DataProvider('middlewares')]
	public function testMiddleware(string $class): void {
		$this->assertInstanceOf($class, self::resolve($class));
	}

	public function testUnknownMiddlewareRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Unknown middleware class in route');
		self::resolve(Router::class);
	}

	private static function resolve(string $class): mixed {
		$generator = new UrlGenerator(Routes::load()['routes'], new RequestContext());
		return (new ReflectionMethod(Router::class, 'resolveMiddleware'))->invoke(new Router(), $class, $generator);
	}
}
