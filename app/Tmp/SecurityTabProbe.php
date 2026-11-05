<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Deliberately vulnerable and never called: checks that Psalm taint and
 Opengrep findings reach the Security tab, and nothing public. Delete it
 after the check.
*/

namespace App\Tmp;

final class SecurityTabProbe
{
	public static function run(): void {
		echo $_GET['probe'] ?? '';
	}
}
