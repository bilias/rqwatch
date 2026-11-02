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

// LineFormatter that always turns CR/LF and the other C0 controls and DEL (tab kept) in
// logged values into spaces (no log forging, no terminal escapes), whatever
// allowInlineLineBreaks() or includeStacktraces() are set to. CRLF is one space, as before.
// Byte-wise (no /u): invalid UTF-8 passes unchanged instead of failing the match.
final class SafeLineFormatter extends LineFormatter
{
	#[\Override]
	protected function replaceNewlines(string $str): string {
		return preg_replace('/\r\n|[\x00-\x08\x0A-\x1F\x7F]/', ' ', $str) ?? '';
	}
}
