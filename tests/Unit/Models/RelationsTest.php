<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The models' relations, by their keys (their types are PHPStan's to
 check), and the order of the ordered ones, by the SQL they build. A MySQL
 connection is configured but never opened: building a query does not
 connect, so nothing here needs a database. A map entry's field reads as text or null
 and fails for any other type. A user's disable_notifications is stored as
 1 or 0; null (the default) is kept only once the migration making it
 nullable has run, before which it is 0.
*/

namespace Tests\Unit\Models;

use App\Core\App;
use App\Core\AppContainer;
use App\Core\Database\MigrationStatus;
use App\Inventory\Migrations;
use App\Models\CustomMapConfig;
use App\Models\MailAlias;
use App\Models\MailLog;
use App\Models\MailLogData;
use App\Models\MailLogFuzzy;
use App\Models\MailLogRecipient;
use App\Models\MailLogToken;
use App\Models\MapCombined;
use App\Models\User;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Unit\UnitTestCase;

final class RelationsTest extends UnitTestCase
{
	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$capsule = new Capsule();
		$capsule->addConnection(['driver' => 'mysql', 'host' => '192.0.2.1', 'database' => 'rqwatch', 'username' => 'none', 'password' => 'none']);
		$capsule->bootEloquent();
	}

	#[\Override]
	protected function tearDown(): void {
		Model::unsetConnectionResolver();
		parent::tearDown();
	}

	/** @return array<string, array{Model, string, class-string<Model>, string, string}> model, relation, related, foreign key, owner key */
	public static function belongsTo(): array {
		return [
			'mail_log_data' => [new MailLogData(), 'mailLog', MailLog::class, 'mail_log_id', 'id'],
			'mail_log_recipients' => [new MailLogRecipient(), 'mailLog', MailLog::class, 'mail_log_id', 'id'],
			'mail_log_tokens' => [new MailLogToken(), 'mailLog', MailLog::class, 'mail_log_id', 'id'],
			'mail_log_fuzzy' => [new MailLogFuzzy(), 'mailLog', MailLog::class, 'mail_log_id', 'id'],
			'maps_combined' => [new MapCombined(), 'user', User::class, 'user_id', 'id'],
			'mail_aliases' => [new MailAlias(), 'user', User::class, 'user_id', 'id'],
		];
	}

	/** @param class-string<Model> $related */
	#[DataProvider('belongsTo')]
	public function testBelongsTo(Model $model, string $relation, string $related, string $foreignKey, string $ownerKey): void {
		$built = $model->{$relation}();
		$this->assertInstanceOf(BelongsTo::class, $built);
		$this->assertInstanceOf($related, $built->getRelated());
		$this->assertSame($foreignKey, $built->getForeignKeyName());
		$this->assertSame($ownerKey, $built->getOwnerKeyName());
	}

	public function testMailLogRelations(): void {
		$recipients = (new MailLog())->recipients();
		$this->assertSame(['mail_log_id', 'id'], [$recipients->getForeignKeyName(), $recipients->getLocalKeyName()]);

		$data = (new MailLog())->mailLogData();
		$this->assertSame(['mail_log_id', 'id'], [$data->getForeignKeyName(), $data->getLocalKeyName()]);
	}

	// each in order
	public function testOrderedHasMany(): void {
		$aliases = (new User())->mailAliases();
		$this->assertSame(['user_id', 'id'], [$aliases->getForeignKeyName(), $aliases->getLocalKeyName()]);
		$this->assertStringEndsWith(' order by `alias` asc', $aliases->toSql());

		$entries = (new CustomMapConfig())->MapsCustom();
		$this->assertSame(['map_name', 'map_name'], [$entries->getForeignKeyName(), $entries->getLocalKeyName()]);
		$this->assertStringEndsWith(' order by `map_name` asc', $entries->toSql());
	}

	public function testFieldValue(): void {
		$entry = (new MapCombined())->setRawAttributes(['mail_from' => 'spammer@example.org', 'rcpt_to' => null, 'user_id' => 3]);
		$this->assertSame('spammer@example.org', $entry->fieldValue('mail_from'));
		$this->assertNull($entry->fieldValue('rcpt_to'));
		$this->assertNull($entry->fieldValue('ip'), 'not loaded: none');
		$this->expectException(LogicException::class);
		$this->expectExceptionMessage("Map entry field 'user_id' must be text, not int");
		$entry->fieldValue('user_id');
	}

	/** @return array<string, array{bool|null, bool, int|null}> value, nullable migration done, stored */
	public static function notifications(): array {
		return [
			'on' => [false, true, 0],
			'off' => [true, true, 1],
			'off, before the migration' => [true, false, 1],
			'default' => [null, true, null],
			'default, before the migration: on' => [null, false, 0],
		];
	}

	#[DataProvider('notifications')]
	public function testDisableNotifications(?bool $value, bool $migrated, ?int $stored): void {
		$status = new MigrationStatus(null, $this->log);
		if ($migrated) {
			$status->setMigrationState(Migrations::USER_NOTIFICATIONS_DEFAULT, Migrations::STATUS_COMPLETED);
		}
		App::swap(new AppContainer(0.0, 0, $this->log, $this->log, null, $status, null, false));
		$user = new User();
		$user->disable_notifications = $value;
		$this->assertSame($stored, $user->getAttributes()['disable_notifications']);
	}
}
