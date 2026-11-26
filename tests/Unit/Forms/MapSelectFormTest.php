<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2026 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

/*
 The map picker's redirect: an admin to the map chosen, or to all entries
 (all custom map entries when the picker was for custom maps); a user to
 the user's view of the map, or of all of them. A picker not submitted
 redirects nowhere. Its choices come from the custom maps in the database,
 so it is built by the integration tests; here a stand-in with its two
 fields is submitted. It takes the options the map pages pass.
*/

namespace Tests\Unit\Forms;

use App\Core\Routing\Routes;
use App\Forms\MapSelectForm;
use App\Utils\FormHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\HttpFoundation\HttpFoundationExtension;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Validator\Validation;
use Tests\Unit\UnitTestCase;

// MapSelectForm's fields, without its choices
/** @extends AbstractType<array<string, mixed>> */
final class MapSelectProbe extends AbstractType
{
	#[\Override]
	public function buildForm(FormBuilderInterface $builder, array $options): void {
		$builder->add('map_name', TextType::class)->add('model', HiddenType::class);
	}
}

final class MapSelectFormTest extends UnitTestCase
{
	/** @return array<string, array{string, string, bool, string}> map, model, admin, where to */
	public static function choices(): array {
		return [
			'admin, a map' => ['ip_blacklist', 'MapCombined', true, '/admin/map/ip_blacklist'],
			'admin, all' => ['all', 'MapCombined', true, '/admin/map/all'],
			'admin, all custom' => ['all', 'MapCustom', true, '/admin/map/all/MapCustom'],
			'admin, a custom map' => ['senders', 'MapCustom', true, '/admin/map/senders'],
			'user, a map' => ['mail_from_rcpt_to_whitelist', 'MapCombined', false, '/user/map/mail_from_rcpt_to_whitelist'],
			'user, all' => ['all', 'MapCombined', false, '/user/map/all'],
		];
	}

	#[DataProvider('choices')]
	public function testRedirect(string $map, string $model, bool $admin, string $url): void {
		$form = self::form();
		$form->submit(['map_name' => $map, 'model' => $model]);
		$this->assertSame($url, MapSelectForm::check_form_show($form, self::urls(), $admin)?->getTargetUrl());
	}

	public function testNotSubmitted(): void {
		$this->assertNull(MapSelectForm::check_form_show(self::form(), self::urls(), true));
	}

	public function testOptions(): void {
		$resolver = new OptionsResolver();
		(new MapSelectForm())->configureOptions($resolver);
		$this->assertEqualsCanonicalizing(['form_name', 'role', 'model', 'available_rcpt_to'], $resolver->getDefinedOptions());
	}

	/**
	 * @psalm-return FormInterface<array<string, mixed>>
	 * @phpstan-return FormInterface
	 */
	private static function form(): FormInterface {
		$factory = Forms::createFormFactoryBuilder()
			->addExtension(new HttpFoundationExtension())
			->addExtension(new ValidatorExtension(Validation::createValidator()))
			->getFormFactory();
		return FormHelper::formCreator($factory, Request::create('/'), MapSelectProbe::class);
	}

	private static function urls(): UrlGenerator {
		return new UrlGenerator(Routes::load()['routes'], new RequestContext());
	}
}
