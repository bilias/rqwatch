<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Core\Auth;

use RuntimeException;

/*
 A login that could not be checked: the authentication backend (the LDAP
 server) failed or is set up wrong, not the user's credentials. The cause
 is logged where it happened. The user is asked to try again later, and
 the attempt is not counted against the login throttle.
*/
final class AuthUnavailableException extends RuntimeException
{
}
