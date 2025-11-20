<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Core\Form;

use Symfony\Component\Form\Extension\HttpFoundation\HttpFoundationExtension;
use Symfony\Component\Form\Forms;
use Symfony\Component\Form\FormFactoryInterface;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

use Symfony\Component\HttpFoundation\Session\Session;
//use Symfony\Component\HttpFoundation\Session\Storage\NativeSessionStorage;

use Symfony\Component\Form\Extension\Csrf\CsrfExtension;
use Symfony\Component\Security\Csrf\CsrfTokenManager;
use Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator;
use Symfony\Component\Security\Csrf\TokenStorage\SessionTokenStorage;

use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Validator\Validation;

use Symfony\Bridge\Twig\Extension\FormExtension;
use Symfony\Bridge\Twig\Form\TwigRendererEngine;
use Symfony\Component\Form\FormRenderer;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\RuntimeLoader\FactoryRuntimeLoader;

class FormFactoryProvider
{
	private static ?FormFactoryInterface $formFactory = null;

	public static function getFactory(Request $request): FormFactoryInterface {
		if (self::$formFactory === null) {
			$session = $request->getSession();

			// creates a RequestStack object using the current request
			$requestStack = new RequestStack([$request]);

			$csrfGenerator = new UriSafeTokenGenerator();
			$csrfStorage = new SessionTokenStorage($requestStack);
			$csrfManager = new CsrfTokenManager($csrfGenerator, $csrfStorage);

			/*
			$vendorDirectory = realpath(__DIR__.'/../vendor');
			$vendorFormDirectory = $vendorDirectory.'/symfony/form';
			$vendorValidatorDirectory = $vendorDirectory.'/symfony/validator';
			*/

			// creates the validator - details will vary
			$validator = Validation::createValidator();

			self::$formFactory = Forms::createFormFactoryBuilder()
				->addExtension(new HttpFoundationExtension())
				->addExtension(new CsrfExtension($csrfManager))
				->addExtension(new ValidatorExtension($validator))
				->getFormFactory();
		}
		return self::$formFactory;
	}
}
