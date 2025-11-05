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
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Type;

use Symfony\Component\Form\Form;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;

use App\Utils\FormHelper;

class SearchForm extends AbstractType
{
	public function buildForm(FormBuilderInterface $formFactory, array $options): void {
        $formFactory
            ->add('filter', ChoiceType::class, [
					'required' => true,
					'label' => 'Filter: ',
					'choices' => FormHelper::getSearchFilters(),
					'constraints' => [
						new NotBlank(),
					],
				])
            ->add('choice', ChoiceType::class, [
					'required' => true,
					'label' => 'Choice: ',
					'choices' => FormHelper::getSearchChoices(),
					'constraints' => [
						new NotBlank(),
					],
				])
				->add('value', TextType::class, [
					'required' => false,
					// comment out to support NULLs
					'constraints' => [
						new NotBlank(),
						new Assert\Length(
                     min: 1,
                     max: 64,
                  ),
					],
				])
            ->add('search', SubmitType::class, [
                'label' => 'Add',
            ]);
	}

	public static function create(
			FormFactoryInterface $formFactory,
			Request $request,
			array $data = null): Form {

		return FormHelper::formCreator($formFactory, $request, self::class, $data);
	}

	public static function check_form(Form $form, UrlGeneratorInterface $urlGenerator): ?RedirectResponse {
		if ($form->isSubmitted() && $form->isValid()) {

			$url = $urlGenerator->generate('search');

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
