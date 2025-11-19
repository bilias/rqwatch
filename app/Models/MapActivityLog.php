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

class MapActivityLog extends Model
{
	protected $table = 'map_activity_logs';

	const CREATED_AT = null;
	const UPDATED_AT = 'last_changed_at';

	public $timestamps = true;
	protected $primaryKey = 'map_name';

	protected $casts = [
		'map_name' => 'string',
		'last_changed_at' => 'datetime',
	];

	protected $fillable = [
		'map_name',
	];

	public const array SELECT_FIELDS = [
		'table_name',
		'last_changed_at',
	];

	public function getRawLastChangedAt(): ?string {
		return $this->getAttributes()['last_changed_at'] ?? null;
	}

}
