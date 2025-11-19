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
use Illuminate\Database\Eloquent\Relations\HasMany;

use Illuminate\Database\Eloquent\Builder;

class CustomMapConfig extends Model
{
	protected $table = 'custom_map_config';
	/*
	const CREATED_AT = 'created_at';
	const UPDATED_AT = 'updated_at';
	*/
	public $timestamps = true;
	protected $primaryKey = 'id';

	protected $casts = [
		'id' => 'integer',
		'map_name' => 'string',
		'map_description' => 'string',
		'field_name' => 'string',
		'field_label' => 'string',
		'created_at' => 'datetime',
		'updated_at' => 'datetime',
	];

	protected $fillable = [
		'map_name',
		'map_description',
		'field_name',
		'field_label',
	];

	public const array SELECT_FIELDS = [
		'id',
		'map_name',
		'map_description',
		'field_name',
		'field_label',
		'created_at',
	];

	public function MapsCustom(): HasMany {
		// map_name: foreign_key, local_key
		return $this->hasMany(MapCustom::class, 'map_name', 'map_name')->orderBy('map_name', 'ASC');
	}

}
