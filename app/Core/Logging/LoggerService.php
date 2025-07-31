<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Core\Logging;

use Psr\Log\LoggerInterface;

// used by Controllers
class LoggerService
{
	private LoggerInterface $fileLogger;
	private LoggerInterface $syslogLogger;

	public function __construct(?LoggerInterface $fileLogger = null, ?LoggerInterface $syslogLogger = null) {
		$this->fileLogger = $fileLogger ?? LoggerFactory::createFileLogger();
		$this->syslogLogger = $syslogLogger ?? LoggerFactory::createSyslogLogger();
	}				          

	public function getFileLogger(): LoggerInterface {
		return $this->fileLogger;
	}

	public function getSyslogLogger(): LoggerInterface {
		return $this->syslogLogger;
	}
}
