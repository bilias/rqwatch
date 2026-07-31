<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Inventory;

use App\Core\Database\AbstractMigration;
use App\Core\Database\Migrations\MailRecipientsMigration;
use App\Core\Database\Migrations\CreatedDayMigration;
use App\Core\Database\Migrations\MailLogDataMigration;

use Illuminate\Database\Capsule\Manager as Capsule;

use Psr\Log\LoggerInterface;

use InvalidArgumentException;

class Migrations
{
	public const string MAIL_RECIPIENTS = '20260729_migrate_mail_recipients';
	public const string ADD_CREATED_DAY = '20260729_add_created_day';
	public const string MAIL_LOG_DATA = '20260731_mail_log_data';

	public const array MIGRATIONS = [
		self::MAIL_RECIPIENTS,
		self::ADD_CREATED_DAY,
		self::MAIL_LOG_DATA,
	];

	public const array MIGRATION_CLASSES = [
		self::MAIL_RECIPIENTS => MailRecipientsMigration::class,
		self::ADD_CREATED_DAY => CreatedDayMigration::class,
		self::MAIL_LOG_DATA => MailLogDataMigration::class,
	];

	public const array MIGRATION_DESCR = [
		self::MAIL_RECIPIENTS => "Mail Log Recipients",
		self::ADD_CREATED_DAY => "Mail Log Created Day",
		self::MAIL_LOG_DATA => "Mail Log Data",
	];

	public const array MIGRATION_BATCH = [
		self::MAIL_RECIPIENTS => 10000,
		self::ADD_CREATED_DAY => 0,
		self::MAIL_LOG_DATA => 1000,
	];

	public const array MIGRATION_SLEEP = [
		self::MAIL_RECIPIENTS => 200000,
		self::ADD_CREATED_DAY => 0,
		self::MAIL_LOG_DATA => 200000,
	];

	public const array MIGRATION_HELP = [
		self::MAIL_RECIPIENTS => "https://github.com/bilias/rqwatch/blob/master/docs/MAIL_RECIPIENTS_UPDATE.md",
		self::ADD_CREATED_DAY => "https://github.com/bilias/rqwatch/blob/master/docs/CREATED_DAY_UPDATE.md",
		self::MAIL_LOG_DATA => "https://github.com/bilias/rqwatch/blob/master/docs/MAIL_LOG_DATA_UPDATE.md",
	];

	public static function create(
		string $migration,
		Capsule $capsule,
		LoggerInterface $logger
	): AbstractMigration {

		if (!isset(self::MIGRATION_CLASSES[$migration])) {
			throw new InvalidArgumentException("Unknown migration: {$migration}");
		}

		$class = self::MIGRATION_CLASSES[$migration];
		return new $class($capsule, $logger);
	}

}
