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

class MailAlias extends Model
{
	/*
	protected $table = 'mail_aliases';
	const CREATED_AT = 'created_at';
	const UPDATED_AT = 'updated_at';
	*/
	public $timestamps = true;
	protected $primaryKey = 'id';

	protected $casts = [
		'id' => 'integer',
		'user_id' => 'integer',
		'alias' => 'string',
		'created_at' => 'datetime',
		'updated_at' => 'datetime',
	];

	protected $fillable = [
		'user_id',
		'alias',
	];

	public const SELECT_FIELDS = [
		'id',
		'user_id',
		'alias',
	];

	public function user(): BelongsTo {
		return $this->belongsTo(User::class);
	}

}
