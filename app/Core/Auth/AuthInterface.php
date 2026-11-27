<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Core\Auth;

interface AuthInterface {
	// true: logged in; false: checked and refused (wrong password, unknown user);
	// throws AuthUnavailableException only when the login could not be checked (the server failed)
	public function authenticate(): bool;
	public function getAuthenticatedUser(): ?string;
}
