<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU Affero General Public License version 3
as published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU Affero General Public License for more details.

You should have received a copy of the GNU Affero General Public License
along with this program.  If not, see <https://www.gnu.org/licenses/>.
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
		'score' => 'float',
		'symbols' => 'array',
		'has_virus' => 'boolean',
		'fuzzy_hashes' => 'array',
		'mail_stored' => 'boolean',
		'notified' => 'boolean',
		'released' => 'boolean',
		'created_at' => 'datetime',
		'updated_at' => 'datetime',
		'notify_date' => 'datetime',
		'release_date' => 'datetime',
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
		'release_date'
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
	];

	/* not needed now
	public function getTable() {
		return $_ENV['MAILLOGS_TABLE'] ?? 'quarantine';
	}
	*/
}
