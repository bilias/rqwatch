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

// A query stopped by max_statement_time (DB_WEB_MAX_STATEMENT_TIME on the web)
class QueryTimeoutException extends RuntimeException
{
}
