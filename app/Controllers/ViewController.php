<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Controllers;

use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Generator\UrlGenerator;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

use Symfony\Component\Form\Extension\Csrf\CsrfExtension;
use Symfony\Component\Security\Csrf\CsrfTokenManager;
use Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator;
use Symfony\Component\Security\Csrf\TokenStorage\SessionTokenStorage;

use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Translation\Loader\XliffFileLoader;
use Symfony\Component\Translation\Translator;

use Symfony\Bridge\Twig\Extension\FormExtension;
use Symfony\Bridge\Twig\Form\TwigRendererEngine;
use Symfony\Component\Form\FormRenderer;
use Symfony\Component\Form\Forms;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\RuntimeLoader\FactoryRuntimeLoader;

use Symfony\Component\Form\FormFactoryInterface;
use App\Core\Form\FormFactoryProvider;

use Twig\TwigFunction;

use App\Core\Config;
use App\Utils\Helper;

class ViewController extends Controller
{
	protected ?Environment $twig = null;
	protected ?FormFactoryInterface $formFactory = null;

	/*
	public function __construct() {
		parent::__construct();
	}
	*/

	final public function twigView(): Environment {
		if (!$this->twig ) {
			$viewsPath = APP_ROOT . '/app/Views';
			$loader = new FilesystemLoader($viewsPath);
			$this->twig = new Environment($loader, [
				'cache' => false,
				'debug' => true
			]);
		}

		$this->twig->addFunction(new TwigFunction('get_route', fn($name, $params = []) =>
			$this->urlGenerator->generate($name, $params)
		));

		$this->twig->addFunction(new TwigFunction('get_action_details', function ($symbols) {
			return Helper::get_action_details($symbols);
		}));

		$this->twig->addFunction(new TwigFunction('get_row_class', function ($action, $symbols = null) {
			return Helper::get_row_class($action, $symbols);
		}));

		$this->twig->addFunction(new TwigFunction('get_symbol_class', function ($symbol = null) {
			return Helper::get_symbol_class($symbol);
		}));

		$this->twig->addFunction(new TwigFunction('decodeEmail', function ($email) {
			return Helper::decodeEmail($email);
		}));
	
		$this->twig->addFunction(new TwigFunction('formatSizeUnits', function ($bytes) {
			return Helper::formatSizeUnits($bytes);
		}));
	
		$this->twig->addFunction(new TwigFunction('check_virus_from_all', function ($symbols) {
			return Helper::check_virus_from_all($symbols);
		}));
	
		$this->twig->addFunction(new TwigFunction('get_runtime', function ($startTime, $startMemory) {
			return $this->getRuntime();
		}));
	
		$this->twig->addFunction(new TwigFunction('gethostbyaddr', function ($ip) {
			return gethostbyaddr($ip);
		}));
	
		$this->twig->addFunction(new TwigFunction('truncate', function ($str, $len) {
			return mb_strimwidth($str, 0, $len, "...");
		}));

		$this->twig->addFunction(new TwigFunction('getDelivery', function ($action) {
			return Helper::getDelivery($action);
		}));

		$this->twig->addFunction(new TwigFunction('getAuthProvider', function ($id) {
			return Helper::getAuthProvider($id);
		}));

		$this->twig->addFilter(new \Twig\TwigFilter('nf', function ($number, $decimals = 0) {
			return number_format($number, $decimals, ',', '.');
		}));

		$this->twig->addGlobal('APP_NAME', Config::get('APP_NAME') ?? 'Rqwatch');
		$this->twig->addGlobal('APP_INFO', Config::get('APP_INFO') ?? 'Rspamd Quarantine Watch');
		$this->twig->addGlobal('FOOTER', Config::get('FOOTER') ?? 'Rqwatch');
		$this->twig->addGlobal('WEB_BASE', $_ENV['WEB_BASE'] ?? '');

		return $this->twig;
	}

	public function twigFormView(Request $request): Environment {
		if (!$this->twig ) {
			$this->twigView();
		}
			
		$vendorDirectory = realpath(__DIR__.'/../../vendor');
		$vendorFormDirectory = $vendorDirectory.'/symfony/form';
		$vendorValidatorDirectory = $vendorDirectory.'/symfony/validator';
		
		$translator = new Translator('en');
		// somehow load some translations into it
		$translator->addLoader('xlf', new XliffFileLoader());

		// there are built-in translations for the core error messages
      $translator->addResource(
            'xlf',
            $vendorFormDirectory.'/Resources/translations/validators.en.xlf',
            'en',
            'validators'
      );
      $translator->addResource(
            'xlf',
            $vendorValidatorDirectory.'/Resources/translations/validators.en.xlf',
            'en',
            'validators'
      );

      // creates a RequestStack object using the current request
      $requestStack = new RequestStack([$request]);

      $csrfGenerator = new UriSafeTokenGenerator();
      $csrfStorage = new SessionTokenStorage($requestStack);
      $csrfManager = new CsrfTokenManager($csrfGenerator, $csrfStorage);

		// the Twig file that holds all the default markup for rendering forms
		// this file comes with TwigBridge
		$defaultFormTheme = 'form_div_layout.html.twig';

		// the path to TwigBridge library so Twig can locate the
		// form_div_layout.html.twig file
      $appVariableReflection = new \ReflectionClass('\Symfony\Bridge\Twig\AppVariable');
      $vendorTwigBridgeDirectory = dirname($appVariableReflection->getFileName());

		$this->twig->getLoader()->addPath($vendorTwigBridgeDirectory . '/Resources/views/Form');

		$formEngine = new TwigRendererEngine([$defaultFormTheme], $this->twig);

      $this->twig->addRuntimeLoader(new FactoryRuntimeLoader([
            FormRenderer::class => function () use ($formEngine, $csrfManager): FormRenderer {
               return new FormRenderer($formEngine, $csrfManager);
            },
      ]));

      // adds the FormExtension to Twig
      $this->twig
			->addExtension(new FormExtension());

		// and TranslationExtension (it gives us trans filter)
		$this->twig
			->addExtension(new TranslationExtension($translator));

		$this->formFactory = FormFactoryProvider::getFactory($request);

		return $this->twig;
	}
}
