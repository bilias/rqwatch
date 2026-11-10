<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The charsets a mail's MIME parts declare, for its unencoded 8-bit
 headers: in part order, each once, only names Helper::mailCharset()
 accepts. A part charset that is not text counts as none.
*/

namespace Tests\Unit\Api;

use App\Api\MetadataImporterMultipartApi;
use PhpMimeMailParser\Parser;
use ReflectionMethod;
use Tests\Unit\UnitTestCase;

final class DeclaredCharsetsTest extends UnitTestCase
{
	public function testDeclaredInPartOrderOnce(): void {
		$parts = '';
		foreach (['KOI8-R', 'Windows-1253', 'koi8-r', 'utf-8', 'utf-7', null] as $i => $charset) {
			$parts .= "--b\r\nContent-Type: text/plain" . ($charset === null ? '' : "; charset={$charset}") . "\r\n\r\npart {$i}\r\n";
		}
		$parser = new Parser();
		$parser->setText("From: a@example.org\r\nContent-Type: multipart/mixed; boundary=b\r\n\r\n{$parts}--b--\r\n");
		$this->assertSame(['koi8-r', 'windows-1253'], self::declared($parser));
	}

	public function testNonTextCharsetIgnored(): void {
		$parser = new class extends Parser {
			/** @return list<array<string, mixed>> */
			#[\Override]
			public function getParts(): array {
				return [['charset' => ['koi8-r']], ['charset' => 1253], ['charset' => 'KOI8-R']];
			}
		};
		$this->assertSame(['koi8-r'], self::declared($parser));
	}

	/** @return mixed the method's list<string> */
	private static function declared(Parser $parser): mixed {
		return (new ReflectionMethod(MetadataImporterMultipartApi::class, 'declaredCharsets'))->invoke(null, $parser);
	}
}
