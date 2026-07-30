<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Configuration;

use App\Core\Database\Migrations\MailRecipientsMigration;

class MigrationList
{
	public const string MAIL_RECIPIENTS = '20260729_migrate_mail_recipients';
	public const string ADD_CREATED_DAY = '20260729_add_created_day';

	public const array MIGRATIONS = [
		self::MAIL_RECIPIENTS,
		self::ADD_CREATED_DAY,
	];

	public const array MIGRATION_CLASSES = [
		self::MAIL_RECIPIENTS => MailRecipientsMigration::class,
		self::ADD_CREATED_DAY => CreatedDayMigration::class,
	];

	public const array MIGRATION_HELP = [
		self::MAIL_RECIPIENTS => "https://github.com/bilias/rqwatch/blob/master/docs/MAIL_RECIPIENTS_UPDATE.md",
		self::ADD_CREATED_DAY => "https://github.com/bilias/rqwatch/blob/master/docs/CREATED_DAY_UPDATE.md",
	];
}
