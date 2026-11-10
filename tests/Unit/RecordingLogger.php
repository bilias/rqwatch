<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace Tests\Unit;

use Psr\Log\AbstractLogger;
use Stringable;

// keeps every log line as "<level> <message>"
final class RecordingLogger extends AbstractLogger
{
	/** @var list<string> */
	public array $lines = [];

	/**
	 * Psalm 7 infers what Psr\Log\LoggerInterface::log() may do (no
	 * write-refs) and treats Stringable::__toString(), which turning the
	 * message into text calls, as possibly writing through references:
	 * any logger that formats a Stringable message conflicts, whatever its code.
	 * @psalm-suppress ImmutableDependency
	 */
	#[\Override]
	public function log($level, string|Stringable $message, array $context = []): void {
		// a PSR level is a string; anything else shows as its type
		$this->lines[] = (is_string($level) ? $level : get_debug_type($level)) . " {$message}";
	}
}
