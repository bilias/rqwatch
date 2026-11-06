<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Base for the tests qa/integration-tests.sh runs against its instance: logged-in
 clients, the database, every route with sample parameters, and the mail the
 instance sends (Mailpit). Route access levels come from the routes' own
 _middleware, which tests/Unit/Routing pins to the reviewed lists.
*/

namespace Tests\Integration;

use App\Core\Middleware\AuthMiddleware;
use App\Core\Middleware\Authorization;
use App\Core\Routing\Routes;
use App\Models\MailLog;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Mailpit;
use Tests\WebClient;
use RuntimeException;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;

abstract class IntegrationTestCase extends TestCase
{
	use Mailpit;

	protected const string ADMIN = 'admin';
	protected const string USER = 'user';
	protected const string PUBLIC = 'public';

	private static ?PDO $db = null;

	/** @var list<int> mails quarantined() created in this test */
	private array $quarantinedIds = [];

	#[\Override]
	protected function setUp(): void {
		if (getenv('INTEGRATION_BASE_URL') === false) {
			$this->markTestSkipped('Run through qa/integration-tests.sh');
		}
	}

	// removes what quarantined() created; tokens go with their recipient rows
	#[\Override]
	protected function tearDown(): void {
		if ($this->quarantinedIds !== []) {
			$ids = implode(',', $this->quarantinedIds);
			self::db()->exec("DELETE FROM mail_log_data WHERE mail_log_id IN ({$ids})");
			self::db()->exec("DELETE FROM mail_log_recipients WHERE mail_log_id IN ({$ids})");
			self::db()->exec("DELETE FROM mail_logs WHERE id IN ({$ids})");
			foreach ($this->quarantinedIds as $id) {
				@unlink(self::quarantineFile($id));
			}
			$this->quarantinedIds = [];
		}
		parent::tearDown();
	}

	/*
	 The instance's copy of the app, which qa/integration-tests.sh sets up.
	 Never empty: cli() would run bin/cli.php (cron:cleanupdb -d...) in the
	 current directory, and the file helpers would write under /q.
	*/
	protected static function root(): string {
		$root = getenv('INTEGRATION_ROOT');
		if ($root === false || $root === '') {
			throw new RuntimeException('INTEGRATION_ROOT is not set: run through qa/integration-tests.sh');
		}
		return $root;
	}

	/** A client logged in as $user (password pw-$user, from the harness). */
	protected static function client(string $user): WebClient {
		$client = new WebClient((string) getenv('INTEGRATION_BASE_URL'));
		$client->login($user, "pw-{$user}");
		return $client;
	}

	#[\Override]
	protected static function mailpitUrl(): string {
		return (string) getenv('INTEGRATION_MAILPIT_URL');
	}

	protected static function anonymous(): WebClient {
		return new WebClient((string) getenv('INTEGRATION_BASE_URL'));
	}

	protected static function db(): PDO {
		return self::$db ??= new PDO(
			(string) getenv('INTEGRATION_DB_DSN'),
			(string) getenv('INTEGRATION_DB_USER'),
			(string) getenv('INTEGRATION_DB_PASS'),
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
			default => throw new RuntimeException('Unknown _middleware: ' . json_encode($route->getDefault('_middleware'), JSON_THROW_ON_ERROR)),
		};
	}

	/**
	 * Requests a route with sample parameters: GET when allowed, else POST
	 * without fields (so without a CSRF token).
	 * @return array{status: int, location: string, body: string, headers: array<string, string>}
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
		$env = self::root() . '/.env';
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

	/**
	 * A quarantined mail stored on $server for $recipients, its file under
	 * QUARANTINE_DIR, removed after the test with its tokens. Returns its qid.
	 * $columns overrides mail_logs columns (action, score, mail_stored,
	 * admin_notified...); $symbols is the stored rspamd symbols report, by name.
	 * @param list<string> $recipients
	 * @param array<string, int|float|string|null> $columns
	 * @param array<string, array<string, mixed>> $symbols
	 */
	protected function quarantined(int $id, string $server, array $recipients = ['alice@example.org'],
			array $columns = [], array $symbols = []): string {
		$qid = "QIDQ{$id}";
		$rcpt = implode(', ', $recipients);
		file_put_contents(self::quarantineFile($id), "From: sender@example.net\r\nTo: {$rcpt}\r\n"
			. "Subject: SUBJ-Q-{$id}\r\nMessage-ID: <q-{$id}@example.net>\r\n\r\nBody of SUBJ-Q-{$id}\r\n");
		$row = array_merge([
			'id' => $id, 'qid' => $qid, 'server' => $server, 'subject' => "SUBJ-Q-{$id}",
			'score' => 15, 'action' => 'reject', 'ip' => '192.0.2.30',
			'mail_from' => 'sender@example.net', 'mime_from' => 'sender@example.net',
			'rcpt_to' => $rcpt, 'mime_to' => $rcpt, 'size' => 1000,
			'mail_stored' => 1, 'mail_location' => self::quarantineFile($id),
		], $columns);
		self::db()->prepare('INSERT INTO mail_logs (' . implode(', ', array_keys($row)) . ') VALUES ('
			. implode(', ', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
		foreach ($recipients as $recipient) {
			self::db()->prepare('INSERT INTO mail_log_recipients (mail_log_id, recipient_email) VALUES (?, ?)')
				->execute([$id, $recipient]);
		}
		$symbols = $symbols ?: ['TEST_SYMBOL' => ['name' => 'TEST_SYMBOL', 'score' => 15]];
		self::db()->prepare('INSERT INTO mail_log_data (mail_log_id, headers, symbols) VALUES (?, ?, ?)')
			->execute([$id, "Subject: SUBJ-Q-{$id}", json_encode($symbols, JSON_THROW_ON_ERROR)]);
		$this->quarantinedIds[] = $id;
		return $qid;
	}

	/**
	 * POSTs $fields to web/api/$script on the API server, authenticated as
	 * the mail API user a web node uses.
	 * @param array<string, mixed> $fields
	 * @return array{0: int, 1: string} status and body
	 */
	protected static function mailApi(string $script, array $fields): array {
		$ch = curl_init(rtrim((string) getenv('INTEGRATION_API_URL'), '/') . "/api/{$script}") ?: throw new RuntimeException('curl_init() failed');
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POSTFIELDS => http_build_query($fields),
			CURLOPT_USERPWD => (string) getenv('INTEGRATION_MAIL_API_USER') . ':' . (string) getenv('INTEGRATION_MAIL_API_PASS'),
			CURLOPT_TIMEOUT => 30,
		]);
		$body = curl_exec($ch);
		if (!is_string($body)) {
			throw new RuntimeException("{$script}: " . curl_error($ch));
		}
		return [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $body];
	}

	protected static function quarantineFile(int $id): string {
		return self::root() . "/q/{$id}.eml";
	}

	/**
	 * Exactly one release message for the mail $qid, delivered to exactly
	 * $recipients, with mail $id's quarantined file attached byte for byte.
	 * @param list<string> $recipients
	 * @return array<string, mixed> its Mailpit summary
	 */
	protected function assertReleaseDelivered(string $qid, array $recipients, int $id): array {
		$mails = array_values(array_filter(self::mailsFor($recipients[0], $qid),
			fn($mail) => $mail['Subject'] === 'Message released from quarantine'));
		$this->assertCount(1, $mails, "release messages for {$qid}");
		sort($recipients);
		$this->assertSame($recipients, self::recipientsOf($mails[0]));
		$this->assertSame((string) file_get_contents(self::quarantineFile($id)), self::attachedMail((string) $mails[0]['ID']),
			'attached mail differs from the quarantined one');
		return $mails[0];
	}

	protected function assertReleased(int $id): void {
		$this->assertSame(1, (int) self::value('SELECT released FROM mail_logs WHERE id = ?', $id), 'released');
		$this->assertNotNull(self::value('SELECT release_date FROM mail_logs WHERE id = ?', $id), 'release_date');
	}

	/** Runs bin/cli.php in the instance's copy; fails on a non-zero exit. */
	protected static function cli(string ...$args): string {
		$cmd = 'cd ' . escapeshellarg(self::root()) . ' && '
			. escapeshellarg(PHP_BINARY) . ' bin/cli.php ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
		exec($cmd, $out, $status);
		$output = implode("\n", $out);
		if ($status !== 0) {
			throw new RuntimeException('bin/cli.php ' . implode(' ', $args) . " exited {$status}: {$output}");
		}
		return $output;
	}

	/**
	 * Nothing in a quarantined mail's HTML preview can load from another
	 * origin: the srcdoc iframe inherits the page's CSP, so each fetch
	 * directive (or default-src, where it is absent) allows only 'self',
	 * 'none', data: or 'unsafe-inline'.
	 * @param array{status: int, location: string, body: string, headers: array<string, string>} $response
	 */
	protected function assertNoRemoteLoads(array $response, string $what): void {
		$csp = $response['headers']['content-security-policy'] ?? '';
		$this->assertNotSame('', $csp, "{$what}: Content-Security-Policy");
		$directives = [];
		foreach (explode(';', $csp) as $part) {
			$tokens = preg_split('/\s+/', trim($part), -1, PREG_SPLIT_NO_EMPTY) ?: [];
			if ($tokens !== []) {
				$directives[strtolower(array_shift($tokens))] = $tokens;
			}
		}
		$this->assertArrayHasKey('default-src', $directives, "{$what}: default-src");
		$default = $directives['default-src'] ?? [];
		foreach (['default-src', 'img-src', 'style-src', 'font-src', 'media-src', 'frame-src', 'connect-src', 'object-src'] as $name) {
			foreach ($directives[$name] ?? $default as $source) {
				$this->assertContains($source, ["'self'", "'none'", "'unsafe-inline'", 'data:'], "{$what}: {$name} allows {$source}");
			}
		}
	}

	/**
	 * The path of a redirect target, or '' for none.
	 *
	 * @param array{status: int, location: string, body: string, headers: array<string, string>} $response
	 */
	protected static function target(array $response): string {
		return (string) parse_url($response['location'], PHP_URL_PATH);
	}
}
