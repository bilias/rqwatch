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

class MailLogFuzzy extends Model
{
	protected $table = AppConfig::MAIL_LOG_FUZZY_TABLE;

	// the database maintains created_at and updated_at
	public $timestamps = false;

	public const array COLUMNS = [
		'id',
		'mail_log_id',
		'qid',
		'api_server',
		'flag',
		'weight',
		'hashes',
		'learned_by',
		'hits',
		'last_hit_at',
		'created_at',
		'updated_at',
	];

	protected $casts = [
		'id' => 'integer',
		'mail_log_id' => 'integer',
		'flag' => 'integer',
		'hits' => 'integer',
		'last_hit_at' => 'datetime',
		'weight' => 'integer',
		'hashes' => 'array',
		'created_at' => 'datetime',
		'updated_at' => 'datetime',
	];

	protected $fillable = [
		'mail_log_id',
		'qid',
		'api_server',
		'flag',
		'weight',
		'hashes',
		'learned_by',
	];

	public function mailLog(): BelongsTo {
		return $this->belongsTo(
			MailLog::class,
			'mail_log_id',
			'id'
		);
	}

}
