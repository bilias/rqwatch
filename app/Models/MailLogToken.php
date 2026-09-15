<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use App\Configuration\AppConfig;

class MailLogToken extends Model
{
	protected $table = AppConfig::MAIL_LOG_TOKENS_TABLE;

	public $timestamps = false;

	// token_hash is the primary key, not an auto-increment integer
	public $incrementing = false;

	public const string KEY_COLUMN = 'token_hash';

	protected $primaryKey = self::KEY_COLUMN;

	protected $keyType = 'string';

	public const array COLUMNS = [
		self::KEY_COLUMN,
		'mail_log_id',
		'recipient_email',
	];

	protected $casts = [
		self::KEY_COLUMN => 'string',
		'mail_log_id' => 'integer',
		'recipient_email' => 'string',
	];

	protected $fillable = self::COLUMNS;

	public function mailLog(): BelongsTo {
		return $this->belongsTo(
			MailLog::class,
			'mail_log_id',
			'id'
		);
	}

}
