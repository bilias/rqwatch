<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Inventory;

use App\Core\Database\Migrations\AbstractMigration;
use App\Core\Database\Migrations\MailRecipientsMigration;
use App\Core\Database\Migrations\CreatedDayMigration;
use App\Core\Database\Migrations\MailLogDataMigration;
use App\Core\Database\Migrations\IdActionIndex;
use App\Core\Database\Migrations\MailLogTokensMigration;
use App\Core\Database\Migrations\IpCreatedDayIndex;
use App\Core\Database\Migrations\DropMailLogColumns;
use App\Core\Database\Migrations\DropMailLogIndexes;
use App\Core\Database\Migrations\MapUserConstraints;
use App\Core\Database\Migrations\UserNotificationsDefault;
use App\Core\Database\Migrations\AdminNotified;
use App\Core\Database\Migrations\MailLogFuzzyMigration;

use InvalidArgumentException;

class Migrations
{
	public const string MAIL_RECIPIENTS = '20260111_mail_recipients';
	public const string CREATED_DAY = '20260729_created_day';
	public const string MAIL_LOG_DATA = '20260731_mail_log_data';
	public const string ID_ACTION_INDEX = '20260806_id_action_index';
	public const string MAIL_LOG_TOKENS = '20260904_mail_log_tokens';
	public const string IP_CREATED_DAY_INDEX = '20260906_ip_created_day_index';
	public const string DROP_MAIL_LOG_COLUMNS = '20260908_drop_mail_log_columns';
	public const string DROP_MAIL_LOG_INDEXES = '20260911_drop_mail_log_indexes';
	public const string MAP_USER_CONSTRAINTS = '20260912_map_user_constraints';
	public const string USER_NOTIFICATIONS_DEFAULT = '20261005_user_notifications_default';
	public const string ADMIN_NOTIFIED = '20261005_admin_notified';
	public const string MAIL_LOG_FUZZY = '20261007_mail_log_fuzzy';

	public const array MIGRATIONS = [
		self::MAIL_RECIPIENTS,
		self::CREATED_DAY,
		self::MAIL_LOG_DATA,
		self::ID_ACTION_INDEX,
		self::MAIL_LOG_TOKENS,
		self::IP_CREATED_DAY_INDEX,
		self::DROP_MAIL_LOG_COLUMNS,
		self::DROP_MAIL_LOG_INDEXES,
		self::MAP_USER_CONSTRAINTS,
		self::USER_NOTIFICATIONS_DEFAULT,
		self::ADMIN_NOTIFIED,
		self::MAIL_LOG_FUZZY,
	];

	public const array REQUIRED = [
		self::MAIL_RECIPIENTS,
		self::CREATED_DAY,
		self::MAIL_LOG_DATA,
		// self::ID_ACTION_INDEX, ---> reverted/deleted - not needed any more
		self::MAIL_LOG_TOKENS,
		self::IP_CREATED_DAY_INDEX,
	];

	/*
	 Migrations db:migrate must not run. Membership in MIGRATIONS is not
	 optional -- MigrationStatus::getMigrationState() and
	 setMigrationState() both validate against it and throw, so a migration
	 left out of it could never record its own status. Exclusion therefore
	 lives here, and MigrateDb skips these.

	 DROP_MAIL_LOG_COLUMNS is destructive and irreversible, and rebuilds a
	 huge table with writes blocked. Nobody running db:migrate for an
	 unrelated migration should trigger that.
	*/
	public const array MANUAL_ONLY = [
		self::DROP_MAIL_LOG_COLUMNS,
	];

	/*
	 Superseded by a later migration. These never run again: the runner and
	 the migration itself record COMPLETED instead, including under --force.
	*/
	public const array SUPERSEDED = [
		self::ID_ACTION_INDEX => self::DROP_MAIL_LOG_INDEXES,
	];

	public const array MIGRATION_CLASSES = [
		self::MAIL_RECIPIENTS => MailRecipientsMigration::class,
		self::CREATED_DAY => CreatedDayMigration::class,
		self::MAIL_LOG_DATA => MailLogDataMigration::class,
		self::ID_ACTION_INDEX => IdActionIndex::class,
		self::MAIL_LOG_TOKENS => MailLogTokensMigration::class,
		self::IP_CREATED_DAY_INDEX => IpCreatedDayIndex::class,
		self::DROP_MAIL_LOG_COLUMNS => DropMailLogColumns::class,
		self::DROP_MAIL_LOG_INDEXES => DropMailLogIndexes::class,
		self::MAP_USER_CONSTRAINTS => MapUserConstraints::class,
		self::USER_NOTIFICATIONS_DEFAULT => UserNotificationsDefault::class,
		self::ADMIN_NOTIFIED => AdminNotified::class,
		self::MAIL_LOG_FUZZY => MailLogFuzzyMigration::class,
	];

	public const array MIGRATION_DESCR = [
		self::MAIL_RECIPIENTS => "Mail Log Recipients",
		self::CREATED_DAY => "Mail Log Created Day",
		self::MAIL_LOG_DATA => "Mail Log Data",
		self::ID_ACTION_INDEX => "id action Index",
		self::MAIL_LOG_TOKENS => "Mail Log Tokens",
		self::IP_CREATED_DAY_INDEX => "ip created_day Index",
		self::DROP_MAIL_LOG_COLUMNS => "Drop migrated mail_logs columns",
		self::DROP_MAIL_LOG_INDEXES => "Drop dead mail_logs indexes",
		self::MAP_USER_CONSTRAINTS => "Map and alias user constraints",
		self::USER_NOTIFICATIONS_DEFAULT => "User notifications default",
		self::ADMIN_NOTIFIED => "Mail Log admin notification columns",
		self::MAIL_LOG_FUZZY => "Mail Log Fuzzy",
	];

	public const array MIGRATION_BATCH = [
		self::MAIL_RECIPIENTS => 10000,
		self::CREATED_DAY => 0,
		self::MAIL_LOG_DATA => 1000,
		self::ID_ACTION_INDEX => 0,
		self::MAIL_LOG_TOKENS => 0,
		self::IP_CREATED_DAY_INDEX => 0,
		self::DROP_MAIL_LOG_COLUMNS => 0,
		self::DROP_MAIL_LOG_INDEXES => 0,
		self::MAP_USER_CONSTRAINTS => 0,
		self::USER_NOTIFICATIONS_DEFAULT => 0,
		self::ADMIN_NOTIFIED => 0,
		self::MAIL_LOG_FUZZY => 0,
	];

	public const array MIGRATION_SLEEP = [
		self::MAIL_RECIPIENTS => 200000,
		self::CREATED_DAY => 0,
		self::MAIL_LOG_DATA => 200000,
		self::ID_ACTION_INDEX => 200000,
		self::MAIL_LOG_TOKENS => 200000,
		self::IP_CREATED_DAY_INDEX => 200000,
		self::DROP_MAIL_LOG_COLUMNS => 200000,
		self::DROP_MAIL_LOG_INDEXES => 200000,
		self::MAP_USER_CONSTRAINTS => 200000,
		self::USER_NOTIFICATIONS_DEFAULT => 200000,
		self::ADMIN_NOTIFIED => 200000,
		self::MAIL_LOG_FUZZY => 200000,
	];

	public const array MIGRATION_HELP = [
		self::MAIL_RECIPIENTS => "https://github.com/bilias/rqwatch/blob/master/docs/DB_MIGRATION.md",
		self::CREATED_DAY => "https://github.com/bilias/rqwatch/blob/master/docs/DB_MIGRATION.md",
		self::MAIL_LOG_DATA => "https://github.com/bilias/rqwatch/blob/master/docs/DB_MIGRATION.md",
		self::ID_ACTION_INDEX => "https://github.com/bilias/rqwatch/blob/master/docs/DB_MIGRATION.md",
		self::MAIL_LOG_TOKENS => "https://github.com/bilias/rqwatch/blob/master/docs/DB_MIGRATION.md",
		self::IP_CREATED_DAY_INDEX => "https://github.com/bilias/rqwatch/blob/master/docs/DB_MIGRATION.md",
		self::DROP_MAIL_LOG_COLUMNS => "https://github.com/bilias/rqwatch/blob/master/docs/DB_UPDATE_2_plus.md",
		self::DROP_MAIL_LOG_INDEXES => "https://github.com/bilias/rqwatch/blob/master/docs/DB_UPDATE_2_plus.md",
		self::MAP_USER_CONSTRAINTS => "https://github.com/bilias/rqwatch/blob/master/docs/DB_UPDATE_2_plus.md",
		self::USER_NOTIFICATIONS_DEFAULT => "https://github.com/bilias/rqwatch/blob/master/docs/DB_MIGRATION.md",
		self::ADMIN_NOTIFIED => "https://github.com/bilias/rqwatch/blob/master/docs/DB_MIGRATION.md",
		self::MAIL_LOG_FUZZY => "https://github.com/bilias/rqwatch/blob/master/docs/DB_MIGRATION.md",
	];

	public const string STATUS_PENDING   = 'pending';
	public const string STATUS_RUNNING   = 'running';
	public const string STATUS_COMPLETED = 'completed';
	public const string STATUS_FAILED    = 'failed';

	public const array STATUSES = [
		self::STATUS_PENDING,
		self::STATUS_RUNNING,
		self::STATUS_COMPLETED,
		self::STATUS_FAILED,
	];

	public static function create(string $migration): AbstractMigration {

		if (!isset(self::MIGRATION_CLASSES[$migration])) {
			throw new InvalidArgumentException("Unknown migration: {$migration}");
		}

		$class = self::MIGRATION_CLASSES[$migration];
		return new $class();
	}

}
