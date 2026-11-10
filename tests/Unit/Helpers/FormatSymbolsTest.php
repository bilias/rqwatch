<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 How a mail's symbols are shown: Helper::format_symbols() (sorted by score,
 name:score[:options], the virus line for an antivirus symbol) and the
 virus lookups check_virus() and check_virus_from_all().
*/

namespace Tests\Unit\Helpers;

use App\Utils\Helper;
use Tests\Unit\UnitTestCase;

/** @phpstan-import-type RspamdSymbol from Helper */
final class FormatSymbolsTest extends UnitTestCase
{
	/** @return list<RspamdSymbol> stored symbols, as sanitizeSymbols() keeps them */
	private static function symbols(): array {
		return [
			['name' => 'R_SPF_ALLOW', 'score' => -0.2, 'options' => ['+ip4:192.0.2.0/24']],
			['name' => 'CLAM_VIRUS', 'score' => 12.5, 'group' => 'antivirus', 'options' => ['Eicar-Test-Signature']],
			['name' => 'BAYES_SPAM', 'score' => 5.1, 'options' => ['99.9%', '7']],
			['name' => 'MIME_GOOD', 'score' => 0],
		];
	}

	public function testSpamSortedByScoreDescending(): void {
		$this->assertSame([
			'symbols' => [
				'CLAM_VIRUS:12.5:[CLAM_VIRUS: Eicar-Test-Signature]',
				'BAYES_SPAM:5.1:99.9%, 7',
				'MIME_GOOD:0',
				'R_SPF_ALLOW:-0.2:+ip4:192.0.2.0/24',
			],
			'virus_found' => '[CLAM_VIRUS: Eicar-Test-Signature]',
		], Helper::format_symbols(self::symbols(), 18.0, true));
	}

	// a mail without has_virus shows the antivirus symbol's options like any other
	public function testHamAscendingWithoutVirus(): void {
		$this->assertSame([
			'symbols' => [
				'R_SPF_ALLOW:-0.2:+ip4:192.0.2.0/24',
				'MIME_GOOD:0',
				'BAYES_SPAM:5.1:99.9%, 7',
				'CLAM_VIRUS:12.5:Eicar-Test-Signature',
			],
			'virus_found' => null,
		], Helper::format_symbols(self::symbols(), -1.0, false));
	}

	// a NULL score (rspamd sent none) sorts ascending
	public function testNullScore(): void {
		$this->assertSame('R_SPF_ALLOW:-0.2:+ip4:192.0.2.0/24', Helper::format_symbols(self::symbols(), null, false)['symbols'][0]);
	}

	public function testCheckVirus(): void {
		$this->assertSame('[CLAM_VIRUS: Eicar-Test-Signature]', Helper::check_virus(self::symbols()[1]));
		$this->assertSame('[CLAM_VIRUS: ]', Helper::check_virus(['name' => 'CLAM_VIRUS', 'group' => 'antivirus']));
		$this->assertFalse(Helper::check_virus(self::symbols()[0]));
	}

	public function testCheckVirusFromAll(): void {
		$this->assertSame('[CLAM_VIRUS: Eicar-Test-Signature]', Helper::check_virus_from_all(self::symbols()));
		$this->assertFalse(Helper::check_virus_from_all([self::symbols()[0]]));
		// MailLog's accessor gives null without the mail_log_data row
		$this->assertFalse(Helper::check_virus_from_all(null));
		$this->assertFalse(Helper::check_virus_from_all([]));
	}
}
