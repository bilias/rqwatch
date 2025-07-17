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

use Illuminate\Database\Eloquent\Builder;

class MapGeneric extends Model
{
	protected $table = 'maps_generic';
	/*
	const CREATED_AT = 'created_at';
	const UPDATED_AT = 'updated_at';
	*/
	public $timestamps = true;
	protected $primaryKey = 'id';

	protected $casts = [
		'id' => 'integer',
		'map_name' => 'string',
		'pattern' => 'string',
		'score' => 'integer',
		'created_at' => 'datetime',
		'updated_at' => 'datetime',
	];

	protected $fillable = [
		'map_name',
		'pattern',
		'score',
	];

	public const SELECT_FIELDS = [
		'id',
		'map_name',
		'pattern',
		'score',
		'created_at',
	];

	// scopes
	public function scopeForMap($query, $map): Builder {
		return $query->where('map_name', $map);
	}

}
