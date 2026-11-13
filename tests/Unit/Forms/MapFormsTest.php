<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The map entry forms: address fields need a valid address, the IP field
 an IP or network with its host bits clear and stores it in canonical
 form, the URL field anything not blank. A user's form offers only the
 user's own addresses as RCPT To; any other is refused.
*/

namespace Tests\Unit\Forms;

use App\Forms\MapEmailForm;
use App\Forms\MapIpForm;
use App\Forms\MapMailFromForm;
use App\Forms\MapMailFromRcptToForm;
use App\Forms\MapMailFromRcptToUserForm;
use App\Forms\MapUrlForm;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Validator\Validation;
use Tests\Unit\UnitTestCase;

final class MapFormsTest extends UnitTestCase
{
	/** @return array<string, array{class-string<FormTypeInterface<array<string, mixed>>>, string, string, bool}> form, field, value, valid */
	public static function oneFieldCases(): array {
		return [
			'mail from' => [MapMailFromForm::class, 'mail_from', 'spammer@example.org', true],
			'mail from, not an address' => [MapMailFromForm::class, 'mail_from', 'spammer', false],
			'mail from, empty' => [MapMailFromForm::class, 'mail_from', '', false],
			'email' => [MapEmailForm::class, 'email', 'a@example.org', true],
			'email, not an address' => [MapEmailForm::class, 'email', 'a@', false],
			'ip' => [MapIpForm::class, 'ip', '192.0.2.1', true],
			'ip network' => [MapIpForm::class, 'ip', '2001:db8::/32', true],
			'ip, host bits set' => [MapIpForm::class, 'ip', '192.0.2.1/24', false],
			'ip, a name' => [MapIpForm::class, 'ip', 'example.org', false],
			'url' => [MapUrlForm::class, 'url', 'example.org/path', true],
			'url, empty' => [MapUrlForm::class, 'url', '', false],
		];
	}

	/** @param class-string<FormTypeInterface<array<string, mixed>>> $class */
	#[DataProvider('oneFieldCases')]
	public function testOneField(string $class, string $field, string $value, bool $valid): void {
		$form = self::factory()->create($class);
		$form->submit([$field => $value]);
		$this->assertSame($valid, $form->isValid(), (string) $form->getErrors(true));
	}

	// duplicates compare equal: an IPv6 entry is stored compressed and in lower case
	public function testIpStoredCanonical(): void {
		$form = self::factory()->create(MapIpForm::class);
		$form->submit(['ip' => '2001:0DB8:0:0::/32']);
		$this->assertTrue($form->isValid(), (string) $form->getErrors(true));
		$this->assertSame('2001:db8::/32', $form->get('ip')->getData());
	}

	public function testAdminRcptToAnyAddress(): void {
		$form = self::factory()->create(MapMailFromRcptToForm::class);
		$form->submit(['mail_from' => 'spammer@example.org', 'rcpt_to' => 'bob@example.org']);
		$this->assertTrue($form->isValid(), (string) $form->getErrors(true));
	}

	/** @return array<string, array{list<string>, string, bool}> user's addresses, RCPT To, valid */
	public static function userRcptCases(): array {
		$mine = ['alice@example.org', 'a.smith@example.org'];
		return [
			'own address' => [$mine, 'alice@example.org', true],
			'own alias' => [$mine, 'a.smith@example.org', true],
			"someone else's" => [$mine, 'bob@example.org', false],
			'none chosen' => [$mine, '', false],
			'no addresses' => [[], 'alice@example.org', false],
		];
	}

	/** @param list<string> $emails */
	#[DataProvider('userRcptCases')]
	public function testUserRcptToOwnAddressesOnly(array $emails, string $rcptTo, bool $valid): void {
		$form = self::factory()->create(MapMailFromRcptToUserForm::class, null, ['user_emails' => $emails]);
		$form->submit(['mail_from' => 'spammer@example.org', 'rcpt_to' => $rcptTo]);
		$this->assertSame($valid, $form->isValid(), (string) $form->getErrors(true));
		if (!$valid) {
			$this->assertGreaterThan(0, $form->get('rcpt_to')->getErrors(true)->count());
		}
	}

	private static function factory(): FormFactoryInterface {
		return Forms::createFormFactoryBuilder()
			->addExtension(new ValidatorExtension(Validation::createValidator()))
			->getFormFactory();
	}
}
