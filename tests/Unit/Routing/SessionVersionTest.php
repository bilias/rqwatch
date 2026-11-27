<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 AuthMiddleware's session_version check, once its migration is
 completed, on RecordingDatabase: a session passes while its version is
 the user's in users; a bumped version, a user gone, or a session from
 before the version (none in it) ends the session, logged, with the
 message to log in again.
*/

namespace Tests\Unit\Routing;

use App\Core\App;
use App\Core\AppContainer;
use App\Core\Database\MigrationStatus;
use App\Core\Middleware\AuthMiddleware;
use App\Core\Routing\Routes;
use App\Inventory\Migrations;
use Illuminate\Database\Eloquent\Model;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Tests\Unit\RecordingDatabase;
use Tests\Unit\UnitTestCase;

final class SessionVersionTest extends UnitTestCase
{
	private const string READ = 'select `session_version` from `users` where `users`.`id` = ? limit 1';

	private RecordingDatabase $db;

	/** @var array<int, int|null> user id => session_version */
	private array $users = [7 => 3];

	private bool $reached = false;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->db = new RecordingDatabase($this->createStub(PDO::class));
		$this->db->capsule->bootEloquent();
		$this->db->answer = function (string $sql, array $bindings): array {
			/** @var mixed $id */
			$id = $bindings[0] ?? null;
			return is_int($id) && array_key_exists($id, $this->users) ? [['session_version' => $this->users[$id]]] : [];
		};
		$status = new MigrationStatus(null, $this->log);
		$status->setMigrationState(Migrations::SESSION_VERSION, Migrations::STATUS_COMPLETED);
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, $this->db->capsule, $status, null, false));
	}

	#[\Override]
	protected function tearDown(): void {
		Model::unsetConnectionResolver();
		RecordingDatabase::forget();
		parent::tearDown();
	}

	/** @param array<string, mixed> $session */
	private function handle(array $session): Response {
		$request = Request::create('/mail/42');
		$s = new Session(new MockArraySessionStorage());
		$s->replace(['username' => 'alice', 'auth_provider' => 'DB', 'is_admin' => false] + $session);
		$request->setSession($s);
		$urls = new UrlGenerator(Routes::load()['routes'], new RequestContext());
		return (new AuthMiddleware($urls, $this->log))->handle($request, function (Request $r): Response {
			$this->reached = true;
			return new Response('controller');
		});
	}

	public function testSameVersionPasses(): void {
		$response = $this->handle(['user_id' => 7, 'session_version' => 3]);
		$this->assertTrue($this->reached);
		$this->assertSame('controller', $response->getContent());
		$this->assertSame([[self::READ, [7]]], $this->db->selects);
		$this->assertSame([], $this->log->lines);
	}

	/** @return array<string, array{array<string, mixed>}> */
	public static function ended(): array {
		return [
			'version bumped' => [['user_id' => 7, 'session_version' => 2]],
			'user gone' => [['user_id' => 8, 'session_version' => 3]],
			'no user id' => [['session_version' => 3]],
			'no version in the session' => [['user_id' => 7]],
		];
	}

	/** @param array<string, mixed> $session */
	#[DataProvider('ended')]
	public function testSessionEnded(array $session): void {
		$response = $this->handle($session);
		$this->assertFalse($this->reached);
		$this->assertInstanceOf(RedirectResponse::class, $response);
		$this->assertSame('/login', $response->getTargetUrl());
		$this->assertSame(["info Session of 'alice' ended: account changed"], $this->log->lines);
		$this->assertCount(1, $this->db->selects);
	}

	public function testNoUserIdReadsNone(): void {
		$this->users[0] = 3;
		$this->handle(['session_version' => 3]);
		$this->assertSame([[self::READ, [0]]], $this->db->selects);
	}
}
