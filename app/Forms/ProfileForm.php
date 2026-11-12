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
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;

use Symfony\Component\Validator\Constraints as Assert;

use Symfony\Component\OptionsResolver\OptionsResolver;

use Symfony\Component\HttpFoundation\Request;

use App\Utils\FormHelper;

/** @extends AbstractType<array<string, mixed>> */
final class ProfileForm extends AbstractType
{
	#[\Override]
	public function buildForm(FormBuilderInterface $builder, array $options): void {
		$builder
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
			->add('current_password', PasswordType::class, [
				'label' => 'Current Password:',
				'mapped' => false,
				'required' => false,
				'attr' => [
					'class' => 'password',
					'title' => 'Required to set a new password',
					'autocomplete' => 'current-password',
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
						// an empty field is submitted as null, which Length skips
						new Assert\Length(min: 8, max: 128),
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

			// a bool, as configureOptions() requires
			FormHelper::addNotificationsField($builder, $options['notifications_default'] === true);
	}

	/**
	 * @param array<string, mixed>|null $data
	 * @param array<string, mixed> $options
	 * @psalm-return FormInterface<array<string, mixed>>
	 * @phpstan-return FormInterface
	 */
	public static function create(
			FormFactoryInterface $formFactory,
			Request $request,
			?array $data = null,
			array $options = []): FormInterface {

		return FormHelper::formCreator($formFactory, $request, self::class, $data, $options);
	}

	#[\Override]
	public function configureOptions(OptionsResolver $resolver): void {
		$resolver->setDefaults([
			'notifications_default' => true,
		]);

		$resolver->setAllowedTypes('notifications_default', 'bool');
	}

}
