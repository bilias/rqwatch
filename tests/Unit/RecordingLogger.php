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

	public function log($level, string|Stringable $message, array $context = []): void {
		$this->lines[] = "{$level} {$message}";
	}
}
