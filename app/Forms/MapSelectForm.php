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
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Form;

use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Type;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Regex;

use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;

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
			$options = []): Form {

		return FormHelper::formCreator($formFactory, $request, self::class, $data, $options);
	}

	public static function check_form_show(Form $form, UrlGeneratorInterface $urlGenerator, bool $is_admin): ?RedirectResponse {
		if ($form->isSubmitted() && $form->isValid()) {
			$data = $form->getData();
			$map = $data['map_name'];
			$model = $data['model'] ?? null;

			if ($is_admin) {
				if ($map === 'all') {
					// handle MapGeneric and MapCustom
					if (($model === 'MapGeneric') || ($model === 'MapCustom')) {
						$url = $urlGenerator->generate('admin_map_show_all', ['model' => $model]);
					// default to MapCombined
					} else {
						$url = $urlGenerator->generate('admin_map_show_all');
					}
				} else {
					$url = $urlGenerator->generate('admin_map_show', [
						'map' => $map,
					]);
				}
			} else {
				if ($map === 'all') {
					$url = $urlGenerator->generate('map_show_all');
				} else {
					$url = $urlGenerator->generate('map_show', [
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
