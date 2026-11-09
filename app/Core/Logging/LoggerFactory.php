<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Core\Logging;

use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogHandler;

use App\Configuration\Config;
use RuntimeException;
use Monolog\Level;

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

final class LoggerFactory
{
	public static function createFileLogger(): LoggerInterface {
		// Get log file path from config or use fallback
		$log_file = Config::getString('LOG_FILE') ?? __DIR__ . '/../../../logs/rqwatch.log';

		if (!file_exists(dirname($log_file))) {
			mkdir(dirname($log_file), 0750, true);
		}

		$logger = new Logger('file_logger');

		// Set custom formatter
		$dateFormat = 'd-M-Y H:i:s e';
		// %channel% removed
		$output = "[%datetime%] %level_name%: %message% %context%\n";

		$formatter = new SafeLineFormatter($output, $dateFormat, false, true);
		$log_file_level = self::level('LOG_FILE_LEVEL', Config::getString('LOG_FILE_LEVEL') ?? 'INFO');

		$handler = new StreamHandler($log_file, $log_file_level);
		$handler->setFormatter($formatter);

		$logger->pushHandler($handler);
		return $logger;
	}

	// a log level by name (DEBUG, INFO, ...), any case; an unknown name is a config error naming $setting
	public static function level(string $setting, string $name): Level {
		foreach (Level::cases() as $level) {
			if (strcasecmp($level->name, $name) === 0) {
				return $level;
			}
		}
		throw new RuntimeException("{$setting} '{$name}' is not a log level (DEBUG, INFO, NOTICE, WARNING, ERROR, CRITICAL, ALERT, EMERGENCY)");
	}

	// the syslog facility LOG_SYSLOG_FACILITY names; any other name is a config error, not LOG_MAIL
	public static function syslogFacility(string $name): int {
		$facilities = [
			'LOG_AUTH', 'LOG_AUTHPRIV', 'LOG_CRON', 'LOG_DAEMON', 'LOG_KERN', 'LOG_LPR', 'LOG_MAIL', 'LOG_NEWS',
			'LOG_SYSLOG', 'LOG_USER', 'LOG_UUCP', 'LOG_LOCAL0', 'LOG_LOCAL1', 'LOG_LOCAL2', 'LOG_LOCAL3',
			'LOG_LOCAL4', 'LOG_LOCAL5', 'LOG_LOCAL6', 'LOG_LOCAL7',
		];
		/** @var mixed $value */
		$value = in_array($name, $facilities, true) && defined($name) ? constant($name) : null;
		return is_int($value) ? $value : throw new RuntimeException("LOG_SYSLOG_FACILITY '{$name}' is not a syslog facility (LOG_MAIL, LOG_LOCAL0 ... LOG_LOCAL7, ...)");
	}

	public static function createSyslogLogger(): LoggerInterface {
		$facility = self::syslogFacility(Config::getString('LOG_SYSLOG_FACILITY') ?? 'LOG_MAIL');
		$log_syslog_prefix = Config::getString('LOG_SYSLOG_PREFIX') ?? 'rqwatch';
		$log_syslog_level = self::level('LOG_SYSLOG_LEVEL', Config::getString('LOG_SYSLOG_LEVEL') ?? 'DEBUG');

		$logger = new Logger('syslog_logger');
		$handler = new SyslogHandler($log_syslog_prefix, $facility, $log_syslog_level);

		$output = "%level_name%: %message% %context%";
		$formatter = new SafeLineFormatter($output, null, false, true);
		$handler->setFormatter($formatter);
		$logger->pushHandler($handler);

		return $logger;
	}
}
