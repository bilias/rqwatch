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
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Model
{
	/*
	protected $table = 'users';
	const CREATED_AT = 'created_at';
	const UPDATED_AT = 'updated_at';
	*/
	public $timestamps = true;
	protected $primaryKey = 'id';

	protected $casts = [
		'id' => 'integer',
		'username' => 'string',
		'email' => 'string',
		'firstname' => 'string',
		'lastname' => 'string',
		'is_admin' => 'boolean',
		'last_login' => 'datetime',
		'auth_provider' => 'int',
		'password' => 'string',
		'created_at' => 'datetime',
		'updated_at' => 'datetime',
	];

	protected $fillable = [
		'username',
		'email',
		'firstname',
		'lastname',
		'is_admin',
		'last_login',
		'auth_provider',
	];

	protected $hidden = [
		'password', // Hide on JSON/array output
	];

	public const SELECT_FIELDS = [
		'id',
		'username',
		'email',
		'firstname',
		'lastname',
		'is_admin',
		'last_login',
		'auth_provider',
	];

	public function mailAliases(): HasMany {
		return $this->hasMany(MailAlias::class)->orderBy('alias', 'ASC');
	}

	/*
	public function mapCombinedEntries(): HasMany {
		return $this->hasMany(MapCombined::class);
	}
	*/

	/* not needed now with toArray()
	public function getTable() {
		return $_ENV['USERS_TABLE'] ?? 'users';
	}
	
	public function getUserName() {
		return $this->attributes['username'];
	}
	
	public function setUsername(string $value): void {
		$this->attributes['username'] = $value;
	}
	
	public function getEmail() {
		return $this->attributes['email'];
	}
	
	public function setEmail(string $value): void {
		$this->attributes['email'] = $value;
	}
	
	// required for form data read with empty firstname
	public function getFirstName() {
		return $this->attributes['firstname'];
	}

	public function setFirstname(string $value): void {
		$this->attributes['firstname'] = $value;
	}
	
	// required for form data read with empty lastname
	public function getLastName() {
		return $this->attributes['lastname'];
	}

	public function setLastname(string $value): void {
		$this->attributes['lastname'] = $value;
	}
	*/
}
