<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Base for the tests qa/scoping-tests.sh runs against its instance: logged-in
 clients, the database, and every route with sample parameters. Route access
 levels come from the routes' own _middleware, which tests/Access pins to the
 reviewed lists.
*/

namespace Tests\Scoping;

use App\Core\Middleware\AuthMiddleware;
use App\Core\Middleware\Authorization;
use App\Core\Routing\Routes;
use App\Models\MailLog;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;

abstract class WebTestCase extends TestCase
{
	protected const ADMIN = 'admin';
	protected const USER = 'user';
	protected const PUBLIC = 'public';

	private static ?PDO $db = null;

	protected function setUp(): void {
		if (getenv('SCOPING_BASE_URL') === false) {
			$this->markTestSkipped('Run through qa/scoping-tests.sh');
		}
	}

	/** A client logged in as $user (password pw-$user, from the harness). */
	protected static function client(string $user): WebClient {
		$client = new WebClient((string) getenv('SCOPING_BASE_URL'));
		$client->login($user, "pw-{$user}");
		return $client;
	}

	protected static function anonymous(): WebClient {
		return new WebClient((string) getenv('SCOPING_BASE_URL'));
	}

	protected static function db(): PDO {
		return self::$db ??= new PDO(
			(string) getenv('SCOPING_DB_DSN'),
			(string) getenv('SCOPING_DB_USER'),
			(string) getenv('SCOPING_DB_PASS'),
			[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
		);
	}

	protected static function value(string $sql, int|string ...$params): mixed {
		$stmt = self::db()->prepare($sql);
		$stmt->execute($params);
		return $stmt->fetchColumn();
	}

	/** @return array<string, Route> every route, by name */
	protected static function routes(): array {
		return Routes::load()['routes']->all();
	}

	/** admin, user or public, from the route's _middleware */
	protected static function level(Route $route): string {
		return match ($route->getDefault('_middleware')) {
			[AuthMiddleware::class, Authorization::class] => self::ADMIN,
			[AuthMiddleware::class] => self::USER,
			['NO_MIDDLEWARE'] => self::PUBLIC,
			default => throw new RuntimeException('Unknown _middleware: ' . json_encode($route->getDefault('_middleware'))),
		};
	}

	/**
	 * Requests a route with sample parameters: GET when allowed, else POST
	 * without fields (so without a CSRF token).
	 * @return array{status: int, location: string, body: string}
	 */
	protected static function call(WebClient $client, string $name, Route $route): array {
		$path = self::path($name, $route);
		$methods = $route->getMethods();
		return $methods === [] || in_array('GET', $methods, true)
			? $client->get($path)
			: $client->post($path, []);
	}

	/**
	 * The route's path with sample parameters: fixture rows where the route
	 * reads one. A new parameter name fails here: add a sample for it.
	 */
	protected static function path(string $name, Route $route): string {
		$params = [];
		foreach ($route->compile()->getPathVariables() as $var) {
			$params[$var] = self::sample($name, $var);
		}
		$generator = new UrlGenerator(Routes::load()['routes'], new RequestContext());
		return $generator->generate($name, $params);
	}

	private static function sample(string $route, string $var): string {
		if ($var === 'id') {
			return match (true) {
				str_starts_with($route, 'admin_user') => (string) self::value("SELECT id FROM users WHERE username = 'alice'"),
				$route === 'admin_aliases_del' => (string) self::value('SELECT MIN(id) FROM mail_aliases'),
				str_starts_with($route, 'admin_maps_custom_') => (string) self::value('SELECT MIN(id) FROM custom_map_config'),
				str_contains($route, 'map_') => '201',
				default => '101',
			};
		}
		return match ($var) {
			'attach_id' => '0',
			'date' => date('Y-m-d'),
			'month' => date('Y-m'),
			'field' => MailLog::REPORT_DYN_FIELDS[0],
			'mode' => 'count',
			'type' => 'id',
			'value' => '101',
			'filter_id' => '0',
			'flag' => '1',
			'model' => 'MapCombined',
			'map' => 'mail_from_rcpt_to_whitelist',
			'ip' => '192.0.2.1',
			'handle' => str_repeat('0', 32),
			default => throw new RuntimeException("Route {$route}: no sample value for {{$var}}"),
		};
	}

	/**
	 * Runs $test with .env settings replaced (php -S rereads .env per request),
	 * then puts the file back.
	 * @param array<string, string> $settings
	 */
	protected static function withEnv(array $settings, callable $test): void {
		$env = getenv('SCOPING_ROOT') . '/.env';
		$original = (string) file_get_contents($env);
		$changed = $original;
		foreach ($settings as $key => $value) {
			$changed = (string) preg_replace('/^' . preg_quote($key, '/') . '=.*$/m', "{$key}={$value}", $changed, -1, $count);
			if ($count !== 1) {
				throw new RuntimeException(".env has no single {$key} line");
			}
		}
		file_put_contents($env, $changed);
		try {
			$test();
		} finally {
			file_put_contents($env, $original);
		}
	}

	/** Runs bin/cli.php in the instance's copy; fails on a non-zero exit. */
	protected static function cli(string ...$args): string {
		$cmd = 'cd ' . escapeshellarg((string) getenv('SCOPING_ROOT')) . ' && '
			. escapeshellarg(PHP_BINARY) . ' bin/cli.php ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
		exec($cmd, $out, $status);
		$output = implode("\n", $out);
		if ($status !== 0) {
			throw new RuntimeException('bin/cli.php ' . implode(' ', $args) . " exited {$status}: {$output}");
		}
		return $output;
	}

	/** The path of a redirect target, or '' for none. */
	protected static function target(array $response): string {
		return (string) parse_url($response['location'], PHP_URL_PATH);
	}
}
