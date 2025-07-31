<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
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
