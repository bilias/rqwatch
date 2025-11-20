<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

 This Source Code Form is subject to the terms of the Mozilla Public
 License, v. 2.0. If a copy of the MPL was not distributed with this
 file, You can obtain one at http://mozilla.org/MPL/2.0/.
*/

namespace App\Forms;

use Symfony\Component\Form\AbstractType;

use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormBuilderInterface;

use Symfony\Component\OptionsResolver\OptionsResolver;

use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;

use App\Core\RouteName;
use App\Utils\FormHelper;
use App\Inventory\MapInventory;

class MapSelectForm extends AbstractType
{
	public function buildForm(FormBuilderInterface $builder, array $options): void {
		// Dynamically populate based on MapDefinitions
		//$configs = MapInventory::getMapConfigs();
		// filter available maps based on access role
		$configs = MapInventory::getAvailableMapConfigs($options['role']);
		$model_maps = MapInventory::getMapsByModel($options['model'], $configs);

		$choices = [];
		foreach ($configs as $key => $config) {
			// filter maps based on model
			if (in_array($key, $model_maps, true)) {
				$choices[$config['description']] = $key;
			}
		}

		$choices = ['All Map Entries' => 'all'] + $choices;

		if ($options['model'] === 'MapCustom') {
			$choices = ['Manage Custom Maps' => 'manage_custom_maps'] + $choices;
		}

		$builder
			->add('map_name', ChoiceType::class, [
				'label' => 'Maps:',
				'choices' => $choices,
				'placeholder' => '---',
				'required' => true,
				//'help' => 'help text',
			])
			->add('select', SubmitType::class, [
				'label' => 'Select',
			])
			->add('model', HiddenType::class, [
				'data' => $options['model'],
				'mapped' => true, // optional: if not mapped to the data object
			]);
	}

	public static function create(
			FormFactoryInterface $formFactory,
			Request $request,
			$data = null,
			array $options = []): FormInterface {

		return FormHelper::formCreator($formFactory, $request, self::class, $data, $options);
	}

	public static function check_form_show(FormInterface $form, UrlGeneratorInterface $urlGenerator, bool $is_admin): ?RedirectResponse {
		if ($form->isSubmitted() && $form->isValid()) {
			$data = $form->getData();
			$map = $data['map_name'];
			$model = $data['model'] ?? null;

			if ($is_admin) {
				if ($map === 'all') {
					// handle MapGeneric and MapCustom
					if (($model === 'MapGeneric') || ($model === 'MapCustom')) {
						$url = $urlGenerator->generate(RouteName::ADMIN_MAP_SHOW_ALL->value, ['model' => $model]);
					// default to MapCombined
					} else {
						$url = $urlGenerator->generate(RouteName::ADMIN_MAP_SHOW_ALL->value);
					}
				} else {
					$url = $urlGenerator->generate(RouteName::ADMIN_MAP_SHOW->value, ['map' => $map]);
				}
			} else {
				if ($map === 'all') {
					$url = $urlGenerator->generate(RouteName::MAP_SHOW_ALL->value);
				} else {
					$url = $urlGenerator->generate(RouteName::MAP_SHOW->value, [
						'map' => $map,
					]);
				}

			}

			return new RedirectResponse($url);
		}
		return null;
	}

	public function configureOptions(OptionsResolver $resolver): void {
		/*
		$resolver->setDefaults([
			$resolver->setDefault('foo', 'default_value');
		]);
		*/

		// Define allowed custom options
		$resolver->setDefined(['form_name']);
		$resolver->setDefined(['role']);
		$resolver->setDefined(['model']);
		$resolver->setDefined(['available_rcpt_to']);
	}

}
