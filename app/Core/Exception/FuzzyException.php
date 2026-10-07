<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Core\Exception;

use RuntimeException;

// Thrown by FuzzyService. The message is safe to show to an admin.
class FuzzyException extends RuntimeException
{
	public function __construct(
		string $message,
		public readonly FuzzyError $error = FuzzyError::Internal
	) {
		parent::__construct($message);
	}
}
