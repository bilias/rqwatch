<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The log settings LoggerFactory reads: a level by name in any case, and a
 syslog facility from the LOG_* facility constants. Anything else is an
 error naming the setting; an unknown facility no longer becomes LOG_MAIL.
 The file logger writes one line per entry from LOG_FILE_LEVEL (INFO by
 default) to LOG_FILE, creating its directory; the syslog logger takes its
 facility, prefix and level from config (LOG_MAIL, rqwatch, DEBUG by
 default). LoggerService builds the two unless it is given them.
*/

namespace Tests\Unit\Core;

use App\Configuration\Config;
use App\Core\Logging\LoggerFactory;
use App\Core\Logging\LoggerService;
use App\Core\Logging\SafeLineFormatter;
use DateTimeImmutable;
use Monolog\Handler\SyslogHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use ReflectionProperty;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Unit\RecordingLogger;
use Tests\Unit\UnitTestCase;

final class LoggerFactoryTest extends UnitTestCase
{
	public function testLevel(): void {
		$this->assertSame(Level::Info, LoggerFactory::level('LOG_FILE_LEVEL', 'INFO'));
		$this->assertSame(Level::Debug, LoggerFactory::level('LOG_FILE_LEVEL', 'debug'));
		$this->assertSame(Level::Emergency, LoggerFactory::level('LOG_FILE_LEVEL', 'Emergency'));
	}

	public function testUnknownLevelThrows(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("LOG_SYSLOG_LEVEL 'VERBOSE' is not a log level");
		LoggerFactory::level('LOG_SYSLOG_LEVEL', 'VERBOSE');
	}

	public function testFacility(): void {
		$names = [
			'LOG_AUTH', 'LOG_AUTHPRIV', 'LOG_CRON', 'LOG_DAEMON', 'LOG_KERN', 'LOG_LPR', 'LOG_MAIL', 'LOG_NEWS',
			'LOG_SYSLOG', 'LOG_USER', 'LOG_UUCP', 'LOG_LOCAL0', 'LOG_LOCAL1', 'LOG_LOCAL2', 'LOG_LOCAL3',
			'LOG_LOCAL4', 'LOG_LOCAL5', 'LOG_LOCAL6', 'LOG_LOCAL7',
		];
		foreach ($names as $name) {
			$this->assertSame(constant($name), LoggerFactory::syslogFacility($name), $name);
		}
	}

	/** @return array<string, array{string}> */
	public static function notFacilities(): array {
		return [
			'typo' => ['LOG_LOCAL9'],
			'a priority, not a facility' => ['LOG_ERR'],
			'another constant' => ['E_ALL'],
			'lower case' => ['log_mail'],
		];
	}

	#[DataProvider('notFacilities')]
	public function testNotAFacilityThrows(string $name): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("LOG_SYSLOG_FACILITY '{$name}' is not a syslog facility");
		LoggerFactory::syslogFacility($name);
	}

	private string $dir = '';

	#[\Override]
	protected function tearDown(): void {
		if ($this->dir !== '') {
			@unlink("{$this->dir}/logs/rqwatch.log");
			@rmdir("{$this->dir}/logs");
			@rmdir($this->dir);
		}
		parent::tearDown();
	}

	// a log file in a directory that does not exist yet
	private function logFile(): string {
		$this->dir = sys_get_temp_dir() . '/rqwatch-log-' . bin2hex(random_bytes(6));
		mkdir($this->dir);
		$file = "{$this->dir}/logs/rqwatch.log";
		Config::set('LOG_FILE', $file);
		return $file;
	}

	public function testFileLogger(): void {
		$file = $this->logFile();
		$logger = LoggerFactory::createFileLogger();
		$this->assertSame(0750, (int) fileperms("{$this->dir}/logs") & 0777);
		$logger->debug('not written');
		$logger->info('written', ['qid' => 'Q1']);
		$logger->warning("one\nline");
		$this->assertMatchesRegularExpression(
			'/^\[\d\d-[A-Z][a-z]{2}-\d{4} \d\d:\d\d:\d\d \S+\] INFO: written \{"qid":"Q1"\}\n'
			. '\[[^\]]+\] WARNING: one line \n$/D',
			(string) file_get_contents($file)
		);
	}

	public function testFileLoggerLevel(): void {
		$file = $this->logFile();
		Config::set('LOG_FILE_LEVEL', 'error');
		$logger = LoggerFactory::createFileLogger();
		$logger->warning('not written');
		$logger->error('written');
		$this->assertStringEndsWith("] ERROR: written \n", (string) file_get_contents($file));
		$this->assertStringNotContainsString('not written', (string) file_get_contents($file));
	}

	/** @return array{string, int, Level, string} ident, facility, level, a warning as formatted */
	private static function syslog(Logger $logger): array {
		$handlers = $logger->getHandlers();
		$handler = $handlers[0] ?? null;
		if (count($handlers) !== 1 || !$handler instanceof SyslogHandler) {
			self::fail('not one syslog handler');
		}
		$formatter = $handler->getFormatter();
		if (!$formatter instanceof SafeLineFormatter) {
			self::fail('not a SafeLineFormatter');
		}
		/** @var string $ident */
		$ident = (new ReflectionProperty(SyslogHandler::class, 'ident'))->getValue($handler);
		/** @var int $facility */
		$facility = (new ReflectionProperty(SyslogHandler::class, 'facility'))->getValue($handler);
		$line = $formatter->format(new LogRecord(new DateTimeImmutable(), 'syslog_logger', Level::Warning, "one\nline"));
		return [$ident, $facility, $handler->getLevel(), $line];
	}

	// no date (syslog adds its own), no empty context
	public function testSyslogLoggerDefaults(): void {
		$logger = LoggerFactory::createSyslogLogger();
		$this->assertInstanceOf(Logger::class, $logger);
		$this->assertSame(['rqwatch', LOG_MAIL, Level::Debug, 'WARNING: one line '], self::syslog($logger));
	}

	public function testSyslogLoggerConfigured(): void {
		Config::set('LOG_SYSLOG_FACILITY', 'LOG_LOCAL3');
		Config::set('LOG_SYSLOG_PREFIX', 'rqtest');
		Config::set('LOG_SYSLOG_LEVEL', 'warning');
		$logger = LoggerFactory::createSyslogLogger();
		$this->assertInstanceOf(Logger::class, $logger);
		$this->assertSame(['rqtest', LOG_LOCAL3, Level::Warning, 'WARNING: one line '], self::syslog($logger));
	}

	public function testServiceGiven(): void {
		$service = new LoggerService($this->log, $syslog = new RecordingLogger());
		$this->assertSame($this->log, $service->getFileLogger());
		$this->assertSame($syslog, $service->getSyslogLogger());
	}

	public function testServiceBuilds(): void {
		$file = $this->logFile();
		$service = new LoggerService();
		$service->getFileLogger()->info('built');
		$this->assertStringEndsWith('] INFO: built ' . "\n", (string) file_get_contents($file));
		$syslog = $service->getSyslogLogger();
		$this->assertInstanceOf(Logger::class, $syslog);
		$this->assertSame('rqwatch', self::syslog($syslog)[0]);
	}
}
