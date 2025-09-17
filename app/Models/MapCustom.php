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

use Illuminate\Database\Eloquent\Builder;

class MapCustom extends Model
{
	protected $table = 'maps_custom';
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
