<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 A ViewController has the controller's loggers and the page settings:
 refresh_rate, items_per_page and max_items as configured, or 0, 50 and
 10000. Its Twig views. twigView(): built once, uncached on a
 command line, not in debug, reloading changed templates, with rqwatch's
 functions. Its globals, set again on every call, are the configured
 names, links and settings, or their defaults, the login throttle shown
 only when Redis and the throttle are both on, and the CSS and script
 versions their files' times. twigFormView(): the view, built first when
 needed, renders forms with TwigBridge's div layout, a CSRF token field,
 and each field's errors; the trans filter has the form and validator
 messages in English. The form factory is set. A view whose loader reads
 no files cannot take the form templates. Each in a process of its own, as
 a command line: the view then has no cache directory (without CLI_MODE it
 would make the web's in the checkout), and the form factory is built
 anew.
*/

namespace Tests\Unit\Controllers;

use App\Configuration\AppConfig;
use App\Configuration\Config;
use App\Controllers\ViewController;
use App\Twig\RqwatchExtension;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use ReflectionProperty;
use RuntimeException;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Validator\Constraints\NotBlank;
use Tests\Unit\UnitTestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class ViewControllerTest extends UnitTestCase
{
	private static function view(): ViewController {
		define('CLI_MODE', true);
		$controller = new ViewController();
		$request = new Request();
		$request->setSession(new Session(new MockArraySessionStorage()));
		$controller->setRequest($request);
		return $controller;
	}

	private static function request(ViewController $controller): Request {
		$request = (new ReflectionProperty(ViewController::class, 'request'))->getValue($controller);
		self::assertInstanceOf(Request::class, $request);
		return $request;
	}

	/** @return list<mixed> refresh_rate, items_per_page, max_items */
	private static function settings(ViewController $controller): array {
		return array_map(fn (string $setting): mixed => (new ReflectionProperty(ViewController::class, $setting))->getValue($controller), ['refresh_rate', 'items_per_page', 'max_items']);
	}

	public function testSettings(): void {
		$defaults = new ViewController();
		$this->assertSame([$this->log, [0, 50, 10000]], [$defaults->getFileLogger(), self::settings($defaults)]);
		Config::set('refresh_rate', 30);
		Config::set('items_per_page', 25);
		Config::set('max_items', 500);
		$this->assertSame([30, 25, 500], self::settings(new ViewController()));
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testView(): void {
		Config::set('APP_NAME', 'Quarantine');
		Config::set('APP_INFO', 'Held mail');
		Config::set('APP_LOGO_PATH', '/images/ia.png');
		Config::set('APP_LOGO_ALT', 'IA logo');
		Config::set('FOOTER_NAME', 'IA-FORTH');
		Config::set('FOOTER_URL', 'https://www.ia.forth.gr/');
		Config::set('FOOTER_VERSION', 'v9');
		Config::set('notification_score', 12.5);
		$this->setEnv('WEB_BASE', '/rqwatch');
		$this->setEnv('OPENIDC_LABEL', 'Login with SSO');
		$this->setEnv('REDIS_ENABLE', 'true');
		$this->setEnv('LOGIN_THROTTLE_ENABLE', 'true');
		$controller = self::view();
		$twig = $controller->twigView();
		$this->assertSame($twig, $controller->twigView());
		$this->assertSame([false, false, true, true], [$twig->getCache(), $twig->isDebug(), $twig->isAutoReload(), $twig->hasExtension(RqwatchExtension::class)]);
		$this->assertSame([
			'APP_NAME' => 'Quarantine',
			'APP_INFO' => 'Held mail',
			'APP_LOGO_PATH' => '/images/ia.png',
			'APP_LOGO_ALT' => 'IA logo',
			'FOOTER_NAME' => 'IA-FORTH',
			'FOOTER_URL' => 'https://www.ia.forth.gr/',
			'FOOTER_VERSION' => 'v9',
			'WEB_BASE' => '/rqwatch',
			'NOTIFICATION_SCORE' => 12.5,
			'OPENIDC_LABEL' => 'Login with SSO',
			'LOGIN_THROTTLE_ENABLED' => true,
			'FUZZY_LEARN_ENABLED' => false,
			'STYLE_VERSION' => (string) filemtime(AppConfig::STYLE_CSS_PATH),
			'SCRIPT_VERSION' => (string) filemtime(AppConfig::SCRIPT_JS_PATH),
		], $twig->getGlobals());
		$this->assertSame('<a href="https://www.ia.forth.gr/">IA-FORTH</a>', $twig->createTemplate('<a href="{{ FOOTER_URL }}">{{ FOOTER_NAME }}</a>')->render());
	}

	/** @var array<string, array{?string, ?string}> REDIS_ENABLE, LOGIN_THROTTLE_ENABLE */
	private const array THROTTLE_OFF = [
		'without Redis' => [null, 'true'],
		'without the throttle' => ['true', null],
		'neither' => [null, null],
	];

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testDefaults(): void {
		$controller = self::view();
		foreach (self::THROTTLE_OFF as $case => [$redis, $throttle]) {
			$this->setEnv('REDIS_ENABLE', $redis);
			$this->setEnv('LOGIN_THROTTLE_ENABLE', $throttle);
			$this->assertFalse($controller->twigView()->getGlobals()['LOGIN_THROTTLE_ENABLED'] ?? null, $case);
		}
		$globals = $controller->twigView()->getGlobals();
		$this->assertSame([
			'APP_NAME' => AppConfig::APP_NAME,
			'APP_INFO' => AppConfig::APP_INFO,
			'APP_LOGO_PATH' => AppConfig::APP_LOGO_PATH,
			'APP_LOGO_ALT' => AppConfig::APP_LOGO_ALT,
			'FOOTER_NAME' => AppConfig::APP_NAME,
			'FOOTER_URL' => AppConfig::GITHUB,
			'FOOTER_VERSION' => AppConfig::APP_NAME,
			'WEB_BASE' => '',
			'NOTIFICATION_SCORE' => 1000,
			'OPENIDC_LABEL' => 'Login with OpenID Connect',
		], array_intersect_key($globals, array_flip(['APP_NAME', 'APP_INFO', 'APP_LOGO_PATH', 'APP_LOGO_ALT', 'FOOTER_NAME', 'FOOTER_URL', 'FOOTER_VERSION', 'WEB_BASE', 'NOTIFICATION_SCORE', 'OPENIDC_LABEL'])));
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testForm(): void {
		$controller = self::view();
		$twig = $controller->twigFormView(self::request($controller));
		$this->assertSame($twig, $controller->twigView());

		$factory = (new ReflectionProperty(ViewController::class, 'formFactory'))->getValue($controller);
		$this->assertInstanceOf(FormFactoryInterface::class, $factory);
		$form = $factory->createBuilder()->add('subject', TextType::class, ['constraints' => [new NotBlank()]])->getForm();
		$form->submit(['subject' => '']);
		$html = $twig->createTemplate('{{ form(form) }}')->render(['form' => $form->createView()]);
		$this->assertStringContainsString('<form name="form" method="post">', $html);
		$this->assertStringContainsString('name="form[subject]"', $html);
		$this->assertMatchesRegularExpression('/<input type="hidden" id="form__token" name="form\[_token\]" value="[^"]+"/', $html);
		$this->assertStringContainsString('This value should not be blank.', $html);
		$this->assertStringContainsString('The CSRF token is invalid. Please try to resubmit the form.', $html);

		$translator = $twig->getExtension(TranslationExtension::class)->getTranslator();
		$this->assertInstanceOf(Translator::class, $translator);
		$this->assertSame('en', $translator->getLocale());
		$messages = $translator->getCatalogue('en');
		$this->assertTrue($messages->defines('This value should not be blank.', 'validators'), 'validator messages');
		$this->assertTrue($messages->defines('The CSRF token is invalid. Please try to resubmit the form.', 'validators'), 'form messages');
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testLoaderReadsNoFiles(): void {
		$controller = self::view();
		(new ReflectionProperty(ViewController::class, 'twig'))->setValue($controller, new Environment(new ArrayLoader()));
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Twig loader is not a FilesystemLoader');
		$controller->twigFormView(self::request($controller));
	}
}
