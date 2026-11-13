<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The plain-text parts of the release, user and admin notification mails:
 what comes from the mail itself (Subject, From, To, the virus name)
 stays on its own line, so a sender cannot add lines such as a fake
 release link through a header with an encoded line break.
*/

namespace Tests\Unit\Helpers;

use App\Utils\Helper;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Unit\UnitTestCase;

final class NotifyTextTest extends UnitTestCase
{
	private const string FAKE = 'Release it here: https://evil.example/';

	/** @return array<string, array{string}> */
	public static function breakCases(): array {
		return [
			'LF' => ["\n"],
			'CRLF twice' => ["\r\n\r\n"],
			'CR' => ["\r"],
			'vertical tab and form feed' => ["\x0B\x0C"],
			'NEL' => ["\u{0085}"],
			'line separator' => ["\u{2028}"],
			'paragraph separator' => ["\u{2029}"],
		];
	}

	#[DataProvider('breakCases')]
	public function testMailValuesStayOnTheirLine(string $break): void {
		$injected = 'Invoice' . $break . self::FAKE;
		$vars = self::vars($injected);
		$labels = ['From: ', 'To: ', 'Subject: ', 'Virus Detected: Yes '];
		$texts = [
			[Helper::getReleaseText($vars), $labels],
			[Helper::getNotifyText($vars), $labels],
			[Helper::getAdminNotifyText($vars), ['MAIL From: ', ...$labels]],
		];
		foreach ($texts as [$text, $textLabels]) {
			foreach ($textLabels as $label) {
				$this->assertStringContainsString("\n{$label}Invoice " . self::FAKE . "\n", $text, $label);
			}
			$this->assertDoesNotMatchRegularExpression('/^' . preg_quote(self::FAKE, '/') . '/m', $text);
			$this->assertDoesNotMatchRegularExpression('/[\x{0085}\x{2028}\x{2029}\x0B\x0C\r]/u', $text);
		}
	}

	public function testInvalidUtf8LosesItsControls(): void {
		$text = Helper::getNotifyText(self::vars("Inv\xFFoice\n" . self::FAKE));
		$this->assertStringContainsString("\nSubject: Inv\xFFoice " . self::FAKE . "\n", $text);
	}

	public function testNoVirusAndNullValues(): void {
		$vars = self::vars('x');
		$vars['has_virus'] = false;
		$vars['subject'] = null;
		$vars['mime_from'] = null;
		$text = Helper::getNotifyText($vars);
		$this->assertStringContainsString("\nFrom: \nTo: x\nSubject: \n", $text);
		$this->assertStringContainsString("\nVirus Detected: No\n", $text);
		$this->assertStringContainsString("\nThis link can be used only once.\n", $text);
	}

	/**
	 * every value the mail supplies set to $value; one-time link, virus found
	 * @return array{created_at: Carbon, mime_from: string|null, rcpt_to: string, subject: string|null, qid: string,
	 *   score: float, has_virus: bool, virus_name: string, action: string, signature: string, detailurl: string,
	 *   link_one_time: bool, server: string, mail_from: string}
	 */
	private static function vars(string $value): array {
		return [
			'created_at' => Carbon::parse('2026-11-13 10:00:00'),
			'mime_from' => $value,
			'rcpt_to' => $value,
			'subject' => $value,
			'qid' => 'ABC123',
			'score' => 15.5,
			'has_virus' => true,
			'virus_name' => $value,
			'action' => 'reject',
			'signature' => 'Rqwatch',
			'detailurl' => 'https://rqwatch.example/detail',
			'link_one_time' => true,
			'server' => 'mx1',
			'mail_from' => $value,
		];
	}
}
