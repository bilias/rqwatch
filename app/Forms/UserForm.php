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

use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Type;
use Symfony\Component\Validator\Constraints\Regex;

use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;

use App\Core\RouteName;
use App\Utils\FormHelper;
use App\Models\User;

class UserForm extends AbstractType
{
	public function buildForm(FormBuilderInterface $formFactory, array $options): void {
        $formFactory
            ->add('username', TextType::class, [
                'required' => true,
                'label' => 'Username: ',
					 'attr' => [
						'class' => 'username',
						'title' => 'Enter user username',
						'autocomplete' => 'new-username',
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
            ->add('email', EmailType::class, [
                'required' => true,
                'label' => 'E-mail: ',
					 'attr' => [
						'class' => 'email',
						'title' => 'Enter user e-mail',
					 ],
					 'constraints' => [
						new NotBlank(),
						new Assert\Length(
							min: 6,
							max: 128,
						),
					 ],
            ])
            ->add('password', RepeatedType::class, [
					 'type' => PasswordType::class,
					 'invalid_message' => 'The password fields must match.',
					 'mapped' => false,
					 'required' => !$options['is_edit'], // Only required in "add" mode
					 'options' => [
						 'attr' => [
							'class' => 'password',
							'title' => 'Enter user password',
							'autocomplete' => 'new-password',
						 ],
						/*
						 'constraints' => $options['is_edit'] ? [] : [
							new NotBlank(),
							new Assert\Length(
								min: 8,
								max: 128,
							),
						 ],
						*/
						 'constraints' => [
							new Assert\When(fn($value) => !empty($value), [ 
								new Assert\Length(min: 8, max: 128), 
							],
							),
						 ],
					 ],
					 'first_options'  => [
						'label' => 'Password:',
					 ],
					 'second_options'  => [
						'label' => 'Repeat Password:',
					 ],
            ])
            ->add('firstname', TextType::class, [
                'required' => false,
                'label' => 'First Name: ',
					 'attr' => [
						'class' => 'firstname',
						'title' => 'Enter user firstname',
					 ],
					 'constraints' => [
						new Assert\Length(
							max: 64,
						),
					 ],
            ])
            ->add('lastname', TextType::class, [
                'required' => false,
                'label' => 'Last Name: ',
					 'attr' => [
						'class' => 'lastname',
						'title' => 'Enter user lastname',
					 ],
					 'constraints' => [
						new Assert\Length(
							max: 64,
						),
					 ],
            ])
				->add('disable_notifications', CheckboxType::class, [
					'label' => 'Disable notifications',
					'required' => false,
					 'attr' => [
						'class' => 'disable_notifications',
						'title' => 'Disable mail notifications for quarantined mails',
					 ],
				])
				->add('is_admin', CheckboxType::class, [
					'label' => 'Admin',
					'required' => false,
					 'attr' => [
						'class' => 'is_admin',
						'title' => 'Check if user is Admin',
					 ],
				])
            ->add('add', SubmitType::class, [
                'label' => $options['is_edit'] ? 'Update User' : 'Add User',
            ]);
	}

	public static function create(
			FormFactoryInterface $formFactory,
			Request $request,
			array $data = null,
			array $options = []
	): FormInterface {

		return FormHelper::formCreator($formFactory, $request, self::class, $data, $options);
	}

	public static function check_form(FormInterface $form, UrlGeneratorInterface $urlGenerator): ?RedirectResponse {
		if ($form->isSubmitted() && $form->isValid()) {
			$data = $form->getData();
			$username = $data['username'];

			$url = $urlGenerator->generate(RouteName::DETAIL->value, [
				'type' => 'username',
				'value' => $username,
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

	public function configureOptions(OptionsResolver $resolver) {
        $resolver->setDefaults([
            //'data_class' => User::class,
            //'data_class' => null,
            'is_edit' => false,  // default to "create" mode
        ]);

        $resolver->setAllowedTypes('is_edit', 'bool');
    }
}
