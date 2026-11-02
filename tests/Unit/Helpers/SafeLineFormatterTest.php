<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 SafeLineFormatter: logged values never carry CR/LF, other C0 controls or
 DEL into the log line, whatever the LineFormatter options say.
*/

namespace Tests\Unit\Helpers;

use App\Core\Logging\SafeLineFormatter;
use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SafeLineFormatterTest extends TestCase
{
	/** @param array<string, mixed> $context */
	private function format(string $message, array $context = [], bool $inlineBreaks = false): string {
		// the file logger's format (LoggerFactory)
		$formatter = new SafeLineFormatter("%level_name%: %message% %context%\n", null, $inlineBreaks, true);
		return $formatter->format(new LogRecord(new DateTimeImmutable(), 'test', Level::Warning, $message, $context));
	}

	/** @return array<string, array{string, string}> */
	public static function messages(): array {
		return [
			'CRLF is one space'        => ["a\r\nb", 'a b'],
			'lone LF and CR'           => ["a\nb\rc", 'a b c'],
			'mixed run, as before'     => ["a\r\r\n\n\rb", 'a    b'],
			'forged line'              => ["x\nERROR: forged", 'x ERROR: forged'],
			'ESC sequence'             => ["x\x1b[2Jy", 'x [2Jy'],
			'NUL, BEL, VT, FF'         => ["a\x00b\x07c\x0bd\x0ce", 'a b c d e'],
			'DEL'                      => ["a\x7fb", 'a b'],
			'tab kept'                 => ["a\tb", "a\tb"],
			'UTF-8 unchanged'          => ['Ελληνικά ✓', 'Ελληνικά ✓'],
			'invalid UTF-8 unchanged'  => ["a\xff\xfeb", "a\xff\xfeb"],
		];
	}

	#[DataProvider('messages')]
	public function testMessage(string $message, string $expected): void {
		$this->assertSame("WARNING: {$expected} \n", $this->format($message));
	}

	#[DataProvider('messages')]
	public function testMessageWithInlineLineBreaksAllowed(string $message, string $expected): void {
		$this->assertSame("WARNING: {$expected} \n", $this->format($message, [], true));
	}

	public function testContextAndStacktraceStayOnOneLine(): void {
		$e = new RuntimeException("boom\nERROR: forged\x1b[0m");
		$out = $this->format("uri '/x\x1b]0;t\x07'", ['e' => $e, 'v' => "a\nb"], true);
		$this->assertStringEndsWith("\n", $out);
		$this->assertSame(0, preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', substr($out, 0, -1)));
	}
}
