<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Core\Auth;

use Jumbojett\OpenIDConnectClient;
use Jumbojett\OpenIDConnectClientException;

final class RqwatchOpenIDConnectClient extends OpenIDConnectClient
{
	public function getEndSessionEndpoint(): string {
		$value = $this->getProviderConfigValue('end_session_endpoint');
		if (!is_string($value)) {
			throw new OpenIDConnectClientException('end_session_endpoint is not a string');
		}
		return $value;
	}
}
