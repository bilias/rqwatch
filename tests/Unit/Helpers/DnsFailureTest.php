<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Which failed DNS lookups are cached as transient (the short fail TTL):
 all but netdns2's NXDOMAIN, the one definite "no name". Another exception
 with NXDOMAIN's code is transient too.
*/

namespace Tests\Unit\Helpers;

use App\Utils\Helper;
use Exception;
use NetDNS2\ENUM\Error as DnsError;
use NetDNS2\Exception as DnsException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Tests\Unit\UnitTestCase;

final class DnsFailureTest extends UnitTestCase
{
	/** @return array<string, array{Exception, bool}> */
	public static function failures(): array {
		return [
			'NXDOMAIN: definite' => [new DnsException('no such name', DnsError::DNS_NXDOMAIN), false],
			'SERVFAIL' => [new DnsException('server failure', DnsError::DNS_SERVFAIL), true],
			'timeout' => [new DnsException('timed out', DnsError::INT_FAILED_SOCKET), true],
			'not netdns2, NXDOMAIN code' => [new RuntimeException('other', DnsError::DNS_NXDOMAIN->value), true],
		];
	}

	#[DataProvider('failures')]
	public function testTransient(Exception $e, bool $transient): void {
		$this->assertSame($transient, (new ReflectionMethod(Helper::class, 'dnsTransientFailure'))->invoke(null, $e));
	}
}
