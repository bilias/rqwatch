<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The fuzzy API's own checks: a calling user, an action (learn, unlearn or
 boost), an id of 1 or more in digits, a flag for learn, a weight for
 boost and any weight 1-65535, else 400. With fuzzy learning off it
 answers 501 naming the node (not 403: the caller reads that as an auth
 or ACL problem). An unexpected error is a 500 that keeps the error itself
 in the log only. Learning itself needs the database and rspamd: the
 integration tests cover it.
*/

namespace Tests\Unit\Api;

use App\Api\FuzzyMailApi;
use App\Configuration\Config;
use App\Core\App;
use App\Core\AppContainer;
use App\Core\Database\MigrationStatus;
use App\Inventory\Migrations;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class FuzzyMailApiTest extends ApiTestCase
{
	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->setEnv('MY_API_SERVER_ALIAS', 'mx1');
	}

	/** @param array<string, mixed> $post */
	private function handle(array $post): Response {
		return $this->answer(fn () => (new FuzzyMailApi(Request::create('/api/fuzzy_mail.php', 'POST', $post)))->handle());
	}

	public function testAclAndCredentials(): void {
		$this->setEnv('MAIL_API_ACL', ' 10.0.0.1, ,' . self::CLIENT . ',, ::1 ');
		$api = new FuzzyMailApi(Request::create('/api/fuzzy_mail.php', 'POST'));
		$this->assertSame(['10.0.0.1', self::CLIENT, '::1'], (new ReflectionMethod($api, 'getAllowedIps'))->invoke($api));
		$this->assertSame(['rspamd', 's3cret-pass'], (new ReflectionMethod($api, 'getAuthCredentials'))->invoke($api));
	}

	/** @return array<string, array{array<string, string>}> */
	public static function invalid(): array {
		$learn = ['remote_user' => 'alice', 'action' => 'learn', 'id' => '5', 'flag' => '1'];
		return [
			'no user' => [array_diff_key($learn, ['remote_user' => 1])],
			'empty user' => [['remote_user' => ''] + $learn],
			'no action' => [array_diff_key($learn, ['action' => 1])],
			'unknown action' => [['action' => 'forget'] + $learn],
			'no id' => [array_diff_key($learn, ['id' => 1])],
			'id 0' => [['id' => '0'] + $learn],
			'id not digits' => [['id' => '5abc'] + $learn],
			'learn without a flag' => [array_diff_key($learn, ['flag' => 1])],
			'learn with flag 0' => [['flag' => '0'] + $learn],
			'learn with a flag not digits' => [['flag' => 'x'] + $learn],
			'boost without a weight' => [['action' => 'boost'] + $learn],
			'weight 0' => [['weight' => '0'] + $learn],
			'weight over 65535' => [['weight' => '65536'] + $learn],
			'weight not digits' => [['weight' => '-5'] + $learn],
		];
	}

	/** @param array<string, string> $post */
	#[DataProvider('invalid')]
	public function testInvalid(array $post): void {
		$this->assertAnswer(400, 'Missing Required info', $this->handle($post));
		$line = "critical [FuzzyMailApi] 192.0.2.7 requested FuzzyMailApi on 'mx1' with missing or invalid user, action, id, flag or weight";
		$this->assertSame([$line, $line], $this->log->lines);
	}

	public function testNotSingleValue(): void {
		$this->assertAnswer(400, 'Missing Required info', $this->handle(['remote_user' => 'alice', 'action' => 'learn', 'id' => ['5'], 'flag' => '1']));
		$line = "critical [FuzzyMailApi] 192.0.2.7 requested FuzzyMailApi with 'id' that is not a single value";
		$this->assertSame([$line, $line], $this->log->lines);
	}

	/** @return array<string, array{array<string, string>, string, string}> a valid request, its action, its id */
	public static function valid(): array {
		return [
			'learn' => [['action' => 'learn', 'id' => '5', 'flag' => '1'], 'learn', '5'],
			'learn with a weight' => [['action' => 'learn', 'id' => '5', 'flag' => '255', 'weight' => '1'], 'learn', '5'],
			'unlearn id 1' => [['action' => 'unlearn', 'id' => '1'], 'unlearn', '1'],
			'boost' => [['action' => 'boost', 'id' => '5', 'weight' => '65535'], 'boost', '5'],
		];
	}

	// fuzzy_learn_enable unset: off
	/** @param array<string, string> $post */
	#[DataProvider('valid')]
	public function testDisabled(array $post, string $action, string $id): void {
		$this->assertAnswer(501, 'Fuzzy learning is disabled on mx1', $this->handle($post + ['remote_user' => 'alice']));
		$line = "warning [FuzzyMailApi] alice via 192.0.2.7 requested fuzzy {$action} of id {$id} on 'mx1' but fuzzy learning is disabled";
		$this->assertSame([$line, $line], $this->log->lines);
	}

	// no database here: the lookup fails, and the client is told no more than that
	/** @param array<string, string> $post */
	#[DataProvider('valid')]
	public function testUnexpectedError(array $post, string $action, string $id): void {
		Config::set('fuzzy_learn_enable', true);
		$status = new MigrationStatus(null, $this->log);
		$status->setMigrationState(Migrations::MAIL_LOG_FUZZY, Migrations::STATUS_COMPLETED);
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, null, $status, null, false));

		$this->assertAnswer(500, 'Internal error', $this->handle($post + ['remote_user' => 'alice']));
		$line = "error [FuzzyMailApi] alice via 192.0.2.7 fuzzy {$action} of id {$id} on 'mx1' failed: Call to a member function connection() on null";
		$this->assertSame([$line, $line], $this->log->lines);
	}
}
