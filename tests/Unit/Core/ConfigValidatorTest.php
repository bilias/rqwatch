<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The boot-time config check: config.php and the local example pass as
 shipped, every failing setting is reported, nothing is converted (an int
 written as a string is refused), and every setting the code reads by name
 has a type.
*/

namespace Tests\Unit\Core;

use App\Configuration\Config;
use App\Configuration\ConfigValidator;
use Tests\Unit\UnitTestCase;

final class ConfigValidatorTest extends UnitTestCase
{
	/** @return array<string, mixed> config.php with config.local-example.php over it */
	private static function shipped(): array {
		$dir = dirname(__DIR__, 3) . '/config';
		return array_merge(
			Config::loadConfigFile("{$dir}/config.php"),
			Config::loadConfigFile("{$dir}/config.local-example.php"),
		);
	}

	public function testShippedConfigPasses(): void {
		$vars = self::shipped();
		$this->assertSame([], array_values(array_diff(array_keys(ConfigValidator::TYPES), array_keys($vars))), 'typed but not shipped');
		$this->assertSame([], ConfigValidator::errors($vars));
	}

	public function testEveryFailureReported(): void {
		$vars = [
			'MAP_FILE_PERM' => '0644',
			'show_charts' => 'false',
			'items_per_page' => 50.0,
			'API_SERVERS' => 'mx1',
			'dns_resolv_redis_key' => ' ',
			'MAP_DIR' => null,
		] + self::shipped();
		$this->assertSame([
			'API_SERVERS must be an array, not string',
			'MAP_DIR is not set',
			'MAP_FILE_PERM must be an integer, not string',
			'items_per_page must be an integer, not float',
			'show_charts must be true or false, not string',
			'dns_resolv_redis_key is empty',
		], ConfigValidator::errors($vars));
	}

	public function testAcceptedForms(): void {
		$vars = [
			'show_charts' => 1, 'dns_resolv' => 0,
			'notification_score' => 50,
			'geoip_country_db' => null,
		] + self::shipped();
		unset($vars['admin_notification_rcpt']);
		$this->assertSame([], ConfigValidator::errors($vars));
	}

	// every setting read by name through Config is typed
	public function testEveryReadSettingTyped(): void {
		$keys = [];
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 3) . '/app'));
		foreach ($files as $file) {
			if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
				preg_match_all("/Config::get(?:Int|IntOr|String|Bool)?\('([A-Za-z0-9_]+)'/",
					(string) file_get_contents($file->getPathname()), $m);
				$keys = [...$keys, ...$m[1]];
			}
		}
		$this->assertNotSame([], $keys);
		$this->assertSame([], array_values(array_diff(array_unique($keys), array_keys(ConfigValidator::TYPES))));
	}
}
