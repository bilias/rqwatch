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
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Type;

use Symfony\Component\Form\Form;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;

use App\Utils\FormHelper;
use App\Models\User;

class ProfileForm extends AbstractType
{
	public function buildForm(FormBuilderInterface $formFactory, array $options): void {
        $formFactory
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
            ->add('password', RepeatedType::class, [
					 'type' => PasswordType::class,
					 'invalid_message' => 'The password fields must match.',
					 'mapped' => false,
					 'required' => false,
					 'options' => [
						 'attr' => [
							'class' => 'password',
							'title' => 'Enter user password',
							'autocomplete' => 'new-password',
						 ],
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
            ->add('add', SubmitType::class, [
                'label' => 'Update',
            ]);
	}

	public static function create(
			FormFactoryInterface $formFactory,
			Request $request,
			array $data = null): Form {

		return FormHelper::formCreator($formFactory, $request, self::class, $data);
	}
}
