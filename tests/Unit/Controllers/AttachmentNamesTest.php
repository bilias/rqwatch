<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 MailLogController::attachmentNames(): an attachment's name for filename*
 (path separators replaced) and an ASCII fallback, a hash of the name with
 its extension when that is 1-10 letters or digits. An extension ending in
 a line break, which a sender can encode in the name, is not one:
 makeDisposition() would refuse the fallback (Symfony 7.4.23).
*/

namespace Tests\Unit\Controllers;

use App\Controllers\MailLogController;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Tests\Unit\UnitTestCase;

final class AttachmentNamesTest extends UnitTestCase
{
	/** @return array<string, array{?string, string, string}> name, filename, the fallback's extension */
	public static function names(): array {
		return [
			'plain' => ['report.pdf', 'report.pdf', '.pdf'],
			'path separators' => ['a/b\\c.txt', 'a_b_c.txt', '.txt'],
			'no extension' => ['README', 'README', ''],
			'extension too long' => ['x.abcdefghijk', 'x.abcdefghijk', ''],
			'extension not alphanumeric' => ['x.p-f', 'x.p-f', ''],
			'extension ending in a line break' => ["x.pdf\n", "x.pdf\n", ''],
			'no name' => [null, '', ''],
		];
	}

	#[DataProvider('names')]
	public function testNames(?string $name, string $filename, string $ext): void {
		$this->assertSame([$filename, hash('xxh3', $filename) . $ext], self::attachmentNames($name));
	}

	public function testDispositionHasNoLineBreak(): void {
		[$filename, $fallback] = self::attachmentNames("x.pdf\n");
		$header = HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename, $fallback);
		$this->assertSame('attachment; filename=' . hash('xxh3', "x.pdf\n") . "; filename*=utf-8''x.pdf%0A", $header);
	}

	/** @return array{string, string} */
	private static function attachmentNames(?string $name): array {
		$controller = (new ReflectionClass(MailLogController::class))->newInstanceWithoutConstructor();
		/** @var array{string, string} */
		return (new ReflectionMethod($controller, 'attachmentNames'))->invoke($controller, $name);
	}
}
