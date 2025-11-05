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
use Symfony\Component\Validator\Constraints\Regex;

use Symfony\Component\Form\Form;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;

use App\Utils\FormHelper;
use App\Models\MailAlias;

class MailAliasForm extends AbstractType
{
	public function buildForm(FormBuilderInterface $formFactory, array $options): void {
        $formFactory
            ->add('username', EmailType::class, [
                'required' => true,
                'label' => 'Username: ',
					 'attr' => [
						'class' => 'username',
						'title' => "Enter user's username",
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
            ->add('alias', EmailType::class, [
                'required' => true,
                'label' => 'E-mail Alias: ',
					 'attr' => [
						'class' => 'email',
						'title' => 'Enter user e-mail alias',
					 ],
					 'constraints' => [
						new NotBlank(),
						new Assert\Length(
							min: 6,
							max: 128,
						),
					 ],
            ])
            ->add('add', SubmitType::class, [
                'label' => 'Add',
            ]);
	}

	public static function create(
			FormFactoryInterface $formFactory,
			Request $request,
			array $data = null): Form {

		return FormHelper::formCreator($formFactory, $request, self::class, $data);
	}

}
