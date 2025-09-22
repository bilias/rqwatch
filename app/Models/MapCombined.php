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

use Illuminate\Database\Eloquent\Builder;

class MapCombined extends Model
{
	protected $table = 'maps_combined';
	/*
	const CREATED_AT = 'created_at';
	const UPDATED_AT = 'updated_at';
	*/
	public $timestamps = true;
	protected $primaryKey = 'id';

	protected $casts = [
		'id' => 'integer',
		'map_name' => 'string',
		'ip' => 'string',
		'mail_from' => 'string',
		'rcpt_to' => 'string',
		'mime_from' => 'string',
		'mime_to' => 'string',
		'disabled' => 'boolean',
		'created_at' => 'datetime',
		'updated_at' => 'datetime',
	];

	protected $fillable = [
		'map_name',
		'user_id',
		'ip',
		'mail_from',
		'rcpt_to',
		'mime_from',
		'mime_to',
		'disabled',
	];

	public const SELECT_FIELDS = [
		'id',
		'user_id',
		'disabled',
		'created_at',
	];

	public function user(): BelongsTo {
		return $this->belongsTo(User::class);
	}

	// scopes
	public function scopeForMap($query, $map): Builder {
		return $query->where('map_name', $map);
	}

	public function scopeMailFromRcptTo($query): Builder {
		return $query->whereNotNull('mail_from')->whereNotNull('rcpt_to');
	}

	public function scopeMailFrom($query): Builder {
		return $query->whereNotNull('mail_from');
	}

}
