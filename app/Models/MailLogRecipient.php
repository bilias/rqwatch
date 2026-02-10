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

use App\Utils\Helper;

use Throwable;

class MailLogRecipient extends Model
{
	/*
	protected $table = $_ENV['MAIL_RECIPIENTS_TABLE'];
	const CREATED_AT = 'created_at';
	const UPDATED_AT = 'updated_at';
	*/
	public $timestamps = false;

	// No auto-increment ID
	public $incrementing = false;

	// Primary key is composite → leave undefined
	protected $primaryKey = null;

	protected $casts = [
		'mail_log_id' => 'integer',
		'recipient_email' => 'string',
	];

	protected $fillable = [
		'mail_log_id',
		'recipient_email',
	];

	public function mailLog() {
		return $this->belongsTo(
			MailLog::class,
			'mail_log_id',
			'id'
		);
	}

}
