<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The importer's metadata fields (rspamd's JSON, from outside): a single
 value passes, a text field takes a number in its exact string form, and
 anything else is logged and treated as absent so the mail is still
 imported.
*/

namespace Tests\Unit\Api;

use App\Api\MetadataImporterMultipartApi;
use App\Configuration\Config;
use App\Api\RqwatchApi;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionMethod;
use ReflectionProperty;
use Tests\Unit\UnitTestCase;

final class MetadataFieldsTest extends UnitTestCase
{
	private const array META = [
		'qid' => 'ABC123', 'score' => 7.5, 'size' => 1000, 'ip' => 3232235777, 'subject' => true,
		'from' => ['a@x.org'], 'action' => null,
	];

	public function testScalarFields(): void {
		$this->assertSame('ABC123', $this->call('metaScalar', 'qid'));
		$this->assertSame(7.5, $this->call('metaScalar', 'score'));
		$this->assertTrue($this->call('metaScalar', 'subject'));
		$this->assertNull($this->call('metaScalar', 'action'));
		$this->assertNull($this->call('metaScalar', 'unset'));
		$this->assertSame([], $this->log->lines);
	}

	public function testNonScalarIgnored(): void {
		$this->assertNull($this->call('metaScalar', 'from'));
		$this->assertSame(["warning [MetadataImporter] non-scalar value for metadata field 'from'; ignoring it"], $this->log->lines);
	}

	public function testTextFields(): void {
		$this->assertSame('ABC123', $this->call('metaString', 'qid'));
		$this->assertSame('3232235777', $this->call('metaString', 'ip'), 'a number in its exact form');
		$this->assertSame('7.5', $this->call('metaString', 'score'));
		$this->assertNull($this->call('metaString', 'action'));
		$this->assertSame([], $this->log->lines);
	}

	public function testBoolTextIgnored(): void {
		$this->assertNull($this->call('metaString', 'subject'));
		$this->assertSame(["warning [MetadataImporter] metadata field 'subject' is bool, not text; ignoring it"], $this->log->lines);
	}

	// the fallback for a store_settings that is not an array is config.php's own
	public function testDefaultStoreSettingsAreConfigPhps(): void {
		$shipped = Config::loadConfigFile(dirname(__DIR__, 3) . '/config/config.php');
		$this->assertSame($shipped['store_settings'] ?? null,
			(new ReflectionClassConstant(MetadataImporterMultipartApi::class, 'DEFAULT_STORE_SETTINGS'))->getValue());
	}

	private function call(string $method, string $key): mixed {
		$api = (new ReflectionClass(MetadataImporterMultipartApi::class))->newInstanceWithoutConstructor();
		(new ReflectionProperty(RqwatchApi::class, 'fileLogger'))->setValue($api, $this->log);
		(new ReflectionProperty(RqwatchApi::class, 'logPrefix'))->setValue($api, 'MetadataImporter');
		return (new ReflectionMethod($api, $method))->invoke($api, self::META, $key);
	}
}
