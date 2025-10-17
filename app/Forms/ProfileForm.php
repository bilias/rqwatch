<?php declare(strict_types=1);
/*
 Rqwatch
 Copyright (C) 2025 Giannis Kapetanakis

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU Affero General Public License version 3
as published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU Affero General Public License for more details.

You should have received a copy of the GNU Affero General Public License
along with this program.  If not, see <https://www.gnu.org/licenses/>.
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
			$data = null): Form {

		return FormHelper::formCreator($formFactory, $request, self::class, $data);
	}
}
