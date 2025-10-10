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
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
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

use App\Utils\FormHelper;
use App\Models\User;

class LoginForm extends AbstractType
{
	public function buildForm(FormBuilderInterface $formFactory, array $options): void {
        $formFactory
            ->add('username', TextType::class, [
                'required' => true,
                'label' => 'E-mail: ',
					 'attr' => [
						'autofocus' => true,
						'class' => 'username',
						'title' => 'Enter your username',
					 ],
					 'constraints' => [
						new NotBlank(),
						new Assert\Length(
							min: 2,
							max: 128,
						),
						new Assert\Regex(
							pattern: '/^[a-zA-Z0-9._+-]+(@[a-zA-Z0-9-]+(\.[a-zA-Z0-9-]+)+)?$/',
							message: 'Wrong characters.',
						),
					 ],
            ])
            ->add('password', PasswordType::class, [
                'required' => true,
					 'mapped' => false,
                'label' => 'Password: ',
					 'attr' => [
						'class' => 'password',
						'title' => 'Enter your password',
					 ],
					 'constraints' => [
						new NotBlank(),
						new Assert\Length(
							min: 1,
							max: 128,
						),
					 ],
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'Login',
            ]);
	}

	public static function create(
			FormFactoryInterface $formFactory,
			Request $request): Form {

		return FormHelper::formCreator($formFactory, $request, self::class);
	}

	public static function check_form(Form $form, UrlGeneratorInterface $urlGenerator): ?RedirectResponse {
		if ($form->isSubmitted() && $form->isValid()) {
			$data = $form->getData();
			$username = $data['username'];
			$password = $data['password'];

			$url = $urlGenerator->generate('login', [
				'username' => $username,
				'password' => $password,
			]);
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
