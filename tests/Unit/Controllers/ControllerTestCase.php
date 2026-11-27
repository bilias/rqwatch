<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 A controller as the Router builds it, on RecordingDatabase (Eloquent
 booted) with a RecordingCache and a migration status of its own: each
 SELECT is answered by the first of $answers whose text it holds (rows,
 or a closure of the bindings giving them), with no rows when none does.
 The request has a session: an admin's (root, id 1) or a user's (alice,
 id 7, with an alias), or none logged in. A view controller gets its Twig
 environment built as twigView() builds it, but without a cache (which
 would be made in the checkout), and the form factory is built anew for
 each test (FormFactoryProvider keeps the first request's session for
 CSRF). The client is 192.0.2.7.
 The configuration is config/config.php's, without the rspamd stats (no
 controller to ask). framed() and assertFramed() check a page's frame: the
 quarantine-id box, the menu (the admin's, the page's entry active), a
 message waiting, the rspamd stats (from the cache) and the run time.
*/

namespace Tests\Unit\Controllers;

use App\Configuration\AppConfig;
use App\Configuration\Config;
use App\Controllers\Controller;
use App\Controllers\ViewController;
use App\Core\Form\FormFactoryProvider;
use App\Twig\RqwatchExtension;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use App\Core\App;
use App\Core\AppContainer;
use App\Core\Database\MigrationStatus;
use App\Core\Routing\RouteName;
use App\Core\Routing\Routes;
use App\Inventory\Migrations;
use App\Utils\Helper;
use Closure;
use Illuminate\Database\Eloquent\Model;
use PDO;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManager;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator;
use Symfony\Component\Security\Csrf\TokenStorage\SessionTokenStorage;
use Symfony\Component\HttpFoundation\RequestStack;
use Tests\Unit\RecordingCache;
use Tests\Unit\RecordingDatabase;
use Tests\Unit\UnitTestCase;

abstract class ControllerTestCase extends UnitTestCase
{
	protected const array ADMIN = ['username' => 'root', 'user_id' => 1, 'is_admin' => true, 'email' => 'root@example.org', 'auth_provider' => 'DB'];

	protected const array USER = ['username' => 'alice', 'user_id' => 7, 'is_admin' => false, 'email' => 'alice@example.org', 'user_aliases' => ['alias@example.org'], 'auth_provider' => 'DB'];

	protected RecordingDatabase $db;

	protected RecordingCache $cache;

	protected MigrationStatus $status;

	protected UrlGenerator $urls;

	/** @var list<array{string, list<array<string, mixed>>|Closure(list<mixed>): list<array<string, mixed>>}> */
	protected array $answers = [];

	private ?string $remoteAddr = null;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->remoteAddr = Helper::server_string('REMOTE_ADDR');
		$_SERVER['REMOTE_ADDR'] = '192.0.2.7';
		$this->db = new RecordingDatabase($this->createStub(PDO::class));
		$this->db->capsule->bootEloquent();
		$this->db->answer = $this->answer(...);
		$this->cache = new RecordingCache();
		$this->status = new MigrationStatus(null, $this->log);
		$this->status->setMigrationTableExists(true);
		foreach (Migrations::MIGRATIONS as $migration) {
			$this->status->setMigrationState($migration, Migrations::STATUS_COMPLETED);
		}
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, $this->db->capsule, $this->status, $this->cache, true));
		$this->urls = new UrlGenerator(Routes::load()['routes'], new RequestContext());
		(new ReflectionProperty(FormFactoryProvider::class, 'formFactory'))->setValue(null, null);
		Config::loadAndInit(AppConfig::CONFIG_DEFAULT_PATH, '');
		Config::set('rspamd_stat_disable', true);
	}

	#[\Override]
	protected function tearDown(): void {
		if ($this->remoteAddr === null) {
			unset($_SERVER['REMOTE_ADDR']);
		} else {
			$_SERVER['REMOTE_ADDR'] = $this->remoteAddr;
		}
		(new ReflectionProperty(FormFactoryProvider::class, 'formFactory'))->setValue(null, null);
		Model::unsetConnectionResolver();
		RecordingDatabase::forget();
		parent::tearDown();
	}

	/**
	 * @param list<mixed> $bindings
	 * @return list<array<string, mixed>>
	 */
	private function answer(string $sql, array $bindings): array {
		foreach ($this->answers as [$needle, $rows]) {
			if (str_contains($sql, $needle)) {
				return $rows instanceof Closure ? $rows($bindings) : $rows;
			}
		}
		return [];
	}

	/** @return array{string, list<mixed>}|null the first SELECT holding $needle: its SQL and bindings */
	protected function selected(string $needle): ?array {
		foreach ($this->db->selects as $select) {
			if (str_contains($select[0], $needle)) {
				return $select;
			}
		}
		return null;
	}

	/**
	 * @param array<string, mixed> $session what is logged in (ADMIN, USER), and more
	 * @param array<string, mixed> $query
	 * @param array<string, mixed> $post
	 */
	protected static function request(string $path = '/', array $session = [], array $query = [], array $post = [], string $method = 'GET'): Request {
		$request = Request::create($path, $method === 'GET' && $post !== [] ? 'POST' : $method, $post === [] ? $query : $post);
		if ($post !== []) {
			$request->query->replace($query);
		}
		$s = new Session(new MockArraySessionStorage());
		$s->replace($session);
		$request->setSession($s);
		return $request;
	}

	/**
	 * @template T of Controller
	 * @param T $controller
	 * @return T
	 */
	protected function controller(Controller $controller, Request $request): Controller {
		$controller->setRequest($request);
		$controller->setUrlGenerator($this->urls);
		if ($controller instanceof ViewController) {
			$twig = new Environment(new FilesystemLoader(AppConfig::VIEWS_PATH), [
				'cache' => false,
				'debug' => false,
				'auto_reload' => true,
				'autoescape' => 'html',
			]);
			$csrf = (new ReflectionMethod(Controller::class, 'csrfManager'))->getClosure($controller);
			$twig->addExtension(new RqwatchExtension(
				fn (): UrlGeneratorInterface => $this->urls,
				function () use ($csrf): CsrfTokenManagerInterface {
					$manager = $csrf();
					self::assertInstanceOf(CsrfTokenManagerInterface::class, $manager);
					return $manager;
				},
			));
			(new ReflectionProperty(ViewController::class, 'twig'))->setValue($controller, $twig);
			// its globals, as twigView() sets them on every call
			$controller->twigView();
		}
		return $controller;
	}

	// a CSRF token for $id in the request's session, as a page would carry it
	protected static function csrf(Request $request, string $id): string {
		$manager = new CsrfTokenManager(new UriSafeTokenGenerator(), new SessionTokenStorage(new RequestStack([$request])));
		return $manager->getToken($id)->getValue();
	}

	// the page carries a CSRF token for $id after $before (a token is salted anew each time it is shown)
	protected function assertCsrfAfter(string $before, string $html, Request $request, string $id): void {
		$this->assertSame(1, preg_match('#' . preg_quote($before, '#') . '([A-Za-z0-9._~%-]+)#', $html, $m), "no token after {$before}");
		$manager = new CsrfTokenManager(new UriSafeTokenGenerator(), new SessionTokenStorage(new RequestStack([$request])));
		$this->assertTrue($manager->isTokenValid(new CsrfToken($id, rawurldecode($m[1]))), "not a {$id} token");
	}

	/**
	 * the quarantine-id box of every page, submitted
	 * @param array<string, mixed> $session
	 * @param array<string, mixed> $query
	 */
	protected static function qidPosted(array $session, array $query = []): Request {
		$request = self::request('/', $session, $query);
		$request->setMethod('POST');
		$request->request->replace(['qid_form' => ['qid' => 'QID99X', '_token' => self::csrf($request, 'qid_form')]]);
		return $request;
	}

	protected function assertToQid(Response $response, bool $admin = true): void {
		$this->assertRedirect($this->urls->generate(($admin ? RouteName::ADMIN_DETAIL : RouteName::DETAIL)->value, ['type' => 'qid', 'value' => 'QID99X']), $response);
	}

	/** @return array<mixed> type => messages */
	protected static function flashes(Request $request): array {
		$session = $request->getSession();
		self::assertInstanceOf(Session::class, $session);
		return $session->getFlashBag()->peekAll();
	}

	protected static function flash(Request $request, string $type, string $message): void {
		$session = $request->getSession();
		self::assertInstanceOf(Session::class, $session);
		$session->getFlashBag()->add($type, $message);
	}

	// a message waiting, and rspamd stats in the cache, for assertFramed()
	protected function framed(Request $request): void {
		self::flash($request, 'info', 'Framed page');
		Config::set('rspamd_stat_disable', false);
		Config::set('rspamd_stat_redis_key', 'rqwatch_rspamd_stat');
		$this->cache->values['rqwatch_rspamd_stat'] = (string) json_encode(['mx1' => ['version' => '3.9', 'scanned' => 4242]]);
	}

	// $active: the menu entry of the page, by its text (null: none)
	protected function assertFramed(string $html, ?string $active): void {
		$this->assertStringContainsString('name="qid_form[qid]"', $html);
		$this->assertStringContainsString('<div class="flash-info">', $html);
		$this->assertStringContainsString('Framed page', $html);
		$this->assertStringContainsString('<th>mx1</th>', $html);
		$this->assertMatchesRegularExpression("/<div class='runtime'>\nExecution time: /", $html);
		if ($active === null) {
			$this->assertStringNotContainsString('<li class="active">', $html);
		} else {
			$this->assertMatchesRegularExpression('#<li class="active">\s*<a href="[^"]*">' . preg_quote($active, '#') . '</a>#', $html);
		}
		$this->assertStringContainsString('>Users</a>', $html);
	}

	protected function assertRedirect(string $url, Response $response): void {
		$this->assertInstanceOf(RedirectResponse::class, $response);
		$this->assertSame($url, $response->getTargetUrl());
	}
}
