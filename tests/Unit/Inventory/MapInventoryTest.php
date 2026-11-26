<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 MapInventory's map config check: every config, built-in or custom from
 the DB, needs a known model, a text description, a non-empty list of
 field names, one of the map form classes and a list of roles, or
 getMapConfigs() throws; it returns the configs it checked. An unknown
 field definition throws too. And the IP or CIDR check of the ip map
 fields: host bits clear, with a suggestion, and the canonical form
 MapIpForm stores. The built-in maps (who may manage them) and the field
 definitions are pinned as they are: a change is the prompt to review it.
*/

namespace Tests\Unit\Inventory;

use App\Forms\MapIpForm;
use App\Forms\MapMailFromForm;
use App\Forms\MapMailFromRcptToForm;
use App\Forms\MapMimeFromForm;
use App\Forms\MapMimeFromRcptToForm;
use App\Forms\MapWithCustomFieldForm;
use App\Inventory\MapInventory;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Validation;
use Tests\Unit\UnitTestCase;

final class MapInventoryTest extends UnitTestCase
{
	// a custom map's config, as getMapConfigs() builds it from the DB
	private const array CUSTOM = [
		'model' => 'MapCustom',
		'description' => 'Custom map',
		'fields' => ['domain'],
		'map_form' => MapWithCustomFieldForm::class,
		'access' => ['admin'],
	];

	public function testBuiltInConfigsPass(): void {
		$configs = MapInventory::getMapConfigs(null, false);
		$this->assertNotEmpty($configs);
		foreach ($configs as $name => $config) {
			$this->assertContains($config['model'], ['MapCombined', 'MapCustom'], $name);
		}
	}

	public function testBuiltInFieldsDefined(): void {
		foreach (MapInventory::getMapConfigs(null, false) as $name => $config) {
			foreach ($config['fields'] as $field) {
				$this->assertNotSame('', MapInventory::fieldDefinition($field)['description'], "{$name}: {$field}");
			}
		}
	}

	// map => model, description, fields, form, roles that may manage it
	public function testBuiltInMaps(): void {
		$maps = [];
		foreach (MapInventory::getMapConfigs(null, false) as $name => $config) {
			$maps[$name] = [$config['model'], $config['description'], $config['fields'], $config['map_form'], $config['access']];
		}
		$this->assertSame([
			'mail_from_rcpt_to_whitelist' => ['MapCombined', 'Mail From/RCPT_TO Whitelist', ['mail_from', 'rcpt_to'], MapMailFromRcptToForm::class, ['admin', 'user']],
			'mail_from_rcpt_to_blacklist' => ['MapCombined', 'Mail From/RCPT_TO Blacklist', ['mail_from', 'rcpt_to'], MapMailFromRcptToForm::class, ['admin', 'user']],
			'mime_from_rcpt_to_whitelist' => ['MapCombined', 'MIME From/RCPT_TO Whitelist', ['mime_from', 'rcpt_to'], MapMimeFromRcptToForm::class, ['admin', 'user']],
			'mime_from_rcpt_to_blacklist' => ['MapCombined', 'MIME From/RCPT_TO Blacklist', ['mime_from', 'rcpt_to'], MapMimeFromRcptToForm::class, ['admin', 'user']],
			'mail_from_whitelist' => ['MapCombined', 'Mail From Whitelist', ['mail_from'], MapMailFromForm::class, ['admin']],
			'mail_from_blacklist' => ['MapCombined', 'Mail From Blacklist', ['mail_from'], MapMailFromForm::class, ['admin']],
			'mime_from_whitelist' => ['MapCombined', 'MIME From Whitelist', ['mime_from'], MapMimeFromForm::class, ['admin']],
			'mime_from_blacklist' => ['MapCombined', 'MIME From Blacklist', ['mime_from'], MapMimeFromForm::class, ['admin']],
			'ip_whitelist' => ['MapCombined', 'IP Whitelist', ['ip'], MapIpForm::class, ['admin']],
			'ip_blacklist' => ['MapCombined', 'IP Blacklist', ['ip'], MapIpForm::class, ['admin']],
		], $maps);
	}

	// field => description, form type, label, required, attributes, constraint classes, help
	public function testFieldDefinitions(): void {
		/** @var array<string, list<mixed>> $fields */
		$fields = [];
		foreach (MapInventory::getFieldDefinitions() as $name => $definition) {
			$options = $definition['field_options'];
			$constraints = $options['constraints'] ?? [];
			$this->assertIsArray($constraints);
			$fields[$name] = [
				$definition['description'],
				$definition['type'],
				$options['label'] ?? null,
				$options['required'] ?? null,
				$options['attr'] ?? [],
				array_map(fn (mixed $constraint): string => get_debug_type($constraint), $constraints),
				$options['help'] ?? null,
			];
		}
		$this->assertSame([
			'mail_from' => ['Mail From', EmailType::class, 'Mail From:', true, ['class' => 'uniform-input'], [NotBlank::class, Email::class], null],
			'mime_from' => ['MIME From', EmailType::class, 'MIME From:', true, ['class' => 'uniform-input'], [NotBlank::class, Email::class], null],
			'rcpt_to' => ['RCPT To', EmailType::class, 'RCPT To:', true, ['class' => 'uniform-input'], [NotBlank::class, Email::class], null],
			'ip' => ['IP Address', TextType::class, 'IP Address:', true, ['class' => 'uniform-input'], [NotBlank::class, Callback::class], null],
			'url' => ['URL', TextType::class, 'URL:', true, ['class' => 'uniform-input'], [NotBlank::class], null],
			'email' => ['Email', TextType::class, 'Email:', true, ['class' => 'uniform-input'], [NotBlank::class, Email::class], null],
			'custom' => ['Custom Field', TextareaType::class, 'Custom Field:', true, ['class' => 'uniform-input map-entries-input', 'rows' => 5], [NotBlank::class], "One entry per line\nLines starting with # are inserted as disabled entries"],
		], $fields);
	}

	public function testUnknownFieldDefinitionThrows(): void {
		$this->expectExceptionMessage("Unknown map field 'domain'");
		MapInventory::fieldDefinition('domain');
	}

	public function testCustomConfigPasses(): void {
		$this->assertSame(['custom' => self::CUSTOM], $this->check(['custom' => self::CUSTOM]));
	}

	/** @return array<string, array{array<string, mixed>, string}> */
	public static function badConfigs(): array {
		$without = static fn (string $key): array => array_diff_key(self::CUSTOM, [$key => true]);
		return [
			'no model' => [$without('model'), "Missing or null required field 'model' in map config 'm'"],
			'no fields' => [$without('fields'), "Missing or null required field 'fields' in map config 'm'"],
			'no map_form' => [$without('map_form'), "Missing or null required field 'map_form' in map config 'm'"],
			'null access' => [['access' => null] + self::CUSTOM, "Missing or null required field 'access' in map config 'm'"],
			'unknown model' => [['model' => 'MapFoo'] + self::CUSTOM, "Unknown model in map config 'm'"],
			'model not a string' => [['model' => 1] + self::CUSTOM, "Unknown model in map config 'm'"],
			'fields a string' => [['fields' => 'domain'] + self::CUSTOM, "Map config 'm' needs a non-empty list of fields"],
			'fields empty' => [['fields' => []] + self::CUSTOM, "Map config 'm' needs a non-empty list of fields"],
			'fields not a list' => [['fields' => [1 => 'domain']] + self::CUSTOM, "Map config 'm' needs a non-empty list of fields"],
			'empty field name' => [['fields' => ['domain', '']] + self::CUSTOM, "Map config 'm' has an invalid field name"],
			'field name not a string' => [['fields' => [5]] + self::CUSTOM, "Map config 'm' has an invalid field name"],
			'no description' => [$without('description'), "Missing or null required field 'description' in map config 'm'"],
			'description not text' => [['description' => 5] + self::CUSTOM, "Map config 'm' needs a text description"],
			'map_form not a map form' => [['map_form' => \stdClass::class] + self::CUSTOM, "Map config 'm' has an unknown map_form"],
			'map_form not a class' => [['map_form' => 'NoSuchForm'] + self::CUSTOM, "Map config 'm' has an unknown map_form"],
			'access a string' => [['access' => 'admin'] + self::CUSTOM, "Map config 'm' needs a list of roles"],
			'role not text' => [['access' => ['admin', 1]] + self::CUSTOM, "Map config 'm' has an invalid role"],
		];
	}

	/** @param array<string, mixed> $config */
	#[DataProvider('badConfigs')]
	public function testBadConfigThrows(array $config, string $message): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage($message);
		$this->check(['m' => $config]);
	}

	/** @return array<string, array{mixed, string|null}> */
	public static function ipOrCidrCases(): array {
		$invalid = 'Invalid IP address or CIDR network.';
		return [
			'IPv4 network' => ['10.0.0.0/8', null],
			'IPv4 address' => ['10.0.0.1', null],
			'IPv4 /32' => ['10.0.0.1/32', null],
			'IPv4 /0' => ['0.0.0.0/0', null],
			'IPv6 address' => ['::1', null],
			'IPv6 network' => ['2001:db8::/32', null],
			'IPv6 /128' => ['2001:db8::1/128', null],
			'IPv4 host bits' => ['10.1.2.3/8', '10.1.2.3/8 is not a network address; did you mean 10.0.0.0/8?'],
			'IPv4 host bits, not on a byte' => ['192.168.1.77/26', '192.168.1.77/26 is not a network address; did you mean 192.168.1.64/26?'],
			'IPv4 /0 with host bits' => ['10.0.0.0/0', '10.0.0.0/0 is not a network address; did you mean 0.0.0.0/0?'],
			'IPv6 host bits' => ['2001:db8::1/32', '2001:db8::1/32 is not a network address; did you mean 2001:db8::/32?'],
			'IPv4 host bits, top bit of a byte' => ['10.128.0.0/8', '10.128.0.0/8 is not a network address; did you mean 10.0.0.0/8?'],
			'IPv4 prefix too long' => ['10.0.0.0/33', $invalid],
			'IPv6 prefix too long' => ['2001:db8::/129', $invalid],
			'prefix with a leading zero' => ['10.0.0.0/08', $invalid],
			'empty prefix' => ['10.0.0.0/', $invalid],
			'prefix not a number' => ['10.0.0.0/x', $invalid],
			'a second slash' => ['10.0.0.0/8/x', $invalid],
			'prefix ending in a line feed' => ["10.0.0.0/8\n", $invalid],
			'octet out of range' => ['300.1.1.1', $invalid],
			'not an IP' => ['bad/8', $invalid],
			// left to NotBlank on the field
			'empty' => ['', null],
			'not a string' => [5, null],
			'null' => [null, null],
		];
	}

	#[DataProvider('ipOrCidrCases')]
	public function testValidateIpOrCidr(mixed $value, ?string $message): void {
		$violations = Validation::createValidator()
			->validate($value, new Callback([MapInventory::class, 'validateIpOrCidr']));
		$messages = [];
		foreach ($violations as $violation) {
			$messages[] = (string) $violation->getMessage();
		}
		$this->assertSame($message === null ? [] : [$message], $messages);
	}

	/** @return array<string, array{string, string}> */
	public static function canonicalCases(): array {
		return [
			'IPv4' => ['192.0.2.1', '192.0.2.1'],
			'IPv4 network' => ['192.0.2.0/24', '192.0.2.0/24'],
			'IPv6 compressed, lowercased' => ['2001:0DB8:0:0::1', '2001:db8::1'],
			'IPv6 network, prefix kept' => ['2001:0DB8:0000::/32', '2001:db8::/32'],
			'IPv4-mapped IPv6' => ['::FFFF:192.0.2.1', '::ffff:192.0.2.1'],
			'not an IP' => ['example.org', 'example.org'],
			'a second slash, kept' => ['192.0.2.1/24/x', '192.0.2.1/24/x'],
			'invalid IPv4' => ['192.0.2.300', '192.0.2.300'],
			'empty' => ['', ''],
		];
	}

	#[DataProvider('canonicalCases')]
	public function testCanonicalIpOrCidr(string $value, string $expected): void {
		$this->assertSame($expected, MapInventory::canonicalIpOrCidr($value));
	}

	/** @return array<string, array{string, string}> */
	public static function urlHostCases(): array {
		return [
			'bracketed, as copied from a browser' => ['[::ffff:d93c:ed72]', '::ffff:217.60.237.114'],
			'bare hex form' => ['::ffff:d93c:ed72', '::ffff:217.60.237.114'],
			'expanded and uppercase, as in a body' => ['[0000:0000:0000:0000:0000:FFFF:D93C:ED72]', '::ffff:217.60.237.114'],
			'disabled entry keeps its #' => ['#[::FFFF:d93c:ed72]', '#::ffff:217.60.237.114'],
			'surrounding spaces kept' => ['  [2001:DB8:0:0:0:0:0:1]  ', '  2001:db8::1  '],
			'several lines, CRLF to LF' => ["evil.com\r\n[::1]\r\n\r\n#::FFFF:1.2.3.4", "evil.com\n::1\n\n#::ffff:1.2.3.4"],
			'domain' => ['example.org', 'example.org'],
			// a full URL: rspamd rewrites an IP host in it too
			'full URL, its IPv6 host as rspamd writes it' => ['http://[::ffff:d93c:ed72]/q9x-oml?lang=el', 'http://[::ffff:217.60.237.114]/q9x-oml?lang=el'],
			'full URL, user, port and a disabled entry' => ['#https://u@[2001:DB8::1]:8443/a', '#https://u@[2001:db8::1]:8443/a'],
			'full URL, host only' => ['http://[0:0:0:0:0:0:0:1]', 'http://[::1]'],
			'full URL, IPv4 in brackets: none' => ['http://[192.0.2.1]/x', 'http://192.0.2.1/x'],
			'full URL, IPv4, kept' => ['http://192.0.2.1/x', 'http://192.0.2.1/x'],
			'full URL, a domain, kept' => ['https://Example.org/[x]', 'https://Example.org/[x]'],
			'full URL, not an address, kept' => ['http://[zz]/x', 'http://[zz]/x'],
			'full URL and more, kept' => ['http://[::FFFF:d93c:ed72]/x note', 'http://[::FFFF:d93c:ed72]/x note'],
			'IPv4, kept' => ['1.2.3.4', '1.2.3.4'],
			'IPv4 in brackets, as rspamd reads it' => ['[1.2.3.4]', '1.2.3.4'],
			'unbalanced bracket, kept' => ['[::ffff:d93c:ed72', '[::ffff:d93c:ed72'],
			'not an address, kept' => ['abc:def', 'abc:def'],
			'an address and more, kept' => ['[::FFFF:d93c:ed72] note', '[::FFFF:d93c:ed72] note'],
			'a bare address and more, kept' => ['::FFFF:d93c:ed72 note', '::FFFF:d93c:ed72 note'],
			'empty' => ['', ''],
		];
	}

	#[DataProvider('urlHostCases')]
	public function testCanonicalUrlHostEntries(string $value, string $expected): void {
		$this->assertSame($expected, MapInventory::canonicalUrlHostEntries($value));
	}

	/** @param array<string, array<string, mixed>> $configs */
	private function check(array $configs): mixed {
		return (new ReflectionMethod(MapInventory::class, 'checkMapConfigs'))->invoke(null, $configs);
	}
}
