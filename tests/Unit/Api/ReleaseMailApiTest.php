<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The release API's own checks, before it looks the mail up, each refused
 400 with a critical log line: the calling user (it may be empty: a web
 user without an e-mail) and the mail id as single values, the mail id
 present and of 1 or more in digits, and a list of valid destination addresses (blanks around one
 allowed). Looking the mail up and releasing it need the database and a
 mailer: the integration tests cover those.
*/

namespace Tests\Unit\Api;

use App\Api\ReleaseMailApi;
use App\Core\App;
use App\Core\AppContainer;
use App\Core\Database\MigrationStatus;
use Error;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\Request;

final class ReleaseMailApiTest extends ApiTestCase
{
	/** @param array<string, mixed> $post */
	private function refused(array $post, string $status, string $log): void {
		$response = $this->answer(fn () => (new ReleaseMailApi(Request::create('/api/release_mail.php', 'POST', $post)))->handle());
		$this->assertAnswer(400, $status, $response);
		$line = "critical [ReleaseMailApi] {$log}";
		$this->assertSame([$line, $line], $this->log->lines);
	}

	public function testAclAndCredentials(): void {
		$this->setEnv('MAIL_API_ACL', ' 10.0.0.1, ,' . self::CLIENT . ',, ::1 ');
		$api = new ReleaseMailApi(Request::create('/api/release_mail.php', 'POST'));
		$this->assertSame(['10.0.0.1', self::CLIENT, '::1'], (new ReflectionMethod($api, 'getAllowedIps'))->invoke($api));
		$this->assertSame(['rspamd', 's3cret-pass'], (new ReflectionMethod($api, 'getAuthCredentials'))->invoke($api));
	}

	public function testNoUser(): void {
		$this->refused(['id' => '5', 'email' => ['a@example.org']], 'Missing Required info',
			'192.0.2.7 requested ReleaseMailApi without a calling user email');
	}

	public function testUserNotSingle(): void {
		$this->refused(['remote_user' => ['alice'], 'id' => '5', 'email' => ['a@example.org']], 'Missing Required info',
			"192.0.2.7 requested ReleaseMailApi with 'remote_user' that is not a single value");
	}

	public function testNoId(): void {
		$this->refused(['remote_user' => 'alice', 'email' => ['a@example.org']], 'Missing Required info',
			'alice via 192.0.2.7 requested mail release without a mail id');
	}

	// digits only: intval() would read "5abc" as 5
	/** @return array<string, array{string}> */
	public static function badIds(): array {
		return ['trailing text' => ['5abc'], 'zero' => ['0'], 'negative' => ['-5'], 'empty' => ['']];
	}

	#[DataProvider('badIds')]
	public function testBadId(string $id): void {
		$this->refused(['remote_user' => 'alice', 'id' => $id, 'email' => ['a@example.org']], 'Missing Required info',
			"alice via 192.0.2.7 requested mail release with invalid mail id '{$id}'");
	}

	// an empty calling user passes: a web user without an e-mail
	public function testNoDestination(): void {
		$this->refused(['remote_user' => '', 'id' => '5'], 'Missing Required info',
			' via 192.0.2.7 requested mail release of mail with id 5 without a destination email');
	}

	/** @return array<string, array{mixed}> */
	public static function notLists(): array {
		return ['text' => ['a@example.org'], 'empty list' => [[]]];
	}

	#[DataProvider('notLists')]
	public function testDestinationNotAList(mixed $email): void {
		$this->refused(['remote_user' => 'alice', 'id' => '5', 'email' => $email], 'Missing Required info',
			'alice via 192.0.2.7 requested mail release of mail with id 5 with a non-array destination email');
	}

	/** @return array<string, array{list<mixed>}> */
	public static function invalidAddresses(): array {
		return [
			'not an address' => [['a@example.org', 'nobody']],
			'not text' => [['a@example.org', ['b@example.org']]],
			'empty' => [['']],
		];
	}

	/** @param list<mixed> $email */
	#[DataProvider('invalidAddresses')]
	public function testInvalidAddress(array $email): void {
		$this->refused(['remote_user' => 'alice', 'id' => '5', 'email' => $email], 'Invalid destination email',
			'alice via 192.0.2.7 requested mail release of mail with id 5 with an invalid destination email');
	}

	// past every check, to the lookup: no database here
	public function testValidGoesToTheLookup(): void {
		$request = Request::create('/api/release_mail.php', 'POST',
			['remote_user' => 'alice', 'id' => '5', 'email' => [' a@example.org ', 'b@example.org']]);
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, null, new MigrationStatus(null, $this->log), null, false));
		$this->expectException(Error::class);
		$this->expectExceptionMessage('Call to a member function connection() on null');
		(new ReleaseMailApi($request))->handle();
	}
}
