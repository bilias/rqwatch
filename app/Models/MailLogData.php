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
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use App\Configuration\AppConfig;

use App\Utils\Helper;

use Throwable;

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

	protected $primaryKey = 'mail_log_id';

	protected $casts = [
		'mail_log_id' => 'integer',
		'headers' => 'string',
		'symbols' => 'array',
		'fuzzy_hashes' => 'array',
	];

	protected $fillable = [
		'mail_log_id',
		'headers',
		'symbols',
		'fuzzy_hashes',
	];

	public const DATA_COLUMNS = [
		'headers',
		'symbols',
		'fuzzy_hashes',
	];

	public function mailLog() {
		return $this->belongsTo(
			MailLog::class,
			'mail_log_id',
			'id'
		);
	}

}
