<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The mail API's own checks, before it looks the mail up: a calling user
 and a mail id of 1 or more in digits, each a single value, else 400 and a
 critical log line naming the id as sent. Its ACL and
 credentials are MAIL_API_*. Looking the mail up needs the database: the
 integration tests cover it.
*/

namespace Tests\Unit\Api;

use App\Api\GetMailApi;
use App\Core\App;
use App\Core\AppContainer;
use App\Core\Database\MigrationStatus;
use Error;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\Request;

final class GetMailApiTest extends ApiTestCase
{
	/** @param array<string, mixed> $post */
	private function handle(array $post): void {
		(new GetMailApi(Request::create('/api/get_mail.php', 'POST', $post)))->handle();
	}

	public function testAclAndCredentials(): void {
		$this->setEnv('MAIL_API_ACL', ' 10.0.0.1, ,' . self::CLIENT . ',, ::1 ');
		$api = new GetMailApi(Request::create('/api/get_mail.php', 'POST'));
		$this->assertSame(['10.0.0.1', self::CLIENT, '::1'], (new ReflectionMethod($api, 'getAllowedIps'))->invoke($api));
		$this->assertSame(['rspamd', 's3cret-pass'], (new ReflectionMethod($api, 'getAuthCredentials'))->invoke($api));
	}

	/** @return array<string, array{array<string, string>}> */
	public static function noUser(): array {
		return ['none' => [['id' => '5']], 'empty' => [['remote_user' => '', 'id' => '5']], 'zero' => [['remote_user' => '0', 'id' => '5']]];
	}

	/** @param array<string, string> $post */
	#[DataProvider('noUser')]
	public function testNoUser(array $post): void {
		$this->assertAnswer(400, 'Missing Required info', $this->answer(fn () => $this->handle($post)));
		$line = 'critical [GetMailApi] 192.0.2.7 requested mail without a calling user email';
		$this->assertSame([$line, $line], $this->log->lines);
	}

	/** @return array<string, array{array<string, string>, string}> */
	public static function badIds(): array {
		return [
			'none' => [[], ''],
			'zero' => [['id' => '0'], '0'],
			'negative' => [['id' => '-3'], '-3'],
			'not a number' => [['id' => 'abc'], 'abc'],
			'trailing text' => [['id' => '5abc'], '5abc'],
		];
	}

	/** @param array<string, string> $post */
	#[DataProvider('badIds')]
	public function testBadId(array $post, string $id): void {
		$this->assertAnswer(400, 'Missing Required info', $this->answer(fn () => $this->handle($post + ['remote_user' => 'alice@example.org'])));
		$line = "critical [GetMailApi] alice@example.org via 192.0.2.7 requested mail with invalid mail id '{$id}'";
		$this->assertSame([$line, $line], $this->log->lines);
	}

	/** @return array<string, array{string}> */
	public static function notSingle(): array {
		return ['remote_user' => ['remote_user'], 'id' => ['id']];
	}

	#[DataProvider('notSingle')]
	public function testNotSingleValue(string $field): void {
		$post = [$field => ['x']] + ['remote_user' => 'alice@example.org', 'id' => '5'];
		$this->assertAnswer(400, 'Missing Required info', $this->answer(fn () => $this->handle($post)));
		$line = "critical [GetMailApi] 192.0.2.7 requested GetMailApi with '{$field}' that is not a single value";
		$this->assertSame([$line, $line], $this->log->lines);
	}

	// past every check, to the lookup with the id as a number: no database here
	public function testValidGoesToTheLookup(): void {
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, null, new MigrationStatus(null, $this->log), null, false));
		$this->expectException(Error::class);
		$this->expectExceptionMessage('Call to a member function connection() on null');
		$this->handle(['remote_user' => 'alice@example.org', 'id' => '5']);
	}
}
