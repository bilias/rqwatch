<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use App\Configuration\AppConfig;

class MailLogData extends Model
{
	protected $table = AppConfig::MAIL_LOG_DATA_TABLE;
	/*
	const CREATED_AT = 'created_at';
	const UPDATED_AT = 'updated_at';
	*/
	public $timestamps = false;

	// No auto-increment ID
	public $incrementing = false;

	public const string KEY_COLUMN = 'mail_log_id';

	protected $primaryKey = self::KEY_COLUMN;

	public const DATA_COLUMNS = [
		'headers',
		'symbols',
		'fuzzy_hashes',
	];

	// the key plus the data columns, for schema verification
	public const array COLUMNS = [
		self::KEY_COLUMN,
		...self::DATA_COLUMNS,
	];

	protected $casts = [
		self::KEY_COLUMN => 'integer',
		'headers' => 'string',
		'symbols' => 'array',
		'fuzzy_hashes' => 'array',
	];

	protected $fillable = self::COLUMNS;

	public function mailLog() {
		return $this->belongsTo(
			MailLog::class,
			self::KEY_COLUMN,
			'id'
		);
	}

}
