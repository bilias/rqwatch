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
use Symfony\Component\Form\Extension\Core\Type\HiddenType;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Type;
use Symfony\Component\Validator\Constraints\Regex;

use Symfony\Component\Form\Form;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;

use App\Utils\FormHelper;

class UserSearchForm extends AbstractType
{
	public function buildForm(FormBuilderInterface $formFactory, array $options): void {
        $formFactory
            ->add('user', TextType::class, [
                'required' => true,
                'label' => 'User search: ',
					 'attr' => [
						'class' => 'field',
						'title' => 'Search user',
					 ],
					 'constraints' => [
						new NotBlank(),
						new Assert\Length(
							min: 2,
							max: 128,
						),
						new Assert\Regex(
							pattern: '/^[a-zA-Z0-9._+-]+(@[a-zA-Z0-9-]+(\.[a-zA-Z0-9-]+)+)?$/',
							message: 'The value can only contain letters, numbers and ._+-@',
						),
					 ],
            ])
            ->add('search', SubmitType::class, [
                'label' => 'Search',
            ]);
	}

	public static function create(
			FormFactoryInterface $formFactory,
			Request $request,
			UrlGeneratorInterface $urlGenerator,
			array $data = null
	): Form {

		$url = $urlGenerator->generate('admin_usersearch');

		return FormHelper::formCreator(
			$formFactory,
			$request,
			self::class,
			$data,
			[
				'action' => $url,
				'method' => 'POST',
			]
		);
	}

	public static function check_form(
		Form $form,
		UrlGeneratorInterface $urlGenerator
	): ?RedirectResponse {

		if ($form->isSubmitted() && $form->isValid()) {
			$data = $form->getData();
			dd($data);

			$url = $urlGenerator->generate('admin_usersearch');
			return new RedirectResponse($url);
      }

		return null;
	}
}
