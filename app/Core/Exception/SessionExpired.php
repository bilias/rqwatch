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

namespace App\Core\Exception;

use Exception;
use Throwable;

class SessionExpired extends Exception
{
	// Redefine the exception so message isn't optional
	public function __construct($message, $code = 0, ?Throwable $previous = null) {
		// make sure everything is assigned properly
		parent::__construct($message, $code, $previous);
	}
}
