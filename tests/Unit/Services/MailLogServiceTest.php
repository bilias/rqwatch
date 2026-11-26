<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 MailLogService without the database: the page sizes from the config or
 their defaults, and the relations a mail is loaded with. Admins and
 system callers (no user context: the mail APIs) get every recipient; a
 user only the recipients that are their own address or an alias, compared
 trimmed and in lower case. CLI_MODE is undefined here: a web request.
*/

namespace Tests\Unit\Services;

use App\Configuration\Config;
use App\Core\App;
use App\Core\AppContainer;
use App\Core\Database\MigrationStatus;
use App\Services\MailLogService;
use Closure;
use Illuminate\Database\Eloquent\Relations\Relation;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Tests\Unit\UnitTestCase;

final class MailLogServiceTest extends UnitTestCase
{
	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, null, new MigrationStatus(null, $this->log), null, false));
	}

	private static function property(MailLogService $service, string $name): mixed {
		return (new ReflectionProperty(MailLogService::class, $name))->getValue($service);
	}

	public function testPageSizeDefaults(): void {
		$service = new MailLogService();
		$this->assertSame(50, self::property($service, 'items_per_page'));
		$this->assertSame(31, self::property($service, 'q_items_per_page'));
		$this->assertSame(10000, self::property($service, 'max_items'));
	}

	public function testPageSizesFromConfig(): void {
		Config::set('items_per_page', 20);
		Config::set('q_items_per_page', 7);
		Config::set('max_items', 500);
		$service = new MailLogService();
		$this->assertSame(20, self::property($service, 'items_per_page'));
		$this->assertSame(7, self::property($service, 'q_items_per_page'));
		$this->assertSame(500, self::property($service, 'max_items'));
	}

	/** @return array<string, array{array{is_admin?: bool|null, username?: string|null, email?: string|null}|null}> */
	public static function allRecipients(): array {
		return [
			'no user context (mail APIs)' => [null],
			'empty user context' => [[]],
			'admin' => [['is_admin' => true, 'username' => 'root', 'email' => 'root@example.org']],
		];
	}

	/** @param array{is_admin?: bool|null, username?: string|null, email?: string|null}|null $context */
	#[DataProvider('allRecipients')]
	public function testAllRecipients(?array $context): void {
		$service = new MailLogService($context);
		$this->assertSame(['recipients', 'mailLogData:mail_log_id,symbols'], $service->getMailLogSymbolsRelations());
		$this->assertSame(['recipients', 'mailLogData'], $service->getMailLogRelations());
	}

	/** @return array<string, array{array{is_admin: bool, email: string|null, user_aliases?: array<int, string>}, array<int, string>}> */
	public static function ownRecipients(): array {
		return [
			'address and aliases' => [
				['is_admin' => false, 'email' => ' Alice@Example.ORG ', 'user_aliases' => ['Bob@example.org ', '']],
				[0 => 'alice@example.org', 1 => 'bob@example.org'],
			],
			'address only' => [['is_admin' => false, 'email' => 'alice@example.org'], [0 => 'alice@example.org']],
			'aliases only' => [['is_admin' => false, 'email' => null, 'user_aliases' => ['bob@example.org']], [1 => 'bob@example.org']],
		];
	}

	/**
	 * @param array{is_admin: bool, email: string|null, user_aliases?: array<int, string>} $context
	 * @param array<int, string> $emails
	 */
	#[DataProvider('ownRecipients')]
	public function testOwnRecipients(array $context, array $emails): void {
		$service = new MailLogService($context);
		foreach ([
			[$service->getMailLogSymbolsRelations(), 'mailLogData:mail_log_id,symbols'],
			[$service->getMailLogRelations(), 'mailLogData'],
		] as [$relations, $data]) {
			$this->assertSame(['recipients', 0], array_keys($relations));
			$this->assertSame($data, $relations[0]);
			$scope = $relations['recipients'];
			$this->assertInstanceOf(Closure::class, $scope);
			$relation = $this->createMock(Relation::class);
			$relation->expects($this->once())->method('__call')
				->with('whereIn', ['recipient_email', $emails])->willReturn($relation);
			$this->assertSame($relation, $scope($relation));
		}
	}
}
