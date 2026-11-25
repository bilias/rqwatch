<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Helper::isPublishedSecret() and hasPublishedSecret(): a CHANGE_ME_
 placeholder or a secret shipped before them is refused, alone or inside a
 DSN; anything else, however close, is not. The code's hash list matches the
 published values exactly, so a wrong or missing hash fails here.
*/

namespace Tests\Unit\Helpers;

use App\Utils\Helper;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClassConstant;
use Tests\PublishedSecrets;
use Tests\Unit\UnitTestCase;

final class PublishedSecretTest extends UnitTestCase
{
	public function testHashListIsThePublishedValues(): void {
		/** @var mixed $hashes */
		$hashes = (new ReflectionClassConstant(Helper::class, 'PUBLISHED_SECRETS_SHA256'))->getValue();
		$this->assertIsArray($hashes);
		$expected = array_map(fn (string $value): string => hash('sha256', $value), array_values(PublishedSecrets::VALUES));
		sort($expected);
		sort($hashes);
		$this->assertSame($expected, $hashes);
	}

	/** @return array<string, array{string}> */
	public static function published(): array {
		$cases = [];
		foreach (PublishedSecrets::VALUES as $shippedAs => $value) {
			$cases[$shippedAs] = [$value];
			$cases["{$shippedAs}, blanks around"] = [" {$value} "];
		}
		$cases['placeholder'] = ['CHANGE_ME_LqdziZSMQB8sNJPXKgEcIgvLZ15IZSb7fB'];
		$cases['placeholder, any tail'] = ['CHANGE_ME_x'];
		return $cases;
	}

	#[DataProvider('published')]
	public function testPublished(string $value): void {
		$this->assertTrue(Helper::isPublishedSecret($value));
	}

	/** @return array<string, array{string}> */
	public static function ownValues(): array {
		$db = PublishedSecrets::VALUES['DB_PASS'];
		return [
			'own' => ['s3cret-of-my-own'],
			'empty' => [''],
			'one character changed' => [substr($db, 0, -1) . 'X'],
			'one character more' => ["{$db}x"],
			'a part of one' => [substr($db, 0, 32)],
			'lower-case placeholder' => ['change_me_x'],
			'placeholder later' => ['x-CHANGE_ME_x'],
		];
	}

	#[DataProvider('ownValues')]
	public function testOwnValue(string $value): void {
		$this->assertFalse(Helper::isPublishedSecret($value));
	}

	/** @return array<string, array{string, bool}> DSN, has a published secret */
	public static function dsns(): array {
		$redis = PublishedSecrets::VALUES['REDIS_DSN'];
		$mailer = PublishedSecrets::VALUES['MAILER_DSN'];
		return [
			'REDIS_DSN as shipped' => ['redis:rqwatch:CHANGE_ME_o3v6lOUYSznSMBqkRJXSkXIYcC75lBc6kg@?host[10.10.10.2:5000]&dbindex=15', true],
			'REDIS_DSN as shipped before' => ["redis:rqwatch:{$redis}@?host[10.10.10.2:5000]&dbindex=15", true],
			'MAILER_DSN as shipped' => ['smtp://rqwatch@example.com:CHANGE_ME_kZRaE6hX@smtp.example.com:587?require_tls=true', true],
			'MAILER_DSN as shipped before' => ["smtp://rqwatch@example.com:{$mailer}@smtp.example.com:587", true],
			'another key\'s value' => ['smtp://u:' . PublishedSecrets::VALUES['MAIL_API_PASS'] . '@smtp.example.com', true],
			'own password' => ['redis:rqwatch:my-0wn_pass@?host[10.10.10.2:5000]', false],
			'no password' => ['redis://127.0.0.1:6379', false],
			'null transport' => ['null://null', false],
			'empty' => ['', false],
		];
	}

	#[DataProvider('dsns')]
	public function testDsn(string $dsn, bool $published): void {
		$this->assertSame($published, Helper::hasPublishedSecret($dsn));
	}
}
