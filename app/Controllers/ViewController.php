<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Controllers;

use Symfony\Component\HttpFoundation\Request;

use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Translation\Loader\XliffFileLoader;
use Symfony\Component\Translation\Translator;

use Symfony\Bridge\Twig\Extension\FormExtension;
use Symfony\Bridge\Twig\Form\TwigRendererEngine;
use Symfony\Bridge\Twig\AppVariable;
use Symfony\Component\Form\FormRenderer;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\RuntimeLoader\FactoryRuntimeLoader;

use Symfony\Component\Form\FormFactoryInterface;
use App\Core\Form\FormFactoryProvider;

use App\Twig\RqwatchExtension;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use App\Core\App;
use App\Configuration\AppConfig;
use App\Configuration\Config;

use App\Utils\Helper;

use App\Services\FuzzyService;

use ReflectionClass;

use RuntimeException;

class ViewController extends Controller
{
	/** @psalm-suppress PropertyNotSetInConstructor built on first use by twigView() */
	protected Environment $twig;
	/** @psalm-suppress PropertyNotSetInConstructor built on first use by twigFormView() */
	protected FormFactoryInterface $formFactory;

	protected int $refresh_rate;
	protected int $items_per_page;
	protected int $max_items;

	public function __construct() {
		parent::__construct();

		$this->refresh_rate   = Config::getInt('refresh_rate') ?? 0;
		$this->items_per_page = Config::getIntOr('items_per_page', 50);
		$this->max_items      = Config::getIntOr('max_items', 10000);
	}

	final public function twigView(): Environment {
		/** @psalm-suppress RedundantPropertyInitializationCheck $twig is built lazily here, not in the constructor */
		if (!isset($this->twig)) {
			$cache = AppConfig::TWIG_CACHE_PATH;

			/** @psalm-suppress RedundantCondition CLI_MODE is defined per entry point */
			if (defined('CLI_MODE') && CLI_MODE) {
				// crons run as root: never create root-owned dirs in the shared web cache
				$cache = false;
			} elseif (!is_dir($cache) && !@mkdir($cache, 0750, true)) {
				App::fileLogger()->warning("Twig cache dir not creatable: {$cache}; running uncached");
				$cache = false;
			} elseif (!is_writable($cache)) {
				App::fileLogger()->warning("Twig cache dir not writable: {$cache}; running uncached");
				$cache = false;
			}

			$loader = new FilesystemLoader(AppConfig::VIEWS_PATH);
			$this->twig = new Environment($loader,
				[
					'cache' => $cache,
					'debug' => false,
					'auto_reload' => true,
					// HTML-escape all output (Twig's default, made explicit)
					'autoescape' => 'html',
				]
			);

			// functions and filters; they read the URL generator and the CSRF
			// manager only when called (a cron renders mails without them)
			$this->twig->addExtension(new RqwatchExtension(
				fn(): UrlGeneratorInterface => $this->urlGenerator,
				$this->csrfManager(...),
			));
		}

		$this->twig->addGlobal('APP_NAME', Config::get('APP_NAME') ?? AppConfig::APP_NAME);
		$this->twig->addGlobal('APP_INFO', Config::get('APP_INFO') ?? AppConfig::APP_INFO);
		$this->twig->addGlobal('APP_LOGO_PATH', Config::get('APP_LOGO_PATH') ?? AppConfig::APP_LOGO_PATH);
		$this->twig->addGlobal('APP_LOGO_ALT', Config::get('APP_LOGO_ALT') ?? AppConfig::APP_LOGO_ALT);
		$this->twig->addGlobal('FOOTER_NAME', Config::get('FOOTER_NAME') ?? AppConfig::APP_NAME);
		$this->twig->addGlobal('FOOTER_URL', Config::get('FOOTER_URL') ?? AppConfig::GITHUB);
		$this->twig->addGlobal('FOOTER_VERSION', Config::get('FOOTER_VERSION') ?? AppConfig::APP_NAME);
		$this->twig->addGlobal('WEB_BASE', Helper::env_string('WEB_BASE') ?? '');
		$this->twig->addGlobal('NOTIFICATION_SCORE', Config::get('notification_score') ?? 1000);
		$this->twig->addGlobal('OPENIDC_LABEL', Helper::env_string('OPENIDC_LABEL') ?? 'Login with OpenID Connect');
		$this->twig->addGlobal('LOGIN_THROTTLE_ENABLED',
			Helper::env_bool('REDIS_ENABLE', false) &&
			Helper::env_bool('LOGIN_THROTTLE_ENABLE', false)
		);
		$this->twig->addGlobal('FUZZY_LEARN_ENABLED', FuzzyService::isEnabled());

		// a changed style.css or rqwatch.js gets a new URL, so browsers don't keep the old one
		/** @psalm-suppress RiskyTruthyFalsyComparison an unreadable file (false) and a 0 mtime both give no version */
		$this->twig->addGlobal('STYLE_VERSION', (string) (@filemtime(AppConfig::STYLE_CSS_PATH) ?: ''));
		/** @psalm-suppress RiskyTruthyFalsyComparison an unreadable file (false) and a 0 mtime both give no version */
		$this->twig->addGlobal('SCRIPT_VERSION', (string) (@filemtime(AppConfig::SCRIPT_JS_PATH) ?: ''));

		return $this->twig;
	}

	public function twigFormView(Request $request): Environment {
		/** @psalm-suppress RedundantPropertyInitializationCheck $twig is built lazily here, not in the constructor */
		if (!isset($this->twig)) {
			$this->twigView();
		}
			
		$vendorDirectory = AppConfig::VENDOR_PATH;
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

		$csrfManager = $this->csrfManager();

		// the Twig file that holds all the default markup for rendering forms
		// this file comes with TwigBridge
		$defaultFormTheme = 'form_div_layout.html.twig';

		// the path to TwigBridge library so Twig can locate the
		// form_div_layout.html.twig file
		$appVariableReflection = new ReflectionClass(AppVariable::class);
		$vendorTwigBridgeDirectory = dirname($appVariableReflection->getFileName()); // @phpstan-ignore argument.type (AppVariable is a userland class, so getFileName() is a path)

		$loader = $this->twig->getLoader();
		if (!$loader instanceof FilesystemLoader) {
			throw new RuntimeException('Twig loader is not a FilesystemLoader');
		}
		$loader->addPath($vendorTwigBridgeDirectory . '/Resources/views/Form');

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
