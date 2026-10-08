<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Core\Exception;

enum FuzzyError
{
	// the state of the mail or fuzzy record forbids the action
	case Conflict;
	// fuzzy learning is disabled or not configured on this server
	case Unavailable;
	// rspamd or a remote API failed
	case Upstream;
	// our own fault: configuration, filesystem, database
	case Internal;
}
