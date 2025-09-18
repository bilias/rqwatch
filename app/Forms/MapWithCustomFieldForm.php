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

		$builder
			->add($field_name, $field['type'], $field['field_options'])
			->add('submit', SubmitType::class, [
				'label' => 'Submit',
			]);
	}

	public static function create(
			FormFactoryInterface $formFactory,
			Request $request,
			$data = null,
			array $options = []): Form {

		// Merge class with any existing class
		$options['attr']['class'] = trim(($options['attr']['class'] ?? '') . ' onefieldform');
		return FormHelper::formCreator($formFactory, $request, static::class, $data, $options);
	}

	public static function check_form(Form $form, UrlGeneratorInterface $urlGenerator): ?RedirectResponse {
		if ($form->isSubmitted() && $form->isValid()) {
			$data = $form->getData();
			$map = $data['map_name'];

			$url = $urlGenerator->generate('admin_map_add_entry', [
				'map' => $map,
			]);

			return new RedirectResponse($url);
		}
		return null;
	}


	public function configureOptions(OptionsResolver $resolver): void {
		$resolver->setDefined(['role']);
		$resolver->setDefined(['map']);
	}
}
