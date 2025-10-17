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

use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogHandler;
use Monolog\Formatter\LineFormatter;

use App\Core\Config;

/*
Method               Level     Severity (Monolog constant)
$logger->emergency() Emergency Logger::EMERGENCY (600)
$logger->alert()     Alert     Logger::ALERT (550)
$logger->critical()  Critical  Logger::CRITICAL (500)
$logger->error()     Error     Logger::ERROR (400)
$logger->warning()   Warning   Logger::WARNING (300)
$logger->notice()    Notice    Logger::NOTICE (250)
$logger->info()      Info      Logger::INFO (200)
$logger->debug()     Debug     Logger::DEBUG (100)
*/

class LoggerFactory
{
	public static function createFileLogger(): LoggerInterface {
		// Get log file path from config or use fallback
		$log_file = Config::get('LOG_FILE') ?? __DIR__ . '/../../../logs/rqwatch.log';

		if (!file_exists(dirname($log_file))) {
			mkdir(dirname($log_file), 0750, true);
		}

		$logger = new Logger('file_logger');

		// Set custom formatter
		$dateFormat = 'd-M-Y H:i:s e';
		// %channel% removed
		$output = "[%datetime%] %level_name%: %message% %context%\n";

		$formatter = new LineFormatter($output, $dateFormat, true, true);
		$log_file_level = Config::get('LOG_FILE_LEVEL') ?? "INFO";

		$handler = new StreamHandler($log_file, $log_file_level);
		$handler->setFormatter($formatter);

		$logger->pushHandler($handler);
		return $logger;
	}

	public static function createSyslogLogger(): LoggerInterface {
		$log_syslog_facility = Config::get('LOG_SYSLOG_FACILITY') ?? 'LOG_MAIL';
		$log_syslog_prefix = Config::get('LOG_SYSLOG_PREFIX') ?? 'rqwatch';
		$log_syslog_level = Config::get('LOG_SYSLOG_LEVEL') ?? 'DEBUG';

		if (defined($log_syslog_facility)) {
			$facility = constant($log_syslog_facility);
		} else {
			$facility = LOG_MAIL;
		}

		$logger = new Logger('syslog_logger');
		$handler = new SyslogHandler($log_syslog_prefix, $facility, $log_syslog_level);

		$output = "%level_name%: %message% %context%";
		$formatter = new LineFormatter($output, null, true, true);
		$handler->setFormatter($formatter);
		$logger->pushHandler($handler);

		return $logger;
	}
}
