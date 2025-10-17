<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU Affero General Public License version 3
as published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU Affero General Public License for more details.

You should have received a copy of the GNU Affero General Public License
along with this program.  If not, see <https://www.gnu.org/licenses/>.
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

			$vendorDirectory = realpath(__DIR__.'/../vendor');
			$vendorFormDirectory = $vendorDirectory.'/symfony/form';
			$vendorValidatorDirectory = $vendorDirectory.'/symfony/validator';

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
