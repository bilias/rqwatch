<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace Tests\Unit\Api;

use Exception;
use Symfony\Component\HttpFoundation\Response;

// an API's answer, thrown by the test seam in place of sending it and exiting
final class ApiAnswer extends Exception
{
	public function __construct(public readonly Response $response) {
		parent::__construct('API answered ' . $response->getStatusCode());
	}
}
