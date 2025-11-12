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
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Type;
use Symfony\Component\Validator\Constraints\Regex;

use Symfony\Component\Form\Form;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;

use App\Core\RouteName;
use App\Utils\FormHelper;

class CustomMapConfigForm extends AbstractType
{
	public function buildForm(FormBuilderInterface $formFactory, array $options): void {
        $formFactory
            ->add('map_name', TextType::class, [
                'required' => true,
                'label' => 'Map Name: ',
					 'help' => 'Will be used for url construction',
					 'attr' => [
						'autofocus' => true,
						'class' => 'map_name',
						'title' => 'Enter Map Name',
					 ],
					 'constraints' => [
						new NotBlank(),
						new Assert\Length(
							min: 3,
							max: 128,
						),
						new Assert\Regex(
							pattern: '/^[a-z0-9._\-]+$/',
							message: 'Only lowercase letters, numbers and ._- are allowed',
						),
					 ],
            ])
            ->add('map_description', TextType::class, [
                'required' => true,
                'label' => 'Map Description: ',
					 'attr' => [
						'class' => 'label',
						'title' => 'Enter Map description',
					 ],
					 'constraints' => [
						new NotBlank(),
						new Assert\Length(
							min: 3,
							max: 128,
						),
						new Assert\Regex(
							pattern: '/^[a-zA-Z0-9._\-\(\) ]+$/',
							message: 'Only letters, numbers, space and ._-()  are allowed',
						),
					 ],
            ])
            ->add('field_name', TextType::class, [
                'required' => true,
                'label' => 'Field Name: ',
					 'attr' => [
						'class' => 'field_name',
						'title' => 'Enter Field Name',
					 ],
					 'constraints' => [
						new NotBlank(),
						new Assert\Length(
							min: 2,
							max: 128,
						),
						new Assert\Regex(
							pattern: '/^[a-zA-Z_]+$/',
							message: 'Only letters and _ is allowed',
						),
					 ],
            ])
            ->add('field_label', TextType::class, [
                'required' => true,
                'label' => 'Field Label: ',
					 'attr' => [
						'class' => 'label',
						'title' => 'Enter Field description',
					 ],
					 'constraints' => [
						new NotBlank(),
						new Assert\Length(
							min: 2,
							max: 128,
						),
						new Assert\Regex(
							pattern: '/^[a-zA-Z0-9._\-\(\) ]+$/',
							message: 'Only letters, numbers, space and ._-()  are allowed',
						),
					 ],
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'Submit',
            ]);
	}

	public static function create(
			FormFactoryInterface $formFactory,
			Request $request,
			array $data = null,
			array $options = []): Form {

		// Merge class with any existing class
		$options['attr']['class'] = trim(($options['attr']['class'] ?? '') . ' onefieldform');
		return FormHelper::formCreator($formFactory, $request, self::class, $data, $options);
	}

	public static function check_form(Form $form, UrlGeneratorInterface $urlGenerator): ?RedirectResponse {
		if ($form->isSubmitted() && $form->isValid()) {
			$data = $form->getData();

			$url = $urlGenerator->generate(RouteName::ADMIN_MAPS_CUSTOM_ADD->value);
			return new RedirectResponse($url);
         /*
         $response = new RedirectResponse($url);
         $response->prepare($this->request);
         return $response->send();
         */
      }
		return null;
	}
}
