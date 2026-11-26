<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The app's one form factory, built on the first request: its forms read a
 Request, carry a CSRF token kept in that request's session, and run their
 validation constraints.
*/

namespace Tests\Unit\Forms;

use App\Core\Form\FormFactoryProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Validator\Constraints\NotBlank;

final class FormFactoryProviderTest extends TestCase
{
	// built for this test's request, and not left for the next
	#[\Override]
	protected function setUp(): void {
		(new ReflectionProperty(FormFactoryProvider::class, 'formFactory'))->setValue(null, null);
	}

	#[\Override]
	protected function tearDown(): void {
		(new ReflectionProperty(FormFactoryProvider::class, 'formFactory'))->setValue(null, null);
	}

	/** @param array<string, mixed> $post */
	private static function request(string $method = 'GET', array $post = []): Request {
		$request = Request::create('/', $method, $post);
		$request->setSession(new Session(new MockArraySessionStorage()));
		return $request;
	}

	private static function form(Request $request): FormInterface {
		return FormFactoryProvider::getFactory($request)->createBuilder(FormType::class)
			->add('name', TextType::class, ['constraints' => [new NotBlank()]])
			->getForm();
	}

	public function testBuiltOnce(): void {
		$factory = FormFactoryProvider::getFactory(self::request());
		$this->assertSame($factory, FormFactoryProvider::getFactory(self::request()));
	}

	// a POST Request is read (HttpFoundation), the token checked, the constraints run
	public function testTokenAndConstraints(): void {
		$request = self::request();
		/** @var mixed $token */
		$token = self::form($request)->createView()->children['_token']->vars['value'];
		$this->assertIsString($token);
		$this->assertNotSame('', $token);

		$post = self::request('POST', ['form' => ['name' => '', '_token' => $token]]);
		$post->setSession($request->getSession());
		$form = self::form($post);
		$form->handleRequest($post);
		$this->assertTrue($form->isSubmitted());
		$this->assertSame("ERROR: This value should not be blank.\n", (string) $form->getErrors(true));
	}

	public function testWrongTokenRefused(): void {
		$post = self::request('POST', ['form' => ['name' => 'x', '_token' => 'forged']]);
		$form = self::form($post);
		$form->handleRequest($post);
		$this->assertTrue($form->isSubmitted());
		$this->assertSame("ERROR: The CSRF token is invalid. Please try to resubmit the form.\n", (string) $form->getErrors(true));
	}
}
