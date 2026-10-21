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
use Symfony\Component\Form\FormRenderer;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\RuntimeLoader\FactoryRuntimeLoader;

use Symfony\Component\Form\FormFactoryInterface;
use App\Core\Form\FormFactoryProvider;

use Twig\TwigFunction;
use Twig\TwigFilter;

use App\Core\App;
use App\Configuration\AppConfig;
use App\Configuration\Config;

use App\Core\Routing\RouteName;
use App\Utils\Helper;

use App\Services\FuzzyService;

use ReflectionClass;

use RuntimeException;

class ViewController extends Controller
{
	protected ?Environment $twig = null;
	protected ?FormFactoryInterface $formFactory = null;

	protected int $refresh_rate;
	protected int $items_per_page;
	protected int $max_items;

	public function __construct() {
		parent::__construct();

		$this->refresh_rate   = (int) Config::get('refresh_rate');
		$this->items_per_page = (int) Config::get('items_per_page') ?: 50;
		$this->max_items      = (int) Config::get('max_items') ?: 10000;
	}

	final public function twigView(): Environment {
		if (!$this->twig ) {
			$cache = AppConfig::TWIG_CACHE_PATH;

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
		}

		$this->twig->addFunction(new TwigFunction('get_route', fn($name, $params = []) =>
			$this->urlGenerator->generate($name, $params)
		));

		$this->twig->addFunction(new TwigFunction('route', function (string $caseName, array $params = []) {
			$enumClass = RouteName::class;
			if (!defined("$enumClass::$caseName")) {
				throw new RuntimeException("Unknown RouteName enum case: $caseName");
			}

			$enumCase = constant("$enumClass::$caseName"); // gets the enum case object
			return $this->url($enumCase, $params);
		}));

		// token for the logout link; checked by LoginController::logout()
		$this->twig->addFunction(new TwigFunction('logout_token', fn() =>
			$this->csrfManager()->getToken('logout')->getValue()
		));

		$this->twig->addFunction(new TwigFunction('get_action_details', function ($symbols) {
			return Helper::get_action_details($symbols);
		}));

		// labels read once per request: the listing calls this for every row
		$fuzzyLabels = null;
		$this->twig->addFunction(new TwigFunction('get_fuzzy_hits', function ($symbols) use (&$fuzzyLabels) {
			if (empty($symbols) || !is_array($symbols)) {
				return [];
			}
			$fuzzyLabels ??= FuzzyService::symbolLabels();
			return Helper::getFuzzyHits($symbols, $fuzzyLabels);
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
	
		/* unused
		$this->twig->addFunction(new TwigFunction('check_virus_from_all', function ($symbols) {
			return Helper::check_virus_from_all($symbols);
		}));
	
		$this->twig->addFunction(new TwigFunction('get_runtime', function ($startTime, $startMemory) {
			return $this->getRuntime();
		}));
		*/
	
		$this->twig->addFunction(new TwigFunction('gethostbyaddr', function ($ip) {
			return Helper::my_gethostbyaddr($ip);
		}));
	
		$this->twig->addFunction(new TwigFunction('truncate', function ($str, $len) {
			return mb_strimwidth((string) $str, 0, $len, "...");
		}));

		$this->twig->addFunction(new TwigFunction('getDelivery', function ($action) {
			return Helper::getDelivery($action);
		}));

		$this->twig->addFunction(new TwigFunction('getAuthProvider', function ($id) {
			return Helper::getAuthProvider($id);
		}));

		$this->twig->addFilter(new TwigFilter('nf', function ($number, $decimals = 0) {
			return number_format($number, $decimals, ',', '.');
		}));


		// Escape text, turn http(s) URLs into links opening in a new tab.
		// Output is marked safe; nl2br (pre_escape) won't re-escape it.
		// {{ message|linkify|nl2br }} opens links in a new tab (the default).
		// {{ message|linkify(false)|nl2br }} opens them in the same tab.
		$this->twig->addFilter(new TwigFilter('linkify', function (string $text, bool $newTab = true): string {
			$target = $newTab ? ' target="_blank" rel="noopener noreferrer"' : '';
			$parts = preg_split('~(https?://[^\s<>"\']+)~i', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
			$out = '';
			foreach ($parts as $i => $part) {
				// even indexes: plain text, odd indexes: captured URLs
				if ($i % 2 === 0) {
					$out .= htmlspecialchars($part, ENT_QUOTES);
					continue;
				}
				$url = rtrim($part, '.,;:!?)');
				$e = htmlspecialchars($url, ENT_QUOTES);
				$out .= '<a href="' . $e . '"' . $target . '>' . $e . '</a>'
					. htmlspecialchars(substr($part, strlen($url)), ENT_QUOTES);
			}
			return $out;
		}, ['is_safe' => ['html']]));


		// Escape text; in words mixing Latin with another script (homoglyph
		// spoofing, e.g. Cyrillic о/а in "Eurоbаnk"), wrap non-Latin letters
		// and invisible format chars (\p{Cf}) in <mark class="homoglyph">.
		// Pure Greek / pure Latin words are untouched. Output is marked safe.
		$this->twig->addFilter(new TwigFilter('mark_homoglyphs', function (?string $text): string {
			$parts = preg_split('/([\p{L}\p{M}\p{Cf}]+)/u', (string) $text, -1, PREG_SPLIT_DELIM_CAPTURE);
			if ($parts === false) {
				return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE);
			}
			$out = '';
			foreach ($parts as $i => $part) {
				// even indexes: separators, odd indexes: words
				$mixed = $i % 2 === 1 && preg_match('/\p{L}/u', $part)
					&& (preg_match('/\p{Cf}/u', $part)
						|| (preg_match('/\p{Latin}/u', $part) && preg_match('/(?!\p{Latin})\p{L}/u', $part)));
				if (!$mixed) {
					$out .= htmlspecialchars($part, ENT_QUOTES | ENT_SUBSTITUTE);
					continue;
				}
				foreach (mb_str_split($part) as $ch) {
					$cp = sprintf('U+%04X', mb_ord($ch));
					if (preg_match('/\p{Cf}/u', $ch)) {
						$out .= '<mark class="homoglyph" title="invisible ' . $cp . '">·</mark>';
					} elseif (preg_match('/(?!\p{Latin})\p{L}/u', $ch)) {
						$out .= '<mark class="homoglyph" title="' . $cp . '">'
							. htmlspecialchars($ch, ENT_QUOTES | ENT_SUBSTITUTE) . '</mark>';
					} else {
						$out .= htmlspecialchars($ch, ENT_QUOTES | ENT_SUBSTITUTE);
					}
				}
			}
			return $out;
		}, ['is_safe' => ['html']]));

		$this->twig->addGlobal('APP_NAME', Config::get('APP_NAME') ?? AppConfig::APP_NAME);
		$this->twig->addGlobal('APP_INFO', Config::get('APP_INFO') ?? AppConfig::APP_INFO);
		$this->twig->addGlobal('APP_LOGO_PATH', Config::get('APP_LOGO_PATH') ?? AppConfig::APP_LOGO_PATH);
		$this->twig->addGlobal('APP_LOGO_ALT', Config::get('APP_LOGO_ALT') ?? AppConfig::APP_LOGO_ALT);
		$this->twig->addGlobal('FOOTER_NAME', Config::get('FOOTER_NAME') ?? AppConfig::APP_NAME);
		$this->twig->addGlobal('FOOTER_URL', Config::get('FOOTER_URL') ?? AppConfig::GITHUB);
		$this->twig->addGlobal('FOOTER_VERSION', Config::get('FOOTER_VERSION') ?? AppConfig::APP_NAME);
		$this->twig->addGlobal('WEB_BASE', $_ENV['WEB_BASE'] ?? '');
		$this->twig->addGlobal('NOTIFICATION_SCORE', Config::get('notification_score') ?? 1000);
		$this->twig->addGlobal('OPENIDC_LABEL', $_ENV['OPENIDC_LABEL'] ?? 'Login with OpenID Connect');
		$this->twig->addGlobal('LOGIN_THROTTLE_ENABLED',
			Helper::env_bool('REDIS_ENABLE', false) &&
			Helper::env_bool('LOGIN_THROTTLE_ENABLE', false)
		);
		$this->twig->addGlobal('FUZZY_LEARN_ENABLED', FuzzyService::isEnabled());

		// a changed style.css gets a new URL, so browsers don't keep the old one
		$this->twig->addGlobal('STYLE_VERSION', (string) (@filemtime(RQWATCH_ROOT . '/web/css/style.css') ?: ''));

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

      $csrfManager = $this->csrfManager();

		// the Twig file that holds all the default markup for rendering forms
		// this file comes with TwigBridge
		$defaultFormTheme = 'form_div_layout.html.twig';

		// the path to TwigBridge library so Twig can locate the
		// form_div_layout.html.twig file
      $appVariableReflection = new ReflectionClass('\Symfony\Bridge\Twig\AppVariable');
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
