<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 Reading submitted form data (FormHelper): a valid submit's data, required
 text fields and optional ones. What can only be a bug (no data, a required
 field that is not text) fails with the form or field named; it does not
 read as empty. The same for a form's own options: the CSS class merge, a
 text option and a list of text.
*/

namespace Tests\Unit\Helpers;

use App\Utils\FormHelper;
use LogicException;
use PHPUnit\Framework\MockObject\Stub;
use Symfony\Component\Form\FormInterface;
use Tests\Unit\UnitTestCase;

final class FormDataTest extends UnitTestCase
{
	public function testSubmittedData(): void {
		/**
		 * @psalm-var FormInterface<array<string, mixed>>&Stub $form
		 * @phpstan-var FormInterface&Stub $form
		 */
		$form = $this->createStub(FormInterface::class);
		$form->method('getData')->willReturn(['username' => 'alice']);
		$this->assertSame(['username' => 'alice'], FormHelper::submittedData($form));
	}

	public function testSubmittedDataMissingFails(): void {
		/**
		 * @psalm-var FormInterface<array<string, mixed>>&Stub $form
		 * @phpstan-var FormInterface&Stub $form
		 */
		$form = $this->createStub(FormInterface::class);
		$form->method('getData')->willReturn(null);
		$form->method('getName')->willReturn('login_form');
		$this->expectException(LogicException::class);
		$this->expectExceptionMessage("Submitted form 'login_form' has no data");
		FormHelper::submittedData($form);
	}

	public function testText(): void {
		$this->assertSame('alice', FormHelper::text('alice', 'username'));
		$this->assertSame('', FormHelper::text('', 'username'));
	}

	public function testTextNullFails(): void {
		$this->expectException(LogicException::class);
		$this->expectExceptionMessage("Form field 'username' must be text, not null");
		FormHelper::text(null, 'username');
	}

	public function testTextArrayFails(): void {
		$this->expectException(LogicException::class);
		$this->expectExceptionMessage("Form field 'email' must be text, not array");
		FormHelper::text(['a@x.org'], 'email');
	}

	// a field left empty is null: an optional field's normal state
	public function testOptionalText(): void {
		$this->assertNull(FormHelper::optionalText(null, 'password'));
		$this->assertSame('secret', FormHelper::optionalText('secret', 'password'));
	}

	public function testOptionalTextOtherTypeFails(): void {
		$this->expectException(LogicException::class);
		$this->expectExceptionMessage("Form field 'password' must be text, not int");
		FormHelper::optionalText(5, 'password');
	}

	public function testWithClass(): void {
		$this->assertSame(['attr' => ['class' => 'onefieldform']], FormHelper::withClass([], 'onefieldform'));
		$this->assertSame(
			['role' => 'admin', 'attr' => ['id' => 'f', 'class' => 'wide onefieldform']],
			FormHelper::withClass(['role' => 'admin', 'attr' => ['id' => 'f', 'class' => 'wide']], 'onefieldform')
		);
	}

	public function testWithClassAttrNotArrayFails(): void {
		$this->expectException(LogicException::class);
		$this->expectExceptionMessage("Form option 'attr' must be an array, not string");
		FormHelper::withClass(['attr' => 'wide'], 'onefieldform');
	}

	public function testWithClassClassNotTextFails(): void {
		$this->expectExceptionMessage("Form option 'attr.class' must be text, not array");
		FormHelper::withClass(['attr' => ['class' => ['wide']]], 'onefieldform');
	}

	public function testTextOption(): void {
		$this->assertSame('admin', FormHelper::textOption(['role' => 'admin'], 'role'));
	}

	public function testTextOptionMissingFails(): void {
		$this->expectExceptionMessage("Form option 'map' must be text, not null");
		FormHelper::textOption(['role' => 'admin'], 'map');
	}

	public function testTextListOption(): void {
		$this->assertSame([], FormHelper::textListOption([], 'user_emails'), 'absent: none');
		$this->assertSame(['a@example.org', 'b@example.org'],
			FormHelper::textListOption(['user_emails' => [0 => 'a@example.org', 2 => 'b@example.org']], 'user_emails'), 'a list, whatever the keys');
	}

	public function testTextListOptionNotTextFails(): void {
		$this->expectExceptionMessage("Form option 'user_emails' must be a list of text, not of int");
		FormHelper::textListOption(['user_emails' => ['a@example.org', 5]], 'user_emails');
	}
}
