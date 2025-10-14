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

class MailLog extends Model
{
	/*
	protected $table = $_ENV['MAILLOGS_TABLE'];
	const CREATED_AT = 'created_at';
	const UPDATED_AT = 'updated_at';
	*/
	public $timestamps = true;
	protected $primaryKey = 'id';

	protected $casts = [
		'id' => 'integer',
		'qid' => 'string',
		'server' => 'string',
		'subject' => 'string',
		'score' => 'float',
		'actions' => 'string',
		'symbols' => 'array',
		'has_virus' => 'boolean',
		'fuzzy_hashes' => 'array',
		'ip' => 'string',
		'mail_from' => 'string',
		'mime_from' => 'string',
		'rcpt_to' => 'string',
		'mime_to' => 'string',
		'size' => 'integer',
		'mail_stored' => 'boolean',
		'mail_location' => 'string',
		'notified' => 'boolean',
		'notify_date' => 'datetime',
		'released' => 'boolean',
		'release_date' => 'datetime',
		'headers' => 'string',
		'message_id' => 'string',
		'created_at' => 'datetime',
		'updated_at' => 'datetime',
	];

	protected $fillable = [
		'qid',
		'server',
		'subject',
		'score',
		'action',
		'symbols',
		'has_virus',
		'fuzzy_hashes',
		'ip',
		'mail_from',
		'mime_from',
		'rcpt_to',
		'mime_to',
		'size',
		'headers',
		'mail_stored',
		'mail_location',
		'notified',
		'notify_date',
		'released',
		'release_date',
		'message_id',
	];

	public const SELECT_FIELDS = [
		'id',
		'qid',
		'created_at',
		'action',
		'has_virus',
		'mail_from',
		'rcpt_to',
		'mime_from',
		'mime_to',
		'subject',
		'size',
		'score',
		'symbols',
		'server',
		'mail_stored',
		'mail_location',
		'message_id',
	];

	public const REPORT_FIELDS = [
		'ip',
		'action',
		'has_virus',
		'mail_from',
		'rcpt_to',
		'mime_from',
		'mime_to',
		'server',
		'mail_from_domain',
		'rcpt_to_domain',
		'mime_from_domain',
		'mime_to_domain',
		'date',
	];

	// Static DB field length limits
	public const FIELD_LIMITS = [
		'qid'           => 30,
		'server'        => 10,
		'subject'       => 1024,
		'action'        => 20,
		'ip'            => 50,
		'mail_from'     => 255,
		'mime_from'     => 255,
		'rcpt_to'       => 1024,
		'mime_to'       => 1024,
		'mail_location' => 255,
		'message_id'    => 1024,
	];

	/* not needed now
	public function getTable() {
		return $_ENV['MAILLOGS_TABLE'] ?? 'quarantine';
	}
	*/
}
