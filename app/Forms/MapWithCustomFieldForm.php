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

use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;

use App\Core\RouteName;
use App\Utils\FormHelper;
use App\Inventory\MapInventory;
use App\Services\MapService;

class MapWithCustomFieldForm extends AbstractType
{
	protected static string $fieldName = 'custom';

	public function buildForm(FormBuilderInterface $builder, array $options): void {

		$field = MapInventory::getFieldDefinitions(static::$fieldName);

		// Override defaults with field options from custom_map_config
		$field_db = MapService::getCustomField($options['map']);
		$field_name = $field_db['field_name'];
		$field_label = $field_db['field_label'];
		$field['description'] = $field_label;
		$field['field_options']['label'] = "{$field_label}:";

		if (!empty($options['is_edit'])) {
			// Fix mismatches between DB key and expected field name
			// If 'domain' is expected but data uses 'pattern', remap it.
			$data = $builder->getData();

			// put # in front if it is disabled
			if (!empty($data['disabled'])) {
				$data['pattern'] = "#{$data['pattern']}";
			}

			if (!array_key_exists($field_name, $data) && array_key_exists('pattern', $data)) {
				$data[$field_name] = $data['pattern'];
			}

			// don't do multi-entry on edit
			// change TextareaType -> TextType
			if ($field['type'] === TextareaType::class) {
				$field['type'] = TextType::class;
				unset($field['field_options']['attr']['rows']);
				unset($field['field_options']['attr']['style']);
				$field['field_options']['help'] = 'Lines starting with # are inserted as disabled entries';
			}
			// set the fixed data back on the builder
			$builder->setData($data);
		}

		$builder
			->add($field_name, $field['type'], $field['field_options'])
			->add('submit', SubmitType::class, [
				'label' => 'Submit',
			]);
	}

	public static function create(
			FormFactoryInterface $formFactory,
			Request $request,
			array $data = null,
			array $options = []): FormInterface {

		// Merge class with any existing class
		$options['attr']['class'] = trim(($options['attr']['class'] ?? '') . ' onefieldform');
		return FormHelper::formCreator($formFactory, $request, static::class, $data, $options);
	}

	public static function check_form(FormInterface $form, UrlGeneratorInterface $urlGenerator): ?RedirectResponse {
		if ($form->isSubmitted() && $form->isValid()) {
			$data = $form->getData();
			$map = $data['map_name'];

			$url = $urlGenerator->generate(RouteName::ADMIN_MAP_ADD_ENTRY->value, [
				'map' => $map,
			]);

			return new RedirectResponse($url);
		}
		return null;
	}


	public function configureOptions(OptionsResolver $resolver): void {
		$resolver->setDefined(['role']);
		$resolver->setDefined(['map']);
		$resolver->setDefined(['is_edit']);
	}
}
