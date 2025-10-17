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

	public const SELECT_FIELDS = [
		'table_name',
		'last_changed_at',
	];

	public function getRawLastChangedAt(): ?string {
		return $this->getAttributes()['last_changed_at'] ?? null;
	}

}
