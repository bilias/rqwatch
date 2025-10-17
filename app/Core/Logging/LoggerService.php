<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU Affero General Public License version 3
as published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU Affero General Public License for more details.

You should have received a copy of the GNU Affero General Public License
along with this program.  If not, see <https://www.gnu.org/licenses/>.
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
