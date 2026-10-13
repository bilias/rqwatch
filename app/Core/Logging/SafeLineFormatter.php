<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Core\Logging;

use Monolog\Formatter\LineFormatter;

// LineFormatter that always turns CR/LF in logged values into spaces (no log forging),
// whatever allowInlineLineBreaks() or includeStacktraces() are set to
final class SafeLineFormatter extends LineFormatter
{
	#[\Override]
	protected function replaceNewlines(string $str): string {
		return str_replace(["\r\n", "\r", "\n"], ' ', $str);
	}
}
