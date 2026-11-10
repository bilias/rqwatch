<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The mail APIs' single-value fields: requireSingleValues() returns the
 listed fields the request holds, as sent, in the order listed. Absent
 fields are left out and unlisted ones not returned. The refusal of a
 field that is not a single value answers 400 and exits: covered by the
 integration tests (FuzzyApiTest, ApiReleaseTest).
*/

namespace Tests\Unit\Api;

use App\Api\FuzzyMailApi;
use ReflectionClass;
use ReflectionMethod;
use Tests\Unit\UnitTestCase;

final class SingleValuesTest extends UnitTestCase
{
	public function testListedFieldsReturnedAsSent(): void {
		$post = [
			'id' => '5', 'remote_user' => ' admin ', 'flag' => '', 'weight' => '0',
			'email' => ['a@example.org'], 'other' => 'x',
		];
		$this->assertSame(
			['remote_user' => ' admin ', 'action' => 'learn', 'id' => '5', 'flag' => '', 'weight' => '0'],
			$this->call($post + ['action' => 'learn'], ['remote_user', 'action', 'id', 'flag', 'weight'])
		);
		$this->assertSame([], $this->log->lines);
	}

	public function testAbsentFieldsLeftOut(): void {
		$this->assertSame(['id' => '7'], $this->call(['id' => '7', 'email' => ['a@example.org']], ['remote_user', 'id']));
		$this->assertSame([], $this->call([], ['remote_user', 'id']));
	}

	/**
	 * @param array<array-key, mixed> $post
	 * @param list<string> $fields
	 */
	private function call(array $post, array $fields): mixed {
		$api = (new ReflectionClass(FuzzyMailApi::class))->newInstanceWithoutConstructor();
		return (new ReflectionMethod($api, 'requireSingleValues'))->invoke($api, $post, $fields);
	}
}
