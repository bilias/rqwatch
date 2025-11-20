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

use App\Core\RouteName;
use App\Utils\FormHelper;
use App\Inventory\MapInventory;

class MapWithTwoFieldsForm extends AbstractType
{
	protected static string $firstFieldName = '';
	protected static string $secondFieldName = '';

	public function buildForm(FormBuilderInterface $builder, array $options): void {
		if (static::$firstFieldName === '' || static::$secondFieldName === '') {
			throw new \RuntimeException("Field names must be defined in child class: " . static::class);
		}

		$firstField = MapInventory::getFieldDefinitions(static::$firstFieldName);
		$secondField = MapInventory::getFieldDefinitions(static::$secondFieldName);

		$builder
			->add(static::$firstFieldName, $firstField['type'], $firstField['field_options'])
			->add(static::$secondFieldName, $secondField['type'], $secondField['field_options'])
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
		$options['attr']['class'] = trim(($options['attr']['class'] ?? '') . ' twofieldsform');
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
		/*
		$resolver->setDefaults([
			$resolver->setDefault('foo', 'default_value');
		]);
		*/

		// Define allowed custom options
		$resolver->setDefined(['role']);
	}

}
