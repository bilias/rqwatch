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
		'created_at' => 'datetime',
		'updated_at' => 'datetime',
	];

	protected $fillable = [
		'map_name',
		'ip',
		'mail_from',
		'rcpt_to',
		'mime_from',
		'mime_to',
		'user_id',
	];

	public const SELECT_FIELDS = [
		'id',
		'user_id',
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
